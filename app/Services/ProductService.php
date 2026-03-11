<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Category;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Builder;
use Exception;

class ProductService
{
    /* ===================================================== */
    /* INDEX (WITH FILTERING SUPPORT)                        */
    /* ===================================================== */

    public function paginate(array $filters = [], int $perPage = 20)
    {
        $query = Product::query()
            ->with([
                'brand',
                'category',
                'translations',
                'images',
                'sale'
            ]);

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
            'sale'
        ]);
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

    public function related(Product $product, int $limit = 3)
    {
        return Product::with(['brand', 'images'])
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->limit($limit)
            ->get();
    }

    /* ===================================================== */
    /* LATEST                                                */
    /* ===================================================== */

    public function latest(int $limit = 3)
    {
        return Product::with(['images', 'sale'])
            ->latest()
            ->limit($limit)
            ->get();
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
        ])->toArray();
    }

    private function handleTranslations(Product $product, array $data): void
    {
        if (!isset($data['translations']) || !is_array($data['translations'])) {
            return;
        }

        foreach ($data['translations'] as $translation) {

            if (!isset($translation['locale'], $translation['description'])) {
                continue;
            }

            $product->translations()->updateOrCreate(
                ['locale' => $translation['locale']],
                ['description' => $translation['description']]
            );
        }
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

    private function applyFilters(Builder $query, array $filters): void
    {
        if (!empty($filters['brand_id'])) {
            $query->where('brand_id', $filters['brand_id']);
        }

        if (!empty($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }

        if (!empty($filters['min_price'])) {
            $query->where('price', '>=', $filters['min_price']);
        }

        if (!empty($filters['max_price'])) {
            $query->where('price', '<=', $filters['max_price']);
        }

        if (!empty($filters['search'])) {
            $query->where('name', 'like', '%' . $filters['search'] . '%');
        }

        if (!empty($filters['on_sale'])) {
            $query->whereHas('sale', function ($q) {
                $q->where('start_date', '<=', now())
                    ->where('end_date', '>=', now());
            });
        }

        if (!empty($filters['in_stock'])) {
            $query->where('quantity', '>', 0);
        }

        // Sorting — when no specific category is selected, order by category priority
        // so care products (shampoo, conditioner, etc.) appear first and
        // professional/chemical products (activators, hydrogen, hair color) appear last
        $sort = $filters['sort'] ?? 'name_asc';

        if (!isset($filters['category_id'])) {
            $priorityMap = Category::pluck('name', 'id');
            $highPriority   = ['Shampoo', 'Conditioner', 'Mask', 'Fluid', 'Lotion', 'Spray', 'Styling'];
            $mediumPriority = ['Sets', 'Color Mask', 'Filler', 'Other', 'Tester'];
            // low: Activator, Hydrogen Peroxide, Bleach and De Color
            // last: Hair Color

            $cases = [];
            $bindings = [];
            foreach ($priorityMap as $id => $name) {
                if (in_array($name, $highPriority, true)) {
                    $cases[] = "WHEN category_id = ? THEN 0";
                } elseif (in_array($name, $mediumPriority, true)) {
                    $cases[] = "WHEN category_id = ? THEN 1";
                } elseif ($name === 'Hair Color') {
                    $cases[] = "WHEN category_id = ? THEN 3";
                } else {
                    $cases[] = "WHEN category_id = ? THEN 2";
                }
                $bindings[] = $id;
            }

            if ($cases) {
                $query->orderByRaw('CASE ' . implode(' ', $cases) . ' ELSE 2 END ASC', $bindings);
            }
        }

        match ($sort) {
            'name_desc'  => $query->orderBy('name', 'desc'),
            'price_asc'  => $query->orderBy('price', 'asc'),
            'price_desc' => $query->orderBy('price', 'desc'),
            'newest'     => $query->orderBy('created_at', 'desc'),
            default      => $query->orderBy('name', 'asc'),
        };
    }
}
