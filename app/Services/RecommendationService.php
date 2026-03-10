<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\Product;
use Illuminate\Support\Collection;

class RecommendationService
{
    /**
     * Get product recommendations based on hair type, concerns, and optional budget.
     *
     * @return array{products: array, bundles: array}
     */
    public function getRecommendations(int $hairTypeId, array $concerns, ?string $budgetRange = null): array
    {
        $products = $this->scoredProducts($hairTypeId, $concerns, $budgetRange);
        $bundles  = $this->matchingBundles($hairTypeId, $concerns);

        if ($bundles->isEmpty()) {
            $dynamic = $this->generateDynamicBundle($products);
            if ($dynamic !== null) {
                $bundles = collect([$dynamic]);
            }
        }

        return [
            'products' => $products->values()->all(),
            'bundles'  => $bundles->values()->all(),
        ];
    }

    /* -------------------------------------------------
     * SCORING
     * ------------------------------------------------- */

    protected function scoredProducts(int $hairTypeId, array $concerns, ?string $budgetRange): Collection
    {
        $query = Product::query()
            ->with(['brand', 'category', 'translations', 'images', 'sale', 'hairTypes', 'hairConcerns']);

        // Optional budget filtering
        if ($budgetRange !== null) {
            [$min, $max] = $this->parseBudgetRange($budgetRange);
            if ($min !== null) {
                $query->where('price', '>=', $min);
            }
            if ($max !== null) {
                $query->where('price', '<=', $max);
            }
        }

        // Only include products that match the hair type OR at least one concern
        $query->where(function ($q) use ($hairTypeId, $concerns) {
            $q->whereHas('hairTypes', fn ($ht) => $ht->where('hair_types.id', $hairTypeId));
            if (!empty($concerns)) {
                $q->orWhereHas('hairConcerns', fn ($hc) => $hc->whereIn('hair_concerns.id', $concerns));
            }
        });

        $products = $query->get();

        // Score each product
        $scored = $products->map(function (Product $product) use ($hairTypeId, $concerns) {
            $score = 0;

            if ($product->hairTypes->contains('id', $hairTypeId)) {
                $score += 5;
            }

            foreach ($concerns as $concernId) {
                if ($product->hairConcerns->contains('id', $concernId)) {
                    $score += 3;
                }
            }

            $product->setAttribute('recommendation_score', $score);

            return $product;
        });

        return $scored->sortByDesc('recommendation_score')->take(10);
    }

    /* -------------------------------------------------
     * BUNDLES
     * ------------------------------------------------- */

    /**
     * Find static bundles whose products overlap with the matching hair type / concerns.
     */
    protected function matchingBundles(int $hairTypeId, array $concerns): Collection
    {
        return Bundle::query()
            ->where('is_dynamic', false)
            ->whereHas('products', function ($q) use ($hairTypeId, $concerns) {
                $q->where(function ($sub) use ($hairTypeId, $concerns) {
                    $sub->whereHas('hairTypes', fn ($ht) => $ht->where('hair_types.id', $hairTypeId));
                    if (!empty($concerns)) {
                        $sub->orWhereHas('hairConcerns', fn ($hc) => $hc->whereIn('hair_concerns.id', $concerns));
                    }
                });
            })
            ->with(['products.images', 'products.brand', 'products.category'])
            ->get();
    }

    /**
     * Generate a virtual routine bundle from the top scored products.
     * Picks the best product from each routine category: shampoo, conditioner, treatment.
     */
    protected function generateDynamicBundle(Collection $scoredProducts): ?array
    {
        $routineCategories = ['shampoo', 'conditioner', 'treatment'];
        $picks = [];

        foreach ($routineCategories as $categoryName) {
            $pick = $scoredProducts->first(function (Product $product) use ($categoryName) {
                return $product->category
                    && str_contains(strtolower($product->category->name), $categoryName);
            });

            if ($pick !== null) {
                $picks[] = $pick;
            }
        }

        if (empty($picks)) {
            return null;
        }

        $totalPrice = collect($picks)->sum(fn (Product $p) => (float) $p->price);

        return [
            'id'                  => null,
            'name'                => 'Your Personalised Routine',
            'description'         => 'A curated routine based on your hair profile.',
            'isDynamic'           => true,
            'discountPercentage'  => null,
            'products'            => collect($picks)->map(fn (Product $p) => [
                'id'       => (string) $p->id,
                'name'     => $p->name,
                'price'    => (float) $p->price,
                'quantity' => 1,
                'image'    => $p->images->isNotEmpty()
                    ? asset('storage/images/' . $p->images->first()->name)
                    : null,
            ])->values()->all(),
            'totalPrice'          => (float) $totalPrice,
        ];
    }

    /* -------------------------------------------------
     * HELPERS
     * ------------------------------------------------- */

    /**
     * Parse a budget range string like "10-50" into [min, max].
     *
     * @return array{0: float|null, 1: float|null}
     */
    protected function parseBudgetRange(string $range): array
    {
        $parts = explode('-', $range, 2);

        $min = isset($parts[0]) && is_numeric($parts[0]) ? (float) $parts[0] : null;
        $max = isset($parts[1]) && is_numeric($parts[1]) ? (float) $parts[1] : null;

        return [$min, $max];
    }
}
