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

        $salonPrices = (bool) \App\Support\ApiUserResolver::fromRequest($request)?->canSeeSalonPrices();

        return [
            'id'                 => (string) $this->id,
            'name'               => $this->name,
            'description'        => $this->description,
            // Picture made in Post Studio (Bundles tab); null until one is saved.
            'image'              => $this->image_path ? asset('storage/' . $this->image_path) : null,
            'isDynamic'          => (bool) $this->is_dynamic,
            'discountPercentage' => $this->discount_percentage ? (float) $this->discount_percentage : null,
            'promotionType'      => $this->promotion_type,
            'bundlePrice'        => $this->bundle_price ? (float) $this->bundle_price : null,
            // "Pick any colours" offers: the colour line, its reward steps, and one shade to open the shade list from.
            'familyId'           => $this->product_family_id ? (string) $this->product_family_id : null,
            'familyName'         => $this->when((bool) $this->product_family_id, fn () => $this->family?->name),
            'tiers'              => $this->isMixMatch() ? $this->sortedTiers() : null,
            'familyProductId'    => $this->when($this->isMixMatch() && $this->product_family_id, function () {
                $id = \App\Models\Product::query()->where('product_family_id', $this->product_family_id)
                    ->orderByDesc('quantity')->orderBy('id')->value('id');

                return $id ? (string) $id : null;
            }),
            // Every colour line the offer counts, each with one shade to open its shade list from.
            'familyLines'        => $this->when($this->isMixMatch(), fn () => collect($this->familyIds())->map(function ($familyId) {
                $family = \App\Models\ProductFamily::find($familyId);
                $productId = \App\Models\Product::query()->where('product_family_id', $familyId)
                    ->orderByDesc('quantity')->orderBy('id')->value('id');

                return $family && $productId ? ['id' => (string) $familyId, 'name' => $family->name, 'productId' => (string) $productId] : null;
            })->filter()->values()),
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
                    'stylistPrice' => $this->when($salonPrices, fn () => (float) $product->stylist_price),
                    'quantity' => (int) $product->pivot->quantity,
                    'isBonus'  => \App\Models\Bundle::isGiftLine($product->pivot),
                    'bonusQuantity' => (int) $product->pivot->bonus_quantity,
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
