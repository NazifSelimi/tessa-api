<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BundleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Support virtual (dynamic) bundles passed as plain arrays
        if (is_array($this->resource)) {
            return $this->resource;
        }

        return [
            'id'                 => (string) $this->id,
            'name'               => $this->name,
            'description'        => $this->description,
            'isDynamic'          => (bool) $this->is_dynamic,
            'discountPercentage' => $this->discount_percentage ? (float) $this->discount_percentage : null,
            'promotionType'      => $this->promotion_type,
            'bundlePrice'        => $this->bundle_price ? (float) $this->bundle_price : null,
            'audience'           => $this->audience,
            'isActive'           => (bool) $this->is_active,
            'isFeatured'         => (bool) $this->is_featured,
            'startsAt'           => $this->starts_at?->toISOString(),
            'endsAt'             => $this->ends_at?->toISOString(),
            'products'           => $this->whenLoaded('products', fn () =>
                $this->products->map(fn ($product) => [
                    'id'       => (string) $product->id,
                    'name'     => $product->name,
                    'price'    => (float) $product->price,
                    'quantity' => (int) $product->pivot->quantity,
                    'isBonus'  => (bool) $product->pivot->is_bonus,
                    'image'    => $product->images->isNotEmpty()
                        ? asset('storage/images/' . $product->images->first()->name)
                        : null,
                ])->values()
            ),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
