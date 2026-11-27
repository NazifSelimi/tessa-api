<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Normal coupons (owner rule Q7). Stylist referral codes are not affected.
 *
 * - Each customer can use a normal coupon once: coupon_user for signed-in
 *   customers, and the email / phone on earlier orders for everyone (guests).
 * - When an order is cancelled or trashed, its coupon use comes back: the
 *   coupon's quantity goes up again and the customer may use it again.
 *   Undoing that cancel or restoring the order takes the use again.
 */
class CouponUsage
{
    public function assertNotUsedBefore(Coupon $coupon, ?User $user, ?string $email = null, ?string $phone = null): void
    {
        if ($coupon->isReferral()) {
            return;
        }

        if ($this->usedBefore($coupon, $user, $email, $phone)) {
            throw ValidationException::withMessages([
                'coupon_code' => [__('You have already used this coupon.')],
            ]);
        }
    }

    public function usedBefore(Coupon $coupon, ?User $user, ?string $email = null, ?string $phone = null): bool
    {
        if ($user && $user->coupons()->whereKey($coupon->getKey())->exists()) {
            return true;
        }

        $email = $email ? mb_strtolower(trim($email)) : null;
        $phone = $this->digits($phone);
        if (! $user && ! $email && ! $phone) {
            return false;
        }

        // Orders that still use this coupon: not cancelled, not in the Trash.
        return Order::query()
            ->where('coupon_id', $coupon->getKey())
            ->where('status', '!=', Order::STATUS_CANCELLED)
            ->with('info')
            ->get(['id', 'user_id'])
            ->contains(function (Order $order) use ($user, $email, $phone) {
                if ($user && (int) $order->user_id === (int) $user->getKey()) {
                    return true;
                }
                $info = $order->info;

                return ($email && $info && mb_strtolower(trim((string) $info->email)) === $email)
                    || ($phone && $info && $this->digits($info->phone) === $phone);
            });
    }

    /** The order no longer uses its coupon (cancelled or trashed). Call once per change, inside a transaction. */
    public function release(Order $order): void
    {
        $coupon = $this->normalCoupon($order);
        if (! $coupon) {
            return;
        }

        Coupon::query()->whereKey($coupon->getKey())->increment('quantity');
        if ($order->user_id) {
            User::query()->find($order->user_id)?->coupons()->detach($coupon->getKey());
        }
    }

    /** The order uses its coupon again (cancel undone, or restored from the Trash). */
    public function retake(Order $order): void
    {
        $coupon = $this->normalCoupon($order);
        if (! $coupon) {
            return;
        }

        Coupon::query()->whereKey($coupon->getKey())->where('quantity', '>', 0)->decrement('quantity');
        if ($order->user_id) {
            User::query()->find($order->user_id)?->coupons()->syncWithoutDetaching([$coupon->getKey() => ['used_at' => now()]]);
        }
    }

    /** Shown on the order: its coupon can be used again (cancelled or trashed order with a normal coupon). */
    public static function released(Order $order): bool
    {
        return $order->coupon_id !== null
            && $order->referral_stylist_id === null
            && ($order->status === Order::STATUS_CANCELLED || $order->trashed());
    }

    private function normalCoupon(Order $order): ?Coupon
    {
        $coupon = $order->coupon_id ? Coupon::query()->find($order->coupon_id) : null;

        return $coupon && ! $coupon->isReferral() ? $coupon : null;
    }

    private function digits(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?: '';

        return $digits === '' ? null : $digits;
    }
}
