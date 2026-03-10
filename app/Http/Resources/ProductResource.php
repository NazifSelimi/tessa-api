<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Get image from polymorphic images relation
        $primaryImage = null;

        if ($this->relationLoaded('images')) {
            $images = $this->images;
            if ($images instanceof \Illuminate\Database\Eloquent\Collection && $images->isNotEmpty()) {
                $primaryImage = asset('storage/images/' . $images->first()->name);
            }
        }

        // Get description from translations
        $description = null;
        if ($this->relationLoaded('translations') && $this->translations->isNotEmpty()) {
            $locale = app()->getLocale();
            $translation = $this->translations->firstWhere('locale', $locale)
                ?? $this->translations->first();
            $description = $translation?->description;
        }

        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'description' => $description,
            'price' => (float) $this->price,
            'stylistPrice' => (float) $this->stylist_price,
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

            'sale' => $this->whenLoaded('sale', fn () => $this->sale ? [
                'price' => (float) $this->sale->sale_price,
                'startDate' => $this->sale->start_date,
                'endDate' => $this->sale->end_date,
            ] : null),

            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
