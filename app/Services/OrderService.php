<?php

namespace App\Services;

use App\Events\OrderPlaced;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly ReferralService $referrals
    ) {}

    public function userOrders(User $user)
    {
        return $user->orders()
            ->with(['items.product.images', 'coupon', 'user', 'info'])
            ->latest()
            ->paginate(15);
    }

    public function createOrder(?User $user, array $payload): Order
    {
        $order = DB::transaction(function () use ($user, $payload) {
            $shipping = $payload['shipping_address'] ?? [];

            if ($user) {
                // Keep the saved profile in sync for authenticated customers.
                $user->update([
                    'first_name' => $shipping['firstName'] ?? $user->first_name,
                    'last_name' => $shipping['lastName'] ?? $user->last_name,
                    'phone' => $shipping['phone'] ?? $user->phone,
                    'address' => $shipping['address'] ?? $user->address,
                    'city' => $shipping['city'] ?? $user->city,
                    'postcode' => $shipping['zipCode'] ?? $shipping['zip'] ?? $user->postcode,
                ]);
            }

            // Check the code before this order exists, or a signed-in client's
            // new order would count as "ordered before" for first-order codes.
            $coupon = empty($payload['coupon_code']) ? null : $this->validCoupon($payload['coupon_code'], true);
            if ($coupon) {
                $this->referrals->assertUsable($coupon, $user, $shipping['email'] ?? $user?->email, $shipping['phone'] ?? $user?->phone);
            }

            $order = Order::create([
                'user_id' => $user?->id,
                'total' => 0,
                'discount' => 0,
                'shipping' => 0,
                'message' => $payload['custom_message'] ?? null,
                'payment_method' => $payload['payment_method'] ?? 'cod',
                'payment_status' => 'pending',
                'coupon_code' => null,
            ]);

            $lines = [];
            $requested = [];
            foreach ($payload['items'] as $item) {
                // Lock the product row to prevent overselling under concurrent requests
                $product = Product::lockForUpdate()->with('category')->findOrFail($item['product_id']);
                // The admin may have hidden it from this buyer (or it is in review / unpriced for them).
                if (!$product->loadMissing(['images', 'brand'])->isAvailableTo($user)) {
                    throw ValidationException::withMessages([
                        'items' => [__(':name is no longer available.', ['name' => $product->name])]
                    ]);
                }
                $qty = (int) $item['qty'];
                // Stock is taken after pricing, so count repeated lines of one product together.
                $requested[$product->id] = ($requested[$product->id] ?? 0) + $qty;

                if (!$product->inStock($requested[$product->id])) {
                    throw ValidationException::withMessages([
                        'items' => [__('Only :count left of :name.', ['count' => max(0, (int) $product->quantity), 'name' => $product->name])]
                    ]);
                }

                $lines[] = ['product' => $product, 'qty' => $qty];
            }

            // Prices, offers and the coupon are recomputed on the server, so a client
            // cannot claim a deal it does not qualify for.
            $quote = $this->pricing->quote($user, $lines, $payload['bundle_ids'] ?? [], $coupon);

            foreach ($lines as $index => ['product' => $product, 'qty' => $qty]) {
                $order->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $qty,
                    'price' => $quote['lines'][$index]['unitPrice'],
                ]);

                $product->decrement('quantity', $qty);
            }

            $couponId = null;
            $appliedCouponCode = null;

            // A coupon the profit floor cut to nothing is not used up.
            if ($coupon?->isReferral()) {
                // Unlimited uses; the credit is what the profit floor leaves room for.
                $couponId = $coupon->id;
                $appliedCouponCode = $coupon->code;
                $order->referral_stylist_id = $coupon->stylist_id;
                $order->referral_commission = $quote['referralCommission'];
            } elseif ($coupon && $quote['couponDiscount'] > 0) {
                $coupon->decrement('quantity');
                $couponId = $coupon->id;
                $appliedCouponCode = $coupon->code;

                // Record coupon usage for authenticated customers only.
                if ($user) {
                    $user->coupons()->syncWithoutDetaching([
                        $coupon->id => ['used_at' => now()],
                    ]);
                }
            }

            $discount = $quote['discount'];
            $subtotal = $quote['subtotal'];
            $shippingCost = $quote['shipping'];

            $order->total = $subtotal - $discount + $shippingCost;

            // Store credit pays part of the order; the user row lock keeps two
            // checkouts from spending the same balance.
            if ($user && !empty($payload['use_store_credit'])) {
                User::query()->whereKey($user->id)->lockForUpdate()->first();
                // A negative balance (credit spent, then the client order was cancelled) is a
                // debt future commission pays off; it never adds to what the stylist pays.
                $order->store_credit_used = max(0, min($this->referrals->balance($user), (float) $order->total));
                $order->total -= $order->store_credit_used;
            }

            $order->discount = $discount;
            $order->shipping = $shippingCost;
            $order->coupon_id = $couponId;
            $order->coupon_code = $appliedCouponCode;
            $order->status = Order::STATUS_PENDING;
            $order->save();
            $this->referrals->syncOrder($order);

            // Snapshot shipping details into order_infos table
            $order->info()->create([
                'first_name'  => $shipping['firstName'] ?? $user?->first_name,
                'last_name'   => $shipping['lastName']  ?? $user?->last_name,
                'email'       => $shipping['email']     ?? $user?->email,
                'phone'       => $shipping['phone']     ?? $user?->phone,
                'address'     => $shipping['address']   ?? $user?->address,
                'city'        => $shipping['city']      ?? $user?->city,
                'postal_code' => $shipping['zipCode']   ?? $shipping['zip'] ?? $user?->postcode,
                'country'     => $shipping['country']   ?? 'MK',
            ]);

            return $order->load(['items.product.images', 'coupon', 'user', 'info']);
        });

        // Dispatch after transaction commits so listeners never act on rolled-back data
        try {
            OrderPlaced::dispatch($order);
        } catch (\Throwable $e) {
            Log::error('Failed to dispatch OrderPlaced event', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $order;
    }

    public function updateStatus(Order $order, int $status): Order
    {
        $order->update(['status' => $status]);
        $this->referrals->syncOrder($order);
        return $order;
    }

    public function cancelOrder(Order $order): Order
    {
        // Idempotency: already cancelled → no-op
        if ($order->status === Order::STATUS_CANCELLED) {
            return $order->load(['items.product.images', 'coupon', 'user', 'info']);
        }

        if ($order->status !== Order::STATUS_PENDING) {
            throw ValidationException::withMessages([
                'order' => [__('Only pending orders can be cancelled.')]
            ]);
        }

        return DB::transaction(function () use ($order) {
            // Re-read under a row lock: two quick cancel requests must not both put the stock back.
            $order = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($order->status === Order::STATUS_CANCELLED) {
                return $order->load(['items.product.images', 'coupon', 'user', 'info']);
            }

            $order->status = Order::STATUS_CANCELLED;
            $order->save();
            $this->referrals->syncOrder($order);

            $this->moveStock($order, +1);

            return $order->load(['items.product.images', 'coupon', 'user', 'info']);
        });
    }

    /**
     * Put an order's items back on the shelf (+1, order cancelled) or take them again (-1, cancel undone).
     * Call inside a transaction.
     */
    public function moveStock(Order $order, int $direction): void
    {
        $order->load('items.product');

        foreach ($order->items as $item) {
            if ($item->product) {
                $item->product->increment('quantity', $direction * $item->quantity);
            }
        }
    }

    public function validCoupon(string $code, bool $lock = false): Coupon
    {
        $query = Coupon::query()->where('code', $code);
        $coupon = ($lock ? $query->lockForUpdate() : $query)->first();

        if (!$coupon) {
            throw ValidationException::withMessages([
                'coupon_code' => [__('Coupon not found.')],
            ]);
        }

        if (!$coupon->isValid()) {
            throw ValidationException::withMessages([
                'coupon_code' => [__('Coupon is expired or has been fully used.')],
            ]);
        }

        return $coupon;
    }
}
