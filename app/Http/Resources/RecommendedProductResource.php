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
            $primaryImage = asset('storage/images/' . $this->primaryImage()->name);
        }

        $description = null;
        if ($this->relationLoaded('translations') && $this->translations->isNotEmpty()) {
            $locale = \App\Support\ContentLocale::current();
            $translation = $this->translations->firstWhere('locale', $locale)
                ?? $this->translations->first();
            $description = $translation?->description;
        }

        return [
            'id'                  => (string) $this->id,
            'name'                => $this->name,
            'description'         => $description,
            'price'               => (float) $this->price,
            'stylistPrice'        => $this->when(
                (bool) \App\Support\ApiUserResolver::fromRequest($request)?->canSeeSalonPrices(),
                fn () => (float) $this->stylist_price,
            ),
            'stylistOnly'         => (bool) $this->stylist_only,
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
            'recommendationScore' => (int) round((float) $this->recommendation_score),
            'quizStep'            => $this->quiz_step_effective ?? $this->quiz_step,
            'matchedNeedIds'      => array_values($this->matched_need_ids ?? []),
            'matchedTypeIds'      => array_values($this->matched_type_ids ?? []),
            'familyId'            => $this->product_family_id ? (string) $this->product_family_id : null,
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
