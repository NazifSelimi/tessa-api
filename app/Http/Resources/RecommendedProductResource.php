<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecommendedProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $primaryImage = null;
        if ($this->relationLoaded('images') && $this->images->isNotEmpty()) {
            $primaryImage = asset('storage/images/' . $this->images->first()->name);
        }

        $description = null;
        if ($this->relationLoaded('translations') && $this->translations->isNotEmpty()) {
            $locale = app()->getLocale();
            $translation = $this->translations->firstWhere('locale', $locale)
                ?? $this->translations->first();
            $description = $translation?->description;
        }

        return [
            'id'                  => (string) $this->id,
            'name'                => $this->name,
            'description'         => $description,
            'price'               => (float) $this->price,
            'stylistPrice'        => (float) $this->stylist_price,
            'quantity'            => (int) $this->quantity,
            'inStock'             => $this->quantity > 0,
            'brandId'             => (string) $this->brand_id,
            'brand'               => $this->whenLoaded('brand', fn () => $this->brand ? [
                'id' => (string) $this->brand->id, 'name' => $this->brand->name,
            ] : null),
            'categoryId'          => (string) $this->category_id,
            'category'            => $this->whenLoaded('category', fn () => $this->category ? [
                'id' => (string) $this->category->id, 'name' => $this->category->name,
            ] : null),
            'image'               => $primaryImage,
            'recommendationScore' => (int) $this->recommendation_score,
            'sale'                => $this->whenLoaded('sale', fn () => $this->sale ? [
                'price'     => (float) $this->sale->sale_price,
                'startDate' => $this->sale->start_date,
                'endDate'   => $this->sale->end_date,
            ] : null),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
