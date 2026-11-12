<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductImageController extends Controller
{
    public function index(Product $product)
    {
        $product->load(['images', 'brand']);
        $primary = $product->primaryImage();
        return response()->json([
            'product' => ['id' => $product->id, 'name' => $product->name, 'brand' => $product->brand?->name],
            'images' => $product->images->map(fn ($image) => [
                'id' => $image->id, 'url' => $image->url ?: $image->publicUrl(),
                'alt' => $image->alt, 'sort_order' => $image->sort_order,
                'variant' => $image->variant, 'review_status' => $image->review_status,
                'is_primary' => $image->id === $primary?->id,
            ])->values(),
        ]);
    }

    public function store(Request $request, Product $product)
    {
        $data = $request->validate([
            'image' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:10240', 'dimensions:max_width=8000,max_height=8000'],
            'alt' => ['nullable', 'string', 'max:255'],
        ]);
        $path = $request->file('image')->store('images/admin-review', 'public');
        try {
            DB::transaction(function () use ($product, $data, $path, $request) {
                Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
                $product->images()->create([
                    'name' => substr($path, strlen('images/')), 'alt' => $data['alt'] ?? $product->name,
                    'variant' => 'gallery', 'sort_order' => (int) $product->images()->max('sort_order') + 10,
                    'review_status' => 'needs_review', 'background' => 'unknown',
                    'metadata' => ['source' => 'admin-upload', 'uploaded_by' => $request->user()->id, 'uploaded_at' => now()->toIso8601String()],
                ]);
            });
        } catch (\Throwable $error) {
            Storage::disk('public')->delete($path);
            throw $error;
        }
        return $this->index($product);
    }

    public function update(Request $request, Product $product, int $image)
    {
        $data = $request->validate([
            'alt' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'review_status' => ['sometimes', 'in:approved,needs_review,rejected,hidden'],
            'make_primary' => ['sometimes', 'boolean'],
        ]);
        DB::transaction(function () use ($request, $product, $image, $data) {
            Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $record = $product->images()->whereKey($image)->firstOrFail();
            $primary = $data['make_primary'] ?? false;
            unset($data['make_primary']);
            if ($primary) {
                abort_if(in_array($data['review_status'] ?? $record->review_status, ['rejected', 'hidden'], true), 422, 'Approve or mark the image for review before making it primary.');
                $product->images()->where('variant', 'card')->update(['variant' => 'gallery']);
                $data['variant'] = 'card';
                $data['sort_order'] = 0;
            }
            $metadata = $record->metadata ?? [];
            $metadata['last_review'] = ['by' => $request->user()->id, 'at' => now()->toIso8601String()];
            $record->update([...$data, 'metadata' => $metadata]);
        });
        return $this->index($product);
    }

    /**
     * Remove one photo from a product that has more than one. Only the database
     * row goes; the file stays on disk so it can be recovered. If it was the
     * main photo, the next photo becomes main.
     */
    public function destroy(Request $request, Product $product, int $image)
    {
        DB::transaction(function () use ($product, $image) {
            Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $record = $product->images()->whereKey($image)->firstOrFail();
            $product->load('images');
            $primary = $product->primaryImage();
            $photos = $product->images->reject(fn ($i) => $i->isPlaceholder());
            abort_if($photos->count() <= 1 && !$record->isPlaceholder(), 422, 'This is the only photo of the product. Add another photo first.');
            $record->delete();
            if ($primary?->id === $record->id) {
                $next = $product->images()->get()->reject(fn ($i) => $i->isPlaceholder() || $i->isHidden() || $i->review_status === 'rejected')->first();
                if ($next) {
                    $product->images()->where('variant', 'card')->update(['variant' => 'gallery']);
                    $next->update(['variant' => 'card', 'sort_order' => 0]);
                }
            }
        });

        return $this->index($product->fresh());
    }
}
