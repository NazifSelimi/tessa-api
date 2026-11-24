<?php

namespace App\Services;

use App\Events\OrderPlaced;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\PublicStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly ReferralService $referrals,
        private readonly CouponUsage $couponUsage,
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

            // Checkout never changes the customer's profile: the delivery details
            // are saved on the order only (owner rule Q5, #13). Ordering for someone
            // else must not change your own name, phone or address.

            // Check the code before this order exists, or a signed-in client's
            // new order would count as "ordered before" for first-order codes.
            $coupon = empty($payload['coupon_code']) ? null : $this->validCoupon($payload['coupon_code'], true);
            if ($coupon) {
                $this->referrals->assertUsable($coupon, $user, $shipping['email'] ?? $user?->email, $shipping['phone'] ?? $user?->phone);
                // A normal coupon once per customer (owner rule Q7).
                $this->couponUsage->assertNotUsedBefore($coupon, $user, $shipping['email'] ?? $user?->email, $shipping['phone'] ?? $user?->phone);
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
                    // Exact stock is for admins; everyone else sees at most PublicStock::CAP (#20).
                    $left = max(0, (int) $product->quantity);
                    if (! $user?->isAdmin()) {
                        $left = min($left, PublicStock::CAP);
                    }
                    throw ValidationException::withMessages([
                        'items' => [__('Only :count left of :name.', ['count' => $left, 'name' => $product->name])]
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
            // Only while the referral feature is on (#22): with it off, credit is never spent.
            if ($user && !empty($payload['use_store_credit']) && app(FeatureService::class)->enabled('referrals')) {
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
            // The admin may have shipped it between the first check and the lock (#9).
            if ($order->status !== Order::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'order' => [__('Only pending orders can be cancelled.')]
                ]);
            }

            $order->status = Order::STATUS_CANCELLED;
            $order->save();
            $this->referrals->syncOrder($order);

            $this->moveStock($order, +1);
            // Its coupon use comes back (owner rule Q7).
            $this->couponUsage->release($order);

            return $order->load(['items.product.images', 'coupon', 'user', 'info']);
        });
    }

    /**
     * Take an order's items from the shelf again (a cancel undone, or an order
     * restored from the Trash). Call inside a transaction.
     *
     * Refuses with 422 (errors.stock) when there is not enough, unless $force:
     * then stock may go below 0, and who did it and when is written to the log
     * (owner rule Q12).
     */
    public function takeStock(Order $order, bool $force = false, ?User $by = null): void
    {
        $order->load('items');
        $needed = [];
        foreach ($order->items as $item) {
            if ($item->product_id) {
                $needed[$item->product_id] = ($needed[$item->product_id] ?? 0) + (int) $item->quantity;
            }
        }
        if (! $needed) {
            return;
        }

        // Lock the rows (in id order, so two requests never wait on each other) before checking.
        $products = Product::query()->whereKey(array_keys($needed))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $short = [];
        foreach ($needed as $id => $qty) {
            $product = $products->get($id);
            if ($product && (int) $product->quantity < $qty) {
                $short[] = __('Only :have left of :name, need :need.', [
                    'have' => max(0, (int) $product->quantity), 'name' => $product->name, 'need' => $qty,
                ]);
            }
        }

        if ($short && ! $force) {
            throw ValidationException::withMessages(['stock' => $short]);
        }
        if ($short) {
            Log::warning('Stock taken below zero by an admin (Proceed anyway)', [
                'order_id' => $order->id,
                'admin_id' => $by?->id,
                'admin_email' => $by?->email,
                'at' => now()->toIso8601String(),
                'short' => $short,
            ]);
        }

        foreach ($needed as $id => $qty) {
            $products->get($id)?->decrement('quantity', $qty);
        }
    }

    /**
     * Put an order's items back on the shelf (+1, order cancelled) or take them again (-1).
     * Taking them again should go through takeStock(), which checks there is enough.
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
