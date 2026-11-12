<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Image;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Read-only catalogue check for the admin "Fix products" page: every product
 * with something to fix, most important first. It never changes data; fixes
 * (photos, categories, merges) are done by a person.
 */
class ProductProblemController extends Controller
{
    /** Problem groups in the order the shop owner works through them. */
    public const GROUPS = [
        'photo' => 'Photo',
        'description' => 'Description',
        'category' => 'Category / brand',
        'duplicate' => 'Duplicate',
        'price' => 'Price',
        'size' => 'Size',
        'stock' => 'Stock',
        'hidden' => 'Hidden',
    ];

    /** Words in a product name and the categories they fit. */
    private const NAME_CATEGORIES = [
        'shampoo' => ['Shampoo', 'Sets'],
        'conditioner' => ['Conditioner', 'Sets'],
        'mask' => ['Mask', 'Color Mask', 'Sets'],
        'maschera' => ['Mask', 'Color Mask', 'Sets'],
        'spray' => ['Spray', 'Styling', 'Sets'],
        'activator' => ['Activator', 'Hydrogen Peroxide'],
        'developer' => ['Activator', 'Hydrogen Peroxide'],
        'peroxide' => ['Activator', 'Hydrogen Peroxide'],
        'bleach' => ['Bleach and De Color'],
        'lotion' => ['Lotion', 'Fluid', 'Sets'],
        'filler' => ['Filler', 'Sets'],
    ];

    public function index(): JsonResponse
    {
        $products = Product::with(['brand', 'category', 'images', 'translations', 'sale'])
            ->withCount('items')
            ->orderBy('id')
            ->get();

        $duplicates = $this->duplicateIndex($products);
        $rows = $products->map(fn (Product $product) => $this->row($product, $duplicates))
            ->filter(fn (array $row) => $row['problems'] !== [])
            ->sort(fn (array $a, array $b) => [$a['rank'], $b['live'], $b['quantity'], $b['orders'], $a['id']]
                <=> [$b['rank'], $a['live'], $a['quantity'], $a['orders'], $b['id']])
            ->values();

        $summary = collect(self::GROUPS)->map(fn ($label, $group) => [
            'group' => $group,
            'label' => $label,
            'products' => $rows->filter(fn ($row) => in_array($group, $row['groups'], true))->count(),
        ])->values();

        return response()->json([
            'total_products' => $products->count(),
            'products_with_problems' => $rows->count(),
            'summary' => $summary,
            'products' => $rows,
        ]);
    }

    private function row(Product $product, array $duplicates): array
    {
        $problems = [];
        $add = function (string $group, string $code, string $label, ?string $detail = null) use (&$problems) {
            $problems[] = compact('group', 'code', 'label', 'detail');
        };

        $realImages = $product->images->reject(fn (Image $image) => $image->isPlaceholder());
        $visible = $product->visible_to_guests || $product->visible_to_customers || $product->visible_to_stylists;
        $qty = (int) $product->quantity;
        $price = (float) $product->price;
        $stylistPrice = (float) $product->stylist_price;

        // 1. Photos
        if ($realImages->isEmpty()) {
            $add('photo', 'no_photo', 'No real photo', $product->images->isEmpty() ? 'No image at all' : 'Only a placeholder image');
        } elseif ($realImages->every(fn (Image $image) => $image->isFromCatalogAudit())) {
            $add('photo', 'auto_photo_only', 'Only an auto-matched photo', 'Check it shows this exact product and size');
        } elseif ($realImages->contains(fn (Image $image) => $image->review_status === 'needs_review')) {
            $add('photo', 'photo_needs_review', 'Photo waits for review', $realImages->where('review_status', 'needs_review')->count() . ' image(s) marked needs review');
        }

        // 2. Descriptions
        $texts = [];
        foreach (['en' => 'English', 'mk' => 'Macedonian', 'shq' => 'Albanian'] as $locale => $language) {
            $text = trim(strip_tags((string) $product->translations->firstWhere('locale', $locale)?->description));
            $texts[$locale] = preg_replace('/\s+/u', ' ', $text);
            if ($text === '') {
                $add('description', "no_description_$locale", "No $language description");
            }
        }
        foreach ([['en', 'mk'], ['en', 'shq'], ['mk', 'shq']] as [$a, $b]) {
            if ($texts[$a] !== '' && $texts[$a] === $texts[$b]) {
                $add('description', "untranslated_{$a}_{$b}", 'Description not translated', strtoupper($a) . ' and ' . strtoupper($b) . ' texts are identical');
            }
        }
        if (mb_strlen($texts['mk']) > 30 && !preg_match('/\p{Cyrillic}/u', $texts['mk']) && $texts['mk'] !== $texts['en']) {
            $add('description', 'mk_not_cyrillic', 'Macedonian text is not in Cyrillic', 'Probably not translated');
        }

        // 3. Category / brand
        $brand = $product->brand?->name;
        $category = $product->category?->name;
        if (!$brand || in_array(mb_strtolower($brand), Product::REVIEW_BRAND_NAMES, true)) {
            $add('category', 'no_brand', 'No real brand', $brand ?: 'empty');
        } elseif (mb_strtolower($brand) === 'other') {
            $add('category', 'brand_other', 'Brand is "Other"');
        }
        if (!$category || in_array(mb_strtolower($category), Product::REVIEW_CATEGORY_NAMES, true)) {
            $add('category', 'no_category', 'No real category', $category ?: 'empty');
        } elseif (mb_strtolower($category) === 'other') {
            $add('category', 'category_other', 'Category is "Other"');
        } else {
            $fits = [];
            foreach (self::NAME_CATEGORIES as $word => $categories) {
                if (preg_match('/\b' . $word . '\b/i', $product->name)) {
                    $fits = [...$fits, ...$categories];
                }
            }
            if (preg_match('/\btester\b/i', $product->name)) {
                $fits[] = 'Tester';
            }
            if ($fits !== [] && !in_array($category, $fits, true)) {
                $add('category', 'category_mismatch', 'Name does not match category', "Category is $category; name suggests " . $fits[0]);
            }
        }

        // 4. Duplicates
        foreach ($duplicates[$product->id] ?? [] as [$code, $label, $ids]) {
            $add('duplicate', $code, $label, 'Same as #' . implode(', #', $ids));
        }

        // 5. Prices
        if ($price <= 0) {
            $add('price', 'no_price', 'No shop price', $product->stylist_only ? 'Salon-only product' : null);
        }
        if ($stylistPrice <= 0) {
            $add('price', 'no_stylist_price', 'No salon price');
        }
        if ($price > 0 && $stylistPrice > 0 && $stylistPrice >= $price) {
            $add('price', 'stylist_not_cheaper', 'Salon price not below shop price', "Salon $stylistPrice ≥ shop $price");
        }
        if ($product->cost_price !== null && (float) $product->cost_price > 0 && $price > 0 && (float) $product->cost_price > $price) {
            $add('price', 'cost_above_price', 'Cost above shop price', "Cost {$product->cost_price} > {$price}");
        }
        if ($product->compare_at_price !== null && (float) $product->compare_at_price > 0 && $price > 0 && (float) $product->compare_at_price <= $price) {
            $add('price', 'compare_not_higher', '"Was" price not higher than price', "Was {$product->compare_at_price}, now {$price}");
        }
        if ($product->sale && (float) $product->sale->sale_price > 0 && $price > 0 && (float) $product->sale->sale_price >= $price) {
            $add('price', 'sale_not_lower', 'Sale price not below price', "Sale {$product->sale->sale_price} ≥ {$price}");
        }

        // 6. Size written in the name vs. the size fields
        $nameSize = $this->size($product->name);
        if ($nameSize) {
            $fields = [
                'size' => trim(($product->size_value ?? '') . ' ' . ($product->size_unit ?? '')),
                'package label' => (string) $product->package_label,
                'variant' => (string) $product->variant_name,
                'English description' => $this->onlySize($texts['en']),
            ];
            foreach ($fields as $field => $value) {
                $fieldSize = $this->size($value) ?? ($field === 'size' && is_numeric($product->size_value) ? $this->size($value . ($product->size_unit ?: 'ml')) : null);
                if ($fieldSize && $fieldSize !== $nameSize) {
                    $add('size', 'size_mismatch', 'Size in name differs from ' . $field, "Name says $nameSize, $field says $value");
                    break;
                }
            }
        }

        // 7. Stock
        if ($qty < 0) {
            $add('stock', 'negative_stock', 'Negative stock', (string) $qty);
        } elseif ($qty === 0 && $visible) {
            $add('stock', 'visible_no_stock', 'Shown but out of stock');
        }
        if ($qty > 0 && !$visible) {
            $add('stock', 'stock_but_hidden', 'In stock but hidden', "$qty in stock");
        }

        // 8. Hidden from shoppers
        if (!$visible) {
            $add('hidden', 'hidden_all', 'Hidden from everyone', 'Guests, customers and salons all switched off');
        } elseif ($product->stylist_only || (!$product->visible_to_guests && !$product->visible_to_customers)) {
            $add('hidden', 'salon_only', 'Salons only', 'Shoppers cannot see it');
        }
        if ($visible && $product->isInReview()) {
            $add('hidden', 'in_review', 'Kept back for review', 'Reason: ' . implode(', ', array_map(fn ($r) => str_replace('_', ' ', $r), $product->reviewReasons())));
        }

        $groups = array_values(array_unique(array_column($problems, 'group')));
        $ranks = array_flip(array_keys(self::GROUPS));
        $primary = $product->primaryImage();

        return [
            'id' => $product->id,
            'name' => $product->name,
            'brand' => $brand,
            'category' => $category,
            'size' => trim(($product->size_value ?? '') . ' ' . ($product->size_unit ?? '')) ?: $product->package_label,
            'manufacturer_code' => $product->manufacturer_code,
            'price' => $product->price,
            'stylist_price' => $product->stylist_price,
            'quantity' => $qty,
            'visible' => (bool) $visible,
            'live' => $visible && $qty > 0,
            'orders' => (int) $product->items_count,
            'image_count' => $realImages->count(),
            'thumbnail' => $primary ? ($primary->url ?: $primary->publicUrl()) : null,
            'groups' => $groups,
            'rank' => $groups === [] ? 99 : min(array_map(fn ($g) => $ranks[$g], $groups)),
            'problems' => $problems,
        ];
    }

    /** The size a text mentions, when it mentions exactly one. */
    private function onlySize(string $text): string
    {
        preg_match_all('/\d+(?:[.,]\d+)?\s*(?:ml|lt|l|gr|g|kg)\b/i', $text, $m);
        $sizes = array_unique(array_map(fn ($s) => $this->size($s), $m[0]));

        return count($sizes) === 1 ? $m[0][0] : '';
    }

    /** "1000 ml", "1L", "250gr" → "1000ml" / "250g"; null when no size. */
    private function size(string $text): ?string
    {
        if (!preg_match('/(\d+(?:[.,]\d+)?)\s*(ml|lt|l|gr|g|kg)\b/i', $text, $m)) {
            return null;
        }
        $value = (float) str_replace(',', '.', $m[1]);
        $unit = strtolower($m[2]);
        [$value, $unit] = match ($unit) {
            'l', 'lt' => [$value * 1000, 'ml'],
            'kg' => [$value * 1000, 'g'],
            'gr' => [$value, 'g'],
            default => [$value, $unit],
        };

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . $unit;
    }

    /** @return array<int, list<array{0:string,1:string,2:list<string>}>> */
    private function duplicateIndex(Collection $products): array
    {
        $index = [];
        $checks = [
            ['same_name', 'Same name as another product of this brand', fn (Product $p) => $p->brand_id . ':' . Str::of($p->name)->lower()->replaceMatches('/[^\p{L}\p{N}]+/u', '')->value()],
            ['same_code', 'Same manufacturer code', fn (Product $p) => mb_strtolower(trim((string) $p->manufacturer_code))],
            ['same_ean', 'Same barcode (EAN)', fn (Product $p) => trim((string) $p->ean)],
            ['same_sku', 'Same SKU', fn (Product $p) => mb_strtolower(trim((string) $p->sku))],
        ];
        foreach ($checks as [$code, $label, $key]) {
            $products->groupBy($key)->each(function (Collection $group, $value) use (&$index, $code, $label) {
                if ($value === '' || str_ends_with((string) $value, ':') || $group->count() < 2) {
                    return;
                }
                foreach ($group as $product) {
                    $others = $group->reject(fn ($other) => $other->id === $product->id)
                        ->map(fn ($other) => $other->id . ' (' . ($other->category?->name ?? 'no category') . ')')->values()->all();
                    $index[$product->id][] = [$code, $label, $others];
                }
            });
        }

        return $index;
    }
}
