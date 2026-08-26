<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductIndexRequest;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Services\ImageService;
use App\Services\ProductService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

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
            'quantity' => ['required', 'integer', 'min:0'],
            'category_id' => ['required', 'exists:categories,id'],
            'brand_id' => ['required', 'exists:brands,id'],
            'stylist_only' => ['nullable', 'boolean'],
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

        // Handle image upload — convert to WebP before passing to the service
        if ($request->hasFile('image')) {
            $filename = $this->imageService->storeAsWebP(
                $request->file('image'),
                'images',
                1200,
                82,
                filter_var($validated['normalize_catalog_background'] ?? false, FILTER_VALIDATE_BOOL)
            );
            $validated['image'] = $filename;
        }

        $validated['translations'] = $this->normalizeTranslations($validated['translations'] ?? []);

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
            'quantity' => ['sometimes', 'integer', 'min:0'],
            'category_id' => ['sometimes', 'exists:categories,id'],
            'brand_id' => ['sometimes', 'exists:brands,id'],
            'stylist_only' => ['sometimes', 'boolean'],
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
        ])->validate();

        // Auto-update stylist_price if price changed and stylist_price not provided
        if (isset($validated['price']) && !isset($validated['stylist_price'])) {
            $validated['stylist_price'] = $validated['price'] * 0.9;
        }

        $productData = collect($validated)->only(['name', 'price', 'stylist_price', 'stylist_only', 'quantity', 'category_id', 'brand_id'])->toArray();
        $product->update($productData);

        // Handle image upload — convert to WebP and replace old images
        if ($request->hasFile('image')) {
            // Delete old image files from storage
            foreach ($product->images as $oldImage) {
                $this->imageService->delete($oldImage->name);
            }

            $filename = $this->imageService->storeAsWebP(
                $request->file('image'),
                'images',
                1200,
                82,
                filter_var($validated['normalize_catalog_background'] ?? false, FILTER_VALIDATE_BOOL)
            );
            $validated['image'] = $filename;
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
     * Delete product.
     */
    public function destroy($id)
    {
        $product = Product::findOrFail($id);

        if ($product->items()->exists()) {
            return ApiResponse::error('Cannot delete product with existing orders', 400);
        }

        // Delete image files from storage
        foreach ($product->images as $image) {
            $this->imageService->delete($image->name);
        }

        $product->images()->delete();
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
        ]);

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

    private function normalizeRequestData(Request $request): array
    {
        $data = $request->all();

        $fieldMap = [
            'categoryId' => 'category_id',
            'brandId' => 'brand_id',
            'stylistPrice' => 'stylist_price',
            'stylistOnly' => 'stylist_only',
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
