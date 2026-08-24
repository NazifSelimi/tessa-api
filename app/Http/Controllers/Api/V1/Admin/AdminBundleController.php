<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\BundleResource;
use App\Models\Bundle;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminBundleController extends Controller
{
    public function index()
    {
        return ApiResponse::ok(BundleResource::collection(
            Bundle::query()->with(['products.images'])->latest()->get()
        )->resolve());
    }

    public function store(Request $request)
    {
        $bundle = Bundle::create($this->validated($request));
        $this->syncProducts($bundle, $request->input('products', []));

        return ApiResponse::ok(new BundleResource($bundle->load('products.images')), 201);
    }

    public function update(Request $request, Bundle $bundle)
    {
        $bundle->update($this->validated($request, true));
        if ($request->has('products')) {
            $this->syncProducts($bundle, $request->input('products', []));
        }

        return ApiResponse::ok(new BundleResource($bundle->load('products.images')));
    }

    public function destroy(Bundle $bundle)
    {
        $bundle->delete();

        return ApiResponse::ok(null, 200, [], 'Offer deleted successfully.');
    }

    private function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'audience' => [$required, 'in:all,stylist'],
            'promotion_type' => [$required, 'in:percentage,fixed_price,bonus_items'],
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'bundle_price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'products' => [$updating ? 'sometimes' : 'required', 'array', 'min:1'],
            'products.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'products.*.quantity' => ['required', 'integer', 'min:1'],
            'products.*.is_bonus' => ['nullable', 'boolean'],
        ]);
    }

    private function syncProducts(Bundle $bundle, array $products): void
    {
        $bundle->products()->sync(collect($products)->mapWithKeys(fn ($product) => [
            $product['product_id'] => [
                'quantity' => $product['quantity'],
                'is_bonus' => (bool) ($product['is_bonus'] ?? false),
            ],
        ])->all());
    }
}
