<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\OrderService;
use App\Services\PricingService;
use App\Services\ReferralService;
use App\Support\ApiResponse;
use App\Support\ApiUserResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Prices the cart exactly as checkout will, so the cart and checkout pages
 * show the real total. Changes nothing: no stock or coupon is used up.
 */
class CartQuoteController extends Controller
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly OrderService $orders,
        private readonly ReferralService $referrals
    ) {}

    public function __invoke(Request $request)
    {
        $validated = $request->validate([
            'coupon_code' => ['nullable', 'string', 'max:50'],
            'use_store_credit' => ['nullable', 'boolean'],
            'bundle_ids' => ['nullable', 'array'],
            'bundle_ids.*' => ['integer', 'distinct'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
        ]);

        $user = $request->bearerToken() ? ApiUserResolver::fromRequest($request) : null;
        $products = Product::query()->with(['category', 'sale'])
            ->findMany(collect($validated['items'])->pluck('product_id'))
            ->keyBy('id');

        $lines = [];
        foreach ($validated['items'] as $item) {
            $product = $products->get($item['product_id']);
            if (!$product) {
                throw ValidationException::withMessages(['items' => ["Product ID {$item['product_id']} is not available for purchase."]]);
            }
            $lines[] = ['product' => $product, 'qty' => (int) $item['qty']];
        }

        $coupon = null;
        $couponError = null;
        if (!empty($validated['coupon_code'])) {
            try {
                $coupon = $this->orders->validCoupon($validated['coupon_code']);
                $this->referrals->assertUsable($coupon, $user);
            } catch (ValidationException $e) {
                $coupon = null;
                $couponError = $e->errors()['coupon_code'][0] ?? 'Coupon is not valid.';
            }
        }

        $quote = $this->pricing->quote($user, $lines, $validated['bundle_ids'] ?? [], $coupon, false);
        $quote['couponError'] = $couponError;
        $quote['isReferralCode'] = (bool) $coupon?->isReferral();

        // Store credit is a way to pay, not a discount: it does not touch the profit floor.
        $quote['storeCreditAvailable'] = $user ? $this->referrals->balance($user) : 0.0;
        $quote['storeCreditUsed'] = !empty($validated['use_store_credit'])
            ? round(min($quote['storeCreditAvailable'], $quote['total']), 2)
            : 0.0;
        $quote['totalToPay'] = round($quote['total'] - $quote['storeCreditUsed'], 2);

        return ApiResponse::ok($user?->isAdmin() ? $quote : $this->pricing->forCustomer($quote));
    }
}
