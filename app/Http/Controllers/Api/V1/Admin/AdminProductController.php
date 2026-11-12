<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductIndexRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Services\ImageService;
use App\Services\ProductService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminProductController extends Controller
{
    public function __construct(
        protected ProductService $productService,
        protected ImageService $imageService
    ) {}

    /**
     * List products with the same filtering and sorting options as the storefront.
     */
    public function index(ProductIndexRequest $request)
    {
        $products = $this->productService->paginate(
            $request->filters(),
            $request->perPage(),
            $request->user()
        );

        return ApiResponse::ok(
            ProductResource::collection($products)->resolve(),
            200,
            [
                'current_page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'last_page' => $products->lastPage(),
            ]
        );
    }

    /**
     * Create new product.
     */
    public function store(Request $request)
    {
        $data = $this->normalizeRequestData($request);

        $validated = validator($data, [
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'stylist_price' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['required', 'integer', 'min:0'],
            'category_id' => ['required', 'exists:categories,id'],
            'brand_id' => ['required', 'exists:brands,id'],
            'stylist_only' => ['nullable', 'boolean'],
            'visible_to' => ['nullable', 'array'],
            'visible_to.*' => ['string', Rule::in(Product::AUDIENCES)],
            'hair_type_ids' => ['nullable', 'array'],
            'hair_type_ids.*' => ['integer', 'distinct', 'exists:hair_types,id'],
            'hair_concern_ids' => ['nullable', 'array'],
            'hair_concern_ids.*' => ['integer', 'distinct', 'exists:hair_concerns,id'],
            'normalize_catalog_background' => ['nullable', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,gif,webp', 'max:10240'],
            'translations' => ['nullable', 'array'],
            'translations.en' => ['nullable', 'string'],
            'translations.mk' => ['nullable', 'string'],
            'translations.shq' => ['nullable', 'string'],
        ])->validate();

        // Auto-calculate stylist_price if not provided
        if (!isset($validated['stylist_price'])) {
            $validated['stylist_price'] = $validated['price'] * 0.9;
        }

        // Preserve the source and create non-destructive catalogue variants.
        if ($request->hasFile('image')) {
            $validated['image_assets'] = $this->imageService->storeProductImageAssets(
                $request->file('image'),
                'images',
                1200,
                82,
                filter_var($validated['normalize_catalog_background'] ?? false, FILTER_VALIDATE_BOOL)
            );
            unset($validated['image']);
        }

        $validated['translations'] = $this->normalizeTranslations($validated['translations'] ?? []);
        $validated = $this->audienceColumns($validated);

        $product = $this->productService->create($validated);

        return ApiResponse::ok(
            new ProductResource($product),
            201,
            [],
            'Product created successfully'
        );
    }

    /**
     * Update existing product.
     */
    public function update(Request $request, $id)
    {
        $product = Product::findOrFail($id);

        $data = $this->normalizeRequestData($request);

        $validated = validator($data, [
            'name' => ['sometimes', 'string', 'max:255'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'stylist_price' => ['sometimes', 'numeric', 'min:0'],
            'cost_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'quantity' => ['sometimes', 'integer', 'min:0'],
            'category_id' => ['sometimes', 'exists:categories,id'],
            'brand_id' => ['sometimes', 'exists:brands,id'],
            'stylist_only' => ['sometimes', 'boolean'],
            'visible_to' => ['sometimes', 'nullable', 'array'],
            'visible_to.*' => ['string', Rule::in(Product::AUDIENCES)],
            'hair_type_ids' => ['sometimes', 'array'],
            'hair_type_ids.*' => ['integer', 'distinct', 'exists:hair_types,id'],
            'hair_concern_ids' => ['sometimes', 'array'],
            'hair_concern_ids.*' => ['integer', 'distinct', 'exists:hair_concerns,id'],
            'normalize_catalog_background' => ['sometimes', 'boolean'],
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,jpg,png,gif,webp', 'max:10240'],
            'translations' => ['sometimes', 'array'],
            'translations.en' => ['nullable', 'string'],
            'translations.mk' => ['nullable', 'string'],
            'translations.shq' => ['nullable', 'string'],
            // Colour lines: which line the product belongs to and its shade in that line.
            'product_family_id' => ['sometimes', 'nullable', 'integer', 'exists:product_families,id'],
            'shade_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'shade_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'shade_sort_order' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'swatch' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
            'remove_swatch' => ['sometimes', 'boolean'],
        ])->validate();

        // Fill a missing salon price from the new retail price, but never overwrite
        // a salon price that is already set.
        if (isset($validated['price']) && !isset($validated['stylist_price']) && (float) $product->stylist_price <= 0) {
            $validated['stylist_price'] = $validated['price'] * 0.9;
        }

        $validated = $this->audienceColumns($validated);
        $productData = collect($validated)->only([
            'name', 'price', 'stylist_price', 'cost_price', 'stylist_only', 'quantity', 'category_id', 'brand_id',
            'visible_to_guests', 'visible_to_customers', 'visible_to_stylists',
        ])->toArray();
        $product->update($productData);

        $shadeData = collect($validated)->only(['product_family_id', 'shade_code', 'shade_name', 'shade_sort_order'])
            ->map(fn ($value) => $value === '' ? null : $value)
            ->all();
        if ($request->hasFile('swatch')) {
            $shadeData['swatch_image'] = $request->file('swatch')->store('swatches/uploads', 'public');
        } elseif (filter_var($validated['remove_swatch'] ?? false, FILTER_VALIDATE_BOOL)) {
            $shadeData['swatch_image'] = null;
        }
        unset($validated['swatch'], $validated['remove_swatch']);
        if ($shadeData) {
            $product->forceFill($shadeData)->save();
        }

        // Persist an original plus derived assets; existing products are untouched until replaced.
        if ($request->hasFile('image')) {
            $validated['image_assets'] = $this->imageService->storeProductImageAssets(
                $request->file('image'),
                'images',
                1200,
                82,
                filter_var($validated['normalize_catalog_background'] ?? false, FILTER_VALIDATE_BOOL)
            );
            unset($validated['image']);
        }

        if (array_key_exists('translations', $validated)) {
            $validated['translations'] = $this->normalizeTranslations($validated['translations'] ?? []);
        }

        $product = $this->productService->update($product, $validated);

        return ApiResponse::ok(
            new ProductResource($product),
            200,
            [],
            'Product updated successfully'
        );
    }

    /**
     * Colour lines a product can be assigned to.
     */
    public function families()
    {
        $families = ProductFamily::query()
            ->withCount('products')
            ->orderBy('name')
            ->get()
            ->map(fn (ProductFamily $family) => [
                'id' => (string) $family->id,
                'name' => $family->name,
                'shadeCount' => $family->products_count,
            ]);

        return ApiResponse::ok($families);
    }

    /**
     * Delete product.
     */
    public function destroy($id)
    {
        $product = Product::findOrFail($id);

        if ($product->items()->exists()) {
            return ApiResponse::error('Cannot delete product with existing orders', 400);
        }

        $product->images()->get()->each->delete();
        $product->translations()->delete();
        $product->sale()->delete();
        $product->delete();

        return ApiResponse::ok(null, 200, [], 'Product deleted successfully');
    }

    /**
     * Update product stock.
     */
    public function updateStock(Request $request, $id)
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:0'],
            'operation' => ['required', 'in:set,add,subtract'],
        ]);

        $product = Product::findOrFail($id);

        switch ($validated['operation']) {
            case 'set':
                $product->quantity = $validated['quantity'];
                break;
            case 'add':
                $product->quantity += $validated['quantity'];
                break;
            case 'subtract':
                $product->quantity = max(0, $product->quantity - $validated['quantity']);
                break;
        }

        $product->save();

        return ApiResponse::ok(
            [
                'id' => (string) $product->id,
                'quantity' => $product->quantity,
                'inStock' => $product->quantity > 0,
            ],
            200,
            [],
            'Stock updated successfully'
        );
    }

    /**
     * Bulk update products.
     */
    public function bulkUpdate(Request $request)
    {
        $validated = $request->validate([
            'product_ids' => ['required', 'array'],
            'product_ids.*' => ['exists:products,id'],
            'updates' => ['required', 'array'],
            'updates.category_id' => ['sometimes', 'exists:categories,id'],
            'updates.brand_id' => ['sometimes', 'exists:brands,id'],
            'updates.visible_to' => ['sometimes', 'nullable', 'array'],
            'updates.visible_to.*' => ['string', Rule::in(Product::AUDIENCES)],
        ]);
        $validated['updates'] = $this->audienceColumns($validated['updates']);

        $updated = 0;
        $failed = 0;

        foreach ($validated['product_ids'] as $productId) {
            try {
                $product = Product::find($productId);
                if ($product) {
                    $product->update($validated['updates']);
                    $updated++;
                } else {
                    $failed++;
                }
            } catch (\Exception $e) {
                $failed++;
            }
        }

        return ApiResponse::ok(
            ['updated' => $updated, 'failed' => $failed],
            200,
            [],
            "{$updated} products updated successfully"
        );
    }

    /**
     * visible_to (the audiences ticked; empty = hidden) becomes the three
     * visible_to_* columns. It takes over from the old stylist_only switch.
     */
    private function audienceColumns(array $validated): array
    {
        if (!array_key_exists('visible_to', $validated)) {
            return $validated;
        }

        $audiences = $validated['visible_to'] ?? [];
        unset($validated['visible_to'], $validated['stylist_only']);

        foreach (Product::AUDIENCES as $audience) {
            $validated['visible_to_' . $audience] = in_array($audience, $audiences, true);
        }

        return $validated;
    }

    private function normalizeRequestData(Request $request): array
    {
        $data = $request->all();

        // Form posts cannot send an empty array, so 'none' (or '') means hidden.
        foreach (['visibleTo', 'visible_to'] as $key) {
            if (array_key_exists($key, $data) && !is_array($data[$key])) {
                $value = trim((string) $data[$key]);
                $data[$key] = in_array($value, ['', 'none'], true) ? [] : array_map('trim', explode(',', $value));
            }
        }

        $fieldMap = [
            'categoryId' => 'category_id',
            'brandId' => 'brand_id',
            'stylistPrice' => 'stylist_price',
            'costPrice' => 'cost_price',
            'stylistOnly' => 'stylist_only',
            'visibleTo' => 'visible_to',
            'normalizeCatalogBackground' => 'normalize_catalog_background',
        ];

        foreach ($fieldMap as $camel => $snake) {
            if (isset($data[$camel]) && !isset($data[$snake])) {
                $data[$snake] = $data[$camel];
            }
        }

        return $data;
    }

    private function normalizeTranslations(array $translations): array
    {
        return collect($translations)
            ->map(fn ($description, $locale) => [
                'locale' => $locale,
                'description' => $description,
            ])
            ->values()
            ->all();
    }
}
