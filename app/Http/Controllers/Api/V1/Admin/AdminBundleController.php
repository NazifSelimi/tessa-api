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
            Bundle::query()->with(['products.images', 'family'])->latest()->get()
        )->resolve());
    }

    public function store(Request $request)
    {
        $bundle = Bundle::create($this->clean($this->validated($request)));
        $this->syncProducts($bundle, $request->input('products', []));

        return ApiResponse::ok(new BundleResource($bundle->load('products.images')), 201);
    }

    public function update(Request $request, Bundle $bundle)
    {
        $bundle->update($this->clean($this->validated($request, true), $bundle));
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

    /** A colour line and steps only belong to "pick any colours" offers; tier free counts never exceed the step. */
    private function clean(array $data, ?Bundle $current = null): array
    {
        if (isset($data['promotion_type']) && $data['promotion_type'] !== Bundle::MIX_MATCH) {
            $data['product_family_id'] = null;
            $data['product_family_ids'] = null;
            $data['tiers'] = null;
        }
        if (! empty($data['tiers'])) {
            // The admin form edits min / free / gift only; a step's own gifts and gift count stay as they were.
            // A step keeps them when its min changes: same min first, otherwise the step in the same place.
            $before = array_values($current?->sortedTiers() ?? []);
            $byMin = collect($before)->keyBy('min');
            $data['tiers'] = array_map(
                fn ($tier, $i) => $tier + array_intersect_key(
                    $byMin->get((int) $tier['min'], $before[$i] ?? []),
                    ['gift_qty' => 1, 'gift_ids' => 1],
                ),
                array_values($data['tiers']),
                array_keys(array_values($data['tiers'])),
            );
            $data['tiers'] = array_values(array_map(fn ($tier) => [
                'min' => (int) $tier['min'],
                'free' => min((int) ($tier['free'] ?? 0), (int) $tier['min']),
                'gift' => (bool) ($tier['gift'] ?? false),
                'gift_qty' => max(1, (int) ($tier['gift_qty'] ?? 1)),
                'gift_ids' => array_values(array_map('intval', $tier['gift_ids'] ?? [])),
            ], $data['tiers']));
        }
        unset($data['products']);

        return $data;
    }

    private function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'audience' => [$required, 'in:' . implode(',', Bundle::AUDIENCES)],
            'promotion_type' => [$required, 'in:percentage,fixed_price,bonus_items,mix_match'],
            // "Pick any colours": the colour line and its reward steps; products = gift choices.
            // A colour line, or a product list (products not marked as gifts) is counted instead.
            'product_family_id' => ['nullable', 'integer', 'exists:product_families,id'],
            'product_family_ids' => ['nullable', 'array', 'max:10'],
            'product_family_ids.*' => ['integer', 'exists:product_families,id'],
            'tiers' => ['nullable', 'required_if:promotion_type,mix_match', 'array', 'max:6'],
            'tiers.*.min' => ['required', 'integer', 'min:1', 'max:500'],
            'tiers.*.free' => ['nullable', 'integer', 'min:0', 'max:100'],
            'tiers.*.gift' => ['nullable', 'boolean'],
            'tiers.*.gift_qty' => ['nullable', 'integer', 'min:1', 'max:20'],
            'tiers.*.gift_ids' => ['nullable', 'array', 'max:10'],
            'tiers.*.gift_ids.*' => ['integer', 'exists:products,id'],
            'discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'bundle_price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'products' => [$updating ? 'sometimes' : 'required_unless:promotion_type,mix_match', 'array'],
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
                'is_bonus' => (bool) ($product['is_bonus'] ?? ((int) ($product['bonus_quantity'] ?? 0) > 0)),
                'bonus_quantity' => min(
                    (int) $product['quantity'],
                    (int) ($product['bonus_quantity'] ?? ((bool) ($product['is_bonus'] ?? false) ? $product['quantity'] : 0))
                ),
            ],
        ])->all());
    }
}
