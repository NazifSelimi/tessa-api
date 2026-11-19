<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\BundleResource;
use App\Models\Bundle;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
        $this->forgetImage($bundle);
        $bundle->delete();

        return ApiResponse::ok(null, 200, [], 'Offer deleted successfully.');
    }

    /** One change for many offers: switch on/off, feature, audience, discount, end date or delete. */
    public function bulk(Request $request)
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'exists:bundles,id'],
            'action' => ['required', 'in:activate,deactivate,feature,unfeature,audience,discount,ends_at,delete'],
            'value' => ['nullable'],
        ]);
        $value = $data['value'] ?? null;
        match ($data['action']) {
            'audience' => $request->validate(['value' => ['required', 'in:' . implode(',', Bundle::AUDIENCES)]]),
            'discount' => $request->validate(['value' => ['required', 'numeric', 'min:0', 'max:90']]),
            'ends_at' => $request->validate(['value' => ['nullable', 'date']]),
            default => null,
        };

        $bundles = Bundle::query()->whereIn('id', $data['ids'])->get();
        foreach ($bundles as $bundle) {
            match ($data['action']) {
                'activate' => $bundle->update(['is_active' => true]),
                'deactivate' => $bundle->update(['is_active' => false]),
                'feature' => $bundle->update(['is_featured' => true]),
                'unfeature' => $bundle->update(['is_featured' => false]),
                'audience' => $bundle->update(['audience' => $value]),
                // Only percentage offers have a % to change.
                'discount' => $bundle->promotion_type === 'percentage' ? $bundle->update(['discount_percentage' => $value]) : null,
                'ends_at' => $bundle->update(['ends_at' => $value ?: null]),
                'delete' => (function () use ($bundle) { $this->forgetImage($bundle); $bundle->delete(); })(),
            };
        }

        return ApiResponse::ok(['updated' => $bundles->count()], 200, null, "{$bundles->count()} offers updated");
    }

    /** Saves the bundle's picture made in Post Studio; the old picture file is removed. */
    public function uploadImage(Request $request, Bundle $bundle)
    {
        $request->validate(['image' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:5120']]);
        $path = $request->file('image')->store('bundles', 'public');
        $this->forgetImage($bundle);
        $bundle->update(['image_path' => $path]);

        return ApiResponse::ok(new BundleResource($bundle->load('products.images')), 200, null, 'Bundle picture saved');
    }

    public function deleteImage(Bundle $bundle)
    {
        $this->forgetImage($bundle);
        $bundle->update(['image_path' => null]);

        return ApiResponse::ok(new BundleResource($bundle->load('products.images')), 200, null, 'Bundle picture removed');
    }

    private function forgetImage(Bundle $bundle): void
    {
        if ($bundle->image_path) {
            Storage::disk('public')->delete($bundle->image_path);
        }
    }

    private function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'audience' => [$required, 'in:' . implode(',', Bundle::AUDIENCES)],
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
            'products.*.bonus_quantity' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function syncProducts(Bundle $bundle, array $products): void
    {
        $bundle->products()->sync(collect($products)->mapWithKeys(fn ($product) => [
            $product['product_id'] => [
                'quantity' => $product['quantity'],
                'is_bonus' => (bool) ($product['is_bonus'] ?? false),
                'bonus_quantity' => min(
                    (int) $product['quantity'],
                    (int) ($product['bonus_quantity'] ?? ((bool) ($product['is_bonus'] ?? false) ? $product['quantity'] : 0))
                ),
            ],
        ])->all());
    }
}
