<?php

namespace App\Services\Documents;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * The products on a price list: only what that audience can really buy
 * (visible to it, priced, out of review), grouped brand -> category. Shades of
 * one colour line become a single row ("42 shades, from 420").
 */
final class PriceListData
{
    public const AUDIENCES = ['retail', 'business'];

    private static function base(string $audience): Builder
    {
        $priceColumn = $audience === 'business' ? 'stylist_price' : 'price';

        return Product::query()
            ->readyForSale()
            ->where($audience === 'business' ? 'visible_to_stylists' : 'visible_to_customers', true)
            ->where($priceColumn, '>', 0);
    }

    /** Brands and categories that have something to list, for the options form. */
    public static function options(): array
    {
        $ids = fn (string $column) => self::base('retail')->distinct()->pluck($column)
            ->merge(self::base('business')->distinct()->pluck($column))->filter()->unique()->values();

        return [
            'brands' => Brand::whereIn('id', $ids('brand_id'))->orderBy('name')->get(['id', 'name'])->all(),
            'categories' => Category::whereIn('id', $ids('category_id'))->orderBy('name')->get(['id', 'name'])->all(),
            'audiences' => self::AUDIENCES,
            'locales' => DocumentText::LOCALES,
        ];
    }

    /**
     * @param array{audience: string, brand_ids?: int[], category_ids?: int[], in_stock_only?: bool} $options
     * @return array{groups: array<int, array{brand: string, categories: array<int, array{category: string, rows: array}>}>, count: int}
     */
    public static function build(array $options, string $locale): array
    {
        $audience = $options['audience'];
        $priceColumn = $audience === 'business' ? 'stylist_price' : 'price';

        $products = self::base($audience)
            ->when(!empty($options['brand_ids']), fn (Builder $q) => $q->whereIn('brand_id', $options['brand_ids']))
            ->when(!empty($options['category_ids']), fn (Builder $q) => $q->whereIn('category_id', $options['category_ids']))
            ->when(!empty($options['in_stock_only']), fn (Builder $q) => $q->where('quantity', '>', 0))
            ->with(['brand:id,name', 'category:id,name', 'productFamily:id,name'])
            ->orderBy('name')
            ->get();

        $tree = [];
        $count = 0;
        foreach ($products as $product) {
            $brand = $product->brand?->name ?: __('documents.other_brand', [], $locale);
            $category = $product->category?->name ?: __('documents.other_category', [], $locale);
            $price = (float) $product->{$priceColumn};
            $family = $product->productFamily;
            $count++;

            if ($family) {
                $key = 'family-' . $family->id;
                $row = $tree[$brand][$category][$key] ?? ['code' => '', 'name' => $family->name, 'price' => $price, 'shades' => 0, 'from' => false];
                $row['from'] = $row['from'] || ($row['shades'] > 0 && $row['price'] != $price);
                $row['price'] = min($row['price'], $price);
                $row['shades']++;
                $tree[$brand][$category][$key] = $row;

                continue;
            }

            $tree[$brand][$category]['product-' . $product->id] = [
                'code' => (string) ($product->manufacturer_code ?? ''),
                'name' => $product->name,
                'price' => $price,
                'shades' => 0,
                'from' => false,
            ];
        }

        ksort($tree, SORT_NATURAL | SORT_FLAG_CASE);
        $groups = [];
        foreach ($tree as $brand => $categories) {
            ksort($categories, SORT_NATURAL | SORT_FLAG_CASE);
            $groups[] = [
                'brand' => $brand,
                'categories' => array_map(
                    fn (string $category, array $rows) => ['category' => $category, 'rows' => array_values($rows)],
                    array_keys($categories),
                    $categories,
                ),
            ];
        }

        return ['groups' => $groups, 'count' => $count];
    }
}
