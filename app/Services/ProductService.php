<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Exception;


class ProductService
{
    /* ===================================================== */
    /* INDEX (WITH FILTERING SUPPORT)                        */
    /* ===================================================== */

    public function paginate(array $filters = [], int $perPage = 20, ?User $viewer = null)
    {
        $query = Product::query()
            ->with([
                'brand',
                'category',
                'translations',
                'images',
                'sale',
                'hairTypes',
                'hairConcerns',
            ]);

        $this->applyVisibilityFilter($query, $viewer);
        $this->applyFilters($query, $filters);

        return $query->paginate($perPage);
    }

    /* ===================================================== */
    /* SHOW SINGLE                                           */
    /* ===================================================== */

    public function find(Product $product): Product
    {
        return $product->load([
            'brand',
            'category',
            'translations',
            'images',
            'sale',
            'hairTypes',
            'hairConcerns',
        ]);
    }

    public function canView(Product $product, ?User $viewer = null): bool
    {
        if (!$product->stylist_only) {
            return true;
        }

        return $viewer?->isStylist() || $viewer?->isAdmin();
    }

    /* ===================================================== */
    /* CREATE                                                */
    /* ===================================================== */

    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data) {

            $product = Product::create($this->extractProductData($data));

            $this->handleTranslations($product, $data);
            $this->handleSale($product, $data);
            $this->handleImage($product, $data);
            $this->syncHairProfile($product, $data);

            return $this->find($product);
        });
    }

    /* ===================================================== */
    /* UPDATE                                                */
    /* ===================================================== */

    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {

            $product->update($this->extractProductData($data));

            $this->handleTranslations($product, $data);
            $this->handleSale($product, $data);
            $this->handleImage($product, $data);
            $this->syncHairProfile($product, $data);

            return $this->find($product);
        });
    }

    /* ===================================================== */
    /* DELETE                                                */
    /* ===================================================== */

    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product) {

            $product->translations()->delete();
            $product->images()->delete();
            $product->sale()->delete();

            $product->delete();
        });
    }

    /* ===================================================== */
    /* RELATED PRODUCTS                                      */
    /* ===================================================== */

    public function related(Product $product, int $limit = 3, ?User $viewer = null)
    {
        $query = Product::with(['brand', 'images'])
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->limit($limit);

        $this->applyVisibilityFilter($query, $viewer);

        return $query->get();
    }

    /* ===================================================== */
    /* LATEST                                                */
    /* ===================================================== */

    public function latest(int $limit = 3, ?User $viewer = null)
    {
        $query = Product::with(['images', 'sale'])
            ->latest()
            ->limit($limit);

        $this->applyVisibilityFilter($query, $viewer);

        return $query->get();
    }

    /* ===================================================== */
    /* SEARCH                                                */
    /* ===================================================== */

    public function search(string $query, int $limit = 10, ?User $viewer = null)
    {
        $builder = Product::query()
            ->with(['brand', 'category', 'translations', 'images', 'sale'])
            ->where('name', 'like', '%' . $query . '%')
            ->limit($limit);

        $this->applyVisibilityFilter($builder, $viewer);

        return $builder->get();
    }

    /* ===================================================== */
    /* PRODUCTS ON SALE                                      */
    /* ===================================================== */

    public function activeSales()
    {
        return Product::whereHas('sale', function ($query) {
            $query->where('start_date', '<=', now())
                ->where('end_date', '>=', now());
        })
            ->with(['sale', 'images'])
            ->get();
    }

    /* ===================================================== */
    /* STOCK CONTROL                                         */
    /* ===================================================== */

    public function reduceStock(Product $product, int $quantity): void
    {
        if (!$product->inStock($quantity)) {
            throw new Exception('Insufficient stock.');
        }

        $product->decrement('quantity', $quantity);
    }

    /* ===================================================== */
    /* PRICE RESOLUTION                                      */
    /* ===================================================== */

    public function resolvePrice(Product $product, bool $isStylist = false): float
    {
        return $product->resolvePrice($isStylist);
    }

    /* ===================================================== */
    /* PRIVATE METHODS                                       */
    /* ===================================================== */

    private function extractProductData(array $data): array
    {
        return collect($data)->only([
            'name',
            'brand_id',
            'category_id',
            'quantity',
            'price',
            'stylist_price',
            'stylist_only',
        ])->toArray();
    }

    private function handleTranslations(Product $product, array $data): void
    {
        if (!isset($data['translations']) || !is_array($data['translations'])) {
            return;
        }

        $translations = $data['translations'];

        // Accept either [{ locale, description }] or { en: "...", mk: "..." } input.
        if (array_keys($translations) !== range(0, count($translations) - 1)) {
            $translations = collect($translations)
                ->map(fn ($description, $locale) => [
                    'locale' => $locale,
                    'description' => $description,
                ])
                ->values()
                ->all();
        }

        foreach ($translations as $translation) {
            if (!isset($translation['locale'])) {
                continue;
            }

            $description = $translation['description'] ?? null;

            if ($description === null || $description === '') {
                $product->translations()->where('locale', $translation['locale'])->delete();
                continue;
            }

            $product->translations()->updateOrCreate(
                ['locale' => $translation['locale']],
                ['description' => $description]
            );
        }
    }

    private function applyVisibilityFilter(Builder $query, ?User $viewer = null): void
    {
        if ($viewer?->isStylist() || $viewer?->isAdmin()) {
            return;
        }

        $query->where('stylist_only', false);
    }

    private function handleSale(Product $product, array $data): void
    {
        if (!isset($data['sale'])) {
            return;
        }

        if (empty($data['sale'])) {
            $product->sale()->delete();
            return;
        }

        $product->sale()->updateOrCreate(
            ['product_id' => $product->id],
            $data['sale']
        );
    }

    private function handleImage(Product $product, array $data): void
    {
        if (!isset($data['image'])) {
            return;
        }

        $product->images()->delete();

        $product->images()->create([
            'name' => $data['image']
        ]);
    }

    private function syncHairProfile(Product $product, array $data): void
    {
        if (array_key_exists('hair_type_ids', $data)) {
            $product->hairTypes()->sync($data['hair_type_ids'] ?? []);
        }

        if (array_key_exists('hair_concern_ids', $data)) {
            $product->hairConcerns()->sync($data['hair_concern_ids'] ?? []);
        }
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if (!empty($filters['brand_id'])) {
            $query->where('brand_id', $filters['brand_id']);
        }

        if (!empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (!empty($filters['min_price'])) {
            $query->where('products.price', '>=', $filters['min_price']);
        }

        if (!empty($filters['max_price'])) {
            $query->where('products.price', '<=', $filters['max_price']);
        }

        if (!empty($filters['search'])) {
            $query->where('products.name', 'like', '%' . $filters['search'] . '%');
        }

        if (!empty($filters['on_sale'])) {
            $query->whereHas('sale', function ($q) {
                $q->where('start_date', '<=', now())
                    ->where('end_date', '>=', now());
            });
        }

        if (!empty($filters['in_stock'])) {
            $query->where('products.quantity', '>', 0);
        }

        // Sorting — use database-driven sort_priority on categories
        $sort = $filters['sort'] ?? 'name_asc';

        if (!isset($filters['category_id'])) {
            $query->join('categories', 'products.category_id', '=', 'categories.id')
                  ->orderBy('categories.sort_priority', 'asc')
                  ->select('products.*');
        }

        match ($sort) {
            'name_desc'  => $query->orderBy('products.name', 'desc'),
            'price_asc'  => $query->orderBy('products.price', 'asc'),
            'price_desc' => $query->orderBy('products.price', 'desc'),
            'newest'     => $query->orderBy('products.created_at', 'desc'),
            default      => $query->orderBy('products.name', 'asc'),
        };
    }
}
