<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bundle;
use App\Models\Product;
use App\Services\PricingService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;

class AdminPricingController extends Controller
{
    public function __construct(
        private readonly PricingService $pricing
    ) {}

    public function settings()
    {
        return ApiResponse::ok($this->pricing->settings());
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'costPercentage' => ['sometimes', 'numeric', 'min:1', 'max:100'],
            'minMarginPercentage' => ['sometimes', 'numeric', 'min:0', 'max:90'],
        ]);

        return ApiResponse::ok($this->pricing->updateSettings($validated));
    }

    /**
     * Profit of an offer as the admin is filling in the form; nothing is saved.
     */
    public function simulateOffer(Request $request)
    {
        $validated = $request->validate([
            'audience' => ['nullable', 'in:all,stylist'],
            'promotion_type' => ['required', 'in:percentage,fixed_price,bonus_items'],
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'bundle_price' => ['nullable', 'numeric', 'min:0'],
            'products' => ['required', 'array', 'min:1'],
            'products.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'products.*.quantity' => ['required', 'integer', 'min:1'],
            'products.*.bonus_quantity' => ['nullable', 'integer', 'min:0'],
        ]);

        $bundle = new Bundle([
            'audience' => $validated['audience'] ?? 'all',
            'promotion_type' => $validated['promotion_type'],
            'discount_percentage' => $validated['discount_percentage'] ?? null,
            'bundle_price' => $validated['bundle_price'] ?? null,
        ]);

        $products = Product::query()->with(['category', 'sale'])
            ->findMany(collect($validated['products'])->pluck('product_id'))
            ->keyBy('id');

        // Same pivot values AdminBundleController saves, so the result matches the live offer.
        $bundle->setRelation('products', collect($validated['products'])->map(function (array $row) use ($products) {
            $product = $products->get($row['product_id']);
            $product->setRelation('pivot', new Pivot([
                'quantity' => (int) $row['quantity'],
                'bonus_quantity' => min((int) $row['quantity'], (int) ($row['bonus_quantity'] ?? 0)),
            ]));

            return $product;
        })->values());

        return ApiResponse::ok($this->pricing->simulateOffer($bundle));
    }
}
