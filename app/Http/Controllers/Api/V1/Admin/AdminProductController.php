<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
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
     * Create new product.
     */
    public function store(Request $request)
    {
        $data = $request->all();

        // Map camelCase to snake_case
        $fieldMap = ['categoryId' => 'category_id', 'brandId' => 'brand_id', 'stylistPrice' => 'stylist_price'];
        foreach ($fieldMap as $camel => $snake) {
            if (isset($data[$camel]) && !isset($data[$snake])) {
                $data[$snake] = $data[$camel];
            }
        }

        $validated = validator($data, [
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'stylist_price' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['required', 'integer', 'min:0'],
            'category_id' => ['required', 'exists:categories,id'],
            'brand_id' => ['required', 'exists:brands,id'],
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,gif,webp', 'max:10240'],
            'description' => ['nullable', 'string'],
            'locale' => ['nullable', 'string', 'max:10'],
        ])->validate();

        // Auto-calculate stylist_price if not provided
        if (!isset($validated['stylist_price'])) {
            $validated['stylist_price'] = $validated['price'] * 0.9;
        }

        $productData = collect($validated)->only(['name', 'price', 'stylist_price', 'quantity', 'category_id', 'brand_id'])->toArray();
        $product = Product::create($productData);

        // Handle image upload — convert to WebP
        if ($request->hasFile('image')) {
            $filename = $this->imageService->storeAsWebP($request->file('image'), 'images');
            $product->images()->create(['name' => $filename]);
        }

        // Handle translation
        if (!empty($validated['description'])) {
            $product->translations()->create([
                'locale' => $validated['locale'] ?? 'en',
                'description' => $validated['description'],
            ]);
        }

        $product->load(['brand', 'category', 'images', 'sale', 'translations']);

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

        $data = $request->all();
        $fieldMap = ['categoryId' => 'category_id', 'brandId' => 'brand_id', 'stylistPrice' => 'stylist_price'];
        foreach ($fieldMap as $camel => $snake) {
            if (isset($data[$camel]) && !isset($data[$snake])) {
                $data[$snake] = $data[$camel];
            }
        }

        $validated = validator($data, [
            'name' => ['sometimes', 'string', 'max:255'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'stylist_price' => ['sometimes', 'numeric', 'min:0'],
            'quantity' => ['sometimes', 'integer', 'min:0'],
            'category_id' => ['sometimes', 'exists:categories,id'],
            'brand_id' => ['sometimes', 'exists:brands,id'],
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,jpg,png,gif,webp', 'max:10240'],
            'description' => ['sometimes', 'nullable', 'string'],
            'locale' => ['nullable', 'string', 'max:10'],
        ])->validate();

        // Auto-update stylist_price if price changed and stylist_price not provided
        if (isset($validated['price']) && !isset($validated['stylist_price'])) {
            $validated['stylist_price'] = $validated['price'] * 0.9;
        }

        $productData = collect($validated)->only(['name', 'price', 'stylist_price', 'quantity', 'category_id', 'brand_id'])->toArray();
        $product->update($productData);

        // Handle image upload — convert to WebP and replace old images
        if ($request->hasFile('image')) {
            // Delete old image files from storage
            foreach ($product->images as $oldImage) {
                $this->imageService->delete($oldImage->name);
            }
            $product->images()->delete();

            $filename = $this->imageService->storeAsWebP($request->file('image'), 'images');
            $product->images()->create(['name' => $filename]);
        }

        // Handle translation update
        if (isset($validated['description'])) {
            $locale = $validated['locale'] ?? 'en';
            $product->translations()->updateOrCreate(
                ['locale' => $locale],
                ['description' => $validated['description']]
            );
        }

        $product->load(['brand', 'category', 'images', 'sale', 'translations']);

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
}
