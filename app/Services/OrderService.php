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
            $isStylist = $user?->isStylist() ?? false;

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

            $subtotal = 0;

            foreach ($payload['items'] as $item) {
                // Lock the product row to prevent overselling under concurrent requests
                $product = Product::lockForUpdate()->findOrFail($item['product_id']);
                $qty = (int) $item['qty'];

                if (!$product->inStock($qty)) {
                    throw ValidationException::withMessages([
                        'items' => ["Insufficient stock for product ID {$product->id}."]
                    ]);
                }

                $price = $product->resolvePrice($isStylist);
                $subtotal += $price * $qty;

                $order->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $qty,
                    'price' => $price,
                ]);

                $product->decrement('quantity', $qty);
            }

            // --- Coupon handling (atomic) ---
            $discount = 0;
            $couponId = null;
            $appliedCouponCode = null;

            if (!empty($payload['coupon_code'])) {
                $coupon = Coupon::lockForUpdate()
                    ->where('code', $payload['coupon_code'])
                    ->first();

                if (!$coupon) {
                    throw ValidationException::withMessages([
                        'coupon_code' => ['Coupon not found.'],
                    ]);
                }

                if (!$coupon->isValid()) {
                    throw ValidationException::withMessages([
                        'coupon_code' => ['Coupon is expired or has been fully used.'],
                    ]);
                }

                $discount = $coupon->calculateDiscount($subtotal);
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

            $shippingCost = $this->resolveShippingCost($subtotal);

            $order->total = $subtotal - $discount + $shippingCost;
            $order->discount = $discount;
            $order->shipping = $shippingCost;
            $order->coupon_id = $couponId;
            $order->coupon_code = $appliedCouponCode;
            $order->status = Order::STATUS_PENDING;
            $order->save();

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
                'order' => ['Only pending orders can be cancelled.']
            ]);
        }

        return DB::transaction(function () use ($order) {
            $order->status = Order::STATUS_CANCELLED;
            $order->save();

            $order->load('items.product');

            foreach ($order->items as $item) {
                if ($item->product) {
                    $item->product->increment('quantity', $item->quantity);
                }
            }

            return $order->load(['items.product.images', 'coupon', 'user', 'info']);
        });
    }

    private function resolveShippingCost(float $subtotal): float
    {
        return $subtotal >= 3000 ? 0.0 : 150.0;
    }
}
