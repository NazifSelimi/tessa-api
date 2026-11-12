<?php

namespace App\Services;

use App\Models\Coupon;
use Illuminate\Validation\ValidationException;

class CouponService
{
    /**
     * List coupons with optional filters and pagination.
     */
    public function listFiltered(array $filters, int $perPage = 20)
    {
        // Stylist referral codes have their own admin page.
        $query = Coupon::query()->whereNull('stylist_id');

        if (!empty($filters['search'])) {
            $query->where('code', 'like', '%' . $filters['search'] . '%');
        }

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'active') {
                $query->where('quantity', '>', 0)
                    ->where(function ($q) {
                        $q->whereNull('expiration_date')
                          ->orWhere('expiration_date', '>', now());
                    });
            } elseif ($filters['status'] === 'expired') {
                $query->where(function ($q) {
                    $q->where('quantity', '<=', 0)
                      ->orWhere(function ($q2) {
                          $q2->whereNotNull('expiration_date')
                             ->where('expiration_date', '<=', now());
                      });
                });
            }
        }

        return $query->latest()->paginate($perPage);
    }

    /**
     * Find a coupon by ID.
     */
    public function find($id): Coupon
    {
        return Coupon::findOrFail($id);
    }

    /**
     * Normalize camelCase fields from frontend to snake_case.
     */
    public function normalizeFields(array $data): array
    {
        if (isset($data['expirationDate']) && !isset($data['expiration_date'])) {
            $data['expiration_date'] = $data['expirationDate'];
        }

        return $data;
    }

    /**
     * Create a new coupon.
     */
    public function create(array $validated): Coupon
    {
        return Coupon::create($validated);
    }

    /**
     * Update an existing coupon.
     */
    public function update($id, array $validated): Coupon
    {
        $coupon = Coupon::findOrFail($id);
        $coupon->update($validated);

        return $coupon;
    }

    /**
     * Delete a coupon.
     */
    public function delete($id): array
    {
        $coupon = Coupon::findOrFail($id);
        $coupon->delete();

        return ['deleted' => true];
    }

    /**
     * Validate a coupon code against a subtotal for a given user.
     */
    public function validateCoupon(string $code, float $subtotal, $user): array
    {
        $coupon = Coupon::where('code', $code)->first();

        if (!$coupon) {
            return [
                'valid' => false,
                'error' => __('Coupon not found.'),
                'errorCode' => 404,
            ];
        }

        if (!$coupon->isValid()) {
            return [
                'valid' => false,
                'error' => __('Coupon is expired or has been fully used.'),
                'errorCode' => 422,
            ];
        }

        try {
            app(ReferralService::class)->assertUsable($coupon, $user);
        } catch (ValidationException $e) {
            $error = $e->errors()['coupon_code'][0];

            return [
                'valid' => false,
                'error' => $error,
                'errorCode' => 422,
                'requiresLogin' => $error === __(ReferralService::GUEST_ERROR),
            ];
        }

        $discount = $coupon->calculateDiscount($subtotal);

        return [
            'valid' => true,
            'coupon' => [
                'id' => $coupon->id,
                'code' => $coupon->code,
                'type' => $coupon->type,
                'value' => $coupon->value,
                'discount' => $discount,
            ],
        ];
    }
}
