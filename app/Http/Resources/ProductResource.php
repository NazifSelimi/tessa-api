<?php

namespace App\Http\Resources;

use App\Support\ApiUserResolver;
use App\Support\ProductCatalogGuidance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $categoryName = $this->relationLoaded('category') ? $this->category?->name : null;

        // Customers get the curated photo set; admins see every attached image.
        $primaryImage = null;
        $media = null;
        $isAdmin = (bool) ApiUserResolver::fromRequest($request)?->isAdmin();

        if ($this->relationLoaded('images')) {
            $images = $isAdmin ? $this->images : $this->storefrontImages();
            if ($images->isNotEmpty() || $this->images->isNotEmpty()) {
                $primaryImage = $this->imageUrl($this->primaryImage());
                $media = $images->map(fn ($image) => [
                    'url' => $this->imageUrl($image),
                    'alt' => $image->alt,
                    'variant' => $image->variant ?? 'legacy',
                    'background' => $image->background ?? 'unknown',
                    'reviewStatus' => $image->review_status ?? 'legacy',
                    'sortOrder' => (int) ($image->sort_order ?? 0),
                    'metadata' => $image->metadata,
                ])->values();
            }
        }

        $reviewReasons = $this->relationLoaded('images') && $this->relationLoaded('brand') && $this->relationLoaded('category')
            ? $this->reviewReasons()
            : null;

        // Get description from translations (locale-aware)
        $description = null;
        $translations = null;
        if ($this->relationLoaded('translations') && $this->translations->isNotEmpty()) {
            $locale = app()->getLocale();
            $translation = $this->translations->firstWhere('locale', $locale)
                ?? $this->translations->first();
            $description = $translation?->description;

            // Return all translations as a locale-keyed map
            $translations = $this->translations->pluck('description', 'locale');
        }

        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'description' => $description,
            'translations' => $translations,
            'price' => (float) $this->price,
            'stylistPrice' => (float) $this->stylist_price,
            'stylistOnly' => (bool) $this->stylist_only,
            // Only admins ever receive products in review; see Product::scopeReadyForSale().
            'inReview' => $reviewReasons === null ? null : $reviewReasons !== [],
            'reviewReasons' => $reviewReasons,
            'hairTypeIds' => $this->whenLoaded('hairTypes', fn () => $this->hairTypes->pluck('id')->map(fn ($id) => (int) $id)->values()),
            'hairConcernIds' => $this->whenLoaded('hairConcerns', fn () => $this->hairConcerns->pluck('id')->map(fn ($id) => (int) $id)->values()),
            'catalogGuidance' => ProductCatalogGuidance::forProduct($categoryName, $this->name),
            'quantity' => (int) $this->quantity,
            'inStock' => $this->quantity > 0,

            'brandId' => (string) $this->brand_id,
            'brand' => $this->whenLoaded('brand', fn () => $this->brand ? [
                'id' => (string) $this->brand->id,
                'name' => $this->brand->name,
            ] : null),
            'categoryId' => (string) $this->category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => (string) $this->category->id,
                'name' => $this->category->name,
            ] : null),

            'image' => $primaryImage,
            'media' => $media,
            'collections' => $this->whenLoaded('catalogCollections', fn () => $this->catalogCollections
                ->map(fn ($collection) => [
                    'slug' => $collection->slug,
                    'name' => $collection->name,
                    'title' => $collection->title,
                    'mappingStatus' => $collection->pivot?->mapping_status,
                ])->values()),
            'collectionAssignments' => $this->when(
                $request->user()?->isAdmin() && $this->relationLoaded('collectionAssignments'),
                fn () => $this->collectionAssignments->map(fn ($collection) => [
                    'slug' => $collection->slug,
                    'name' => $collection->name,
                    'mappingStatus' => $collection->pivot?->mapping_status,
                    'source' => $collection->pivot?->source,
                    'notes' => $collection->pivot?->notes,
                ])->values()
            ),

            'sale' => $this->whenLoaded('sale', fn () => $this->sale ? [
                'price' => (float) $this->sale->sale_price,
                'startDate' => $this->sale->start_date,
                'endDate' => $this->sale->end_date,
            ] : null),

            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }

    private function imageUrl($image): ?string
    {
        if ($image->url) {
            return $image->url;
        }

        return $image->publicUrl();
    }
}
