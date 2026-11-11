<?php

namespace App\Services\ProductImport;

/**
 * Finds the header row of a spreadsheet and guesses which column holds which
 * product field. Knows common Macedonian, Albanian and English headings, so
 * a supplier stock list ("Шифра", "Опис", "Лагер 02.11.2026") or an Albanian
 * price list ("Kodi", "Emri", "Sasia", "Çmimi") maps without any setup.
 */
class ColumnMapper
{
    /** Fields an import can fill, in the order the admin sees them. */
    public const FIELDS = [
        'id' => 'Product ID',
        'manufacturer_code' => 'Article code',
        'name' => 'Name',
        'brand' => 'Brand',
        'category' => 'Category',
        'price' => 'Retail price',
        'stylist_price' => 'Business price',
        'cost_price' => 'Cost price',
        'quantity' => 'Stock',
        'visibility' => 'Visibility',
        'shade_code' => 'Shade code',
        'shade_name' => 'Shade name',
    ];

    /** Headings per field, already normalised (lower case, single spaces). */
    private const ALIASES = [
        'id' => ['id', 'product id', 'ид', 'id на производ', 'id produkti'],
        'manufacturer_code' => [
            'article code', 'шифра', 'шифра на артикл', 'шифра артикл', 'код', 'код на артикл', 'артикл код',
            'šifra', 'sifra', 'šifra artikla', 'sifra artikla', 'kod', 'kodi', 'kodi i artikullit', 'shifra',
            'code', 'item code', 'product code', 'article', 'article no', 'art no', 'sku', 'manufacturer code',
        ],
        'name' => [
            'name', 'назив', 'назив на артикл', 'опис', 'име', 'артикл', 'производ', 'naziv', 'opis', 'ime',
            'emri', 'emërtimi', 'emertimi', 'përshkrimi', 'pershkrimi', 'artikulli', 'produkti',
            'product', 'product name', 'item', 'item name', 'description',
        ],
        'brand' => ['brand', 'бренд', 'марка', 'производител', 'brend', 'brendi', 'marka', 'prodhuesi', 'manufacturer'],
        'category' => ['category', 'категорија', 'група', 'група на артикли', 'kategorija', 'grupa', 'kategoria', 'grupi', 'group'],
        'price' => [
            'retail price', 'цена', 'малопродажна цена', 'продажна цена', 'мпц', 'цена со ддв', 'cena', 'mpc',
            'çmimi', 'cmimi', 'çmimi me pakicë', 'cmimi me pakice', 'çmimi i shitjes', 'cmimi i shitjes',
            'price', 'sale price', 'selling price',
        ],
        'stylist_price' => [
            'business price', 'цена за салони', 'салонска цена', 'големопродажна цена', 'гпц', 'цена на големо',
            'vp cena', 'veleprodajna cena', 'çmimi me shumicë', 'cmimi me shumice', 'çmimi për sallone', 'cmimi per sallone',
            'wholesale price', 'salon price', 'stylist price', 'trade price',
        ],
        'cost_price' => [
            'cost price', 'набавна цена', 'набавна', 'nabavna cena', 'çmimi i blerjes', 'cmimi i blerjes', 'kosto',
            'cost', 'purchase price', 'buying price',
        ],
        'quantity' => [
            'stock', 'количина', 'залиха', 'лагер', 'состојба', 'на залиха', 'kolicina', 'količina', 'zaliha', 'stanje',
            'sasia', 'stoku', 'gjendja', 'sasia në stok', 'sasia ne stok', 'quantity', 'qty', 'on hand', 'inventory', 'in stock',
        ],
        'visibility' => ['visibility', 'видливост', 'vidlivost', 'dukshmëria', 'dukshmeria', 'shown to'],
        'shade_code' => ['shade code', 'шифра на нијанса', 'нијанса бр', 'kodi i nuancës', 'kodi i nuances', 'colour number', 'color number'],
        'shade_name' => ['shade name', 'нијанса', 'име на нијанса', 'nuanca', 'emri i nuancës', 'shade'],
    ];

    /**
     * Words that mean a column is a movement or a total, not the current value
     * (a stock report has "incoming quantity" next to "stock").
     */
    private const NOT_CURRENT = [
        'влезна', 'излезна', 'набавено', 'продадено', 'продажба', 'салдо', 'вкупно', 'просечна', 'сума',
        'ulaz', 'izlaz', 'hyrje', 'dalje', 'shitur', 'total', 'sum', 'sold', 'incoming', 'outgoing', 'average', 'value',
    ];

    public static function normalise(mixed $value): string
    {
        $text = mb_strtolower(trim((string) $value));
        $text = preg_replace('/[\s\x{00A0}]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/[:*#.\-_()\[\]]+$/u', '', $text) ?? $text);
    }

    /**
     * How well a heading fits a field: 3 = exact, 2 = starts with the heading
     * (e.g. "Лагер 02.11.2026"), 1 = contains it as a word, 0 = no match.
     */
    public static function score(string $heading, string $field): int
    {
        $heading = self::normalise($heading);
        if ($heading === '') {
            return 0;
        }

        $best = 0;
        foreach (self::ALIASES[$field] ?? [] as $alias) {
            if ($heading === $alias) {
                return 3;
            }
            $quoted = preg_quote($alias, '/');
            if (preg_match('/^' . $quoted . '(\s|$)/u', $heading)) {
                $best = max($best, 2);
            } elseif (preg_match('/(^|\s)' . $quoted . '(\s|$)/u', $heading)) {
                $best = max($best, 1);
            }
        }

        if ($best > 0 && $best < 3 && self::mentionsMovement($heading)) {
            return 0;
        }

        return $best;
    }

    private static function mentionsMovement(string $heading): bool
    {
        foreach (self::NOT_CURRENT as $word) {
            if (preg_match('/(^|\s)' . preg_quote($word, '/') . '/u', $heading)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The row (0-based) among the first 30 with the most recognised headings.
     *
     * @param list<list<mixed>> $rows
     */
    public static function detectHeaderRow(array $rows): int
    {
        $bestRow = 0;
        $bestCount = 0;

        foreach (array_slice($rows, 0, 30, true) as $index => $row) {
            $count = 0;
            foreach ($row as $cell) {
                foreach (array_keys(self::FIELDS) as $field) {
                    if (self::score((string) $cell, $field) >= 2) {
                        $count++;
                        break;
                    }
                }
            }
            if ($count > $bestCount) {
                $bestCount = $count;
                $bestRow = $index;
            }
        }

        return $bestRow;
    }

    /**
     * Suggested field => column index. Each column and field is used once;
     * the strongest matches win. When two columns fit equally (two "Лагер"
     * columns with different dates), the one with the latest date wins, then
     * the rightmost one.
     *
     * @param list<mixed> $headings
     * @return array<string, int>
     */
    public static function suggest(array $headings): array
    {
        $candidates = [];
        foreach ($headings as $column => $heading) {
            foreach (array_keys(self::FIELDS) as $field) {
                $score = self::score((string) $heading, $field);
                if ($score > 0) {
                    $candidates[] = [
                        'field' => $field,
                        'column' => (int) $column,
                        'score' => $score,
                        'date' => self::latestDate((string) $heading),
                    ];
                }
            }
        }

        usort($candidates, fn (array $a, array $b) => [$b['score'], $b['date'], $b['column']] <=> [$a['score'], $a['date'], $a['column']]);

        $mapping = [];
        $usedColumns = [];
        foreach ($candidates as $candidate) {
            if (isset($mapping[$candidate['field']]) || isset($usedColumns[$candidate['column']])) {
                continue;
            }
            $mapping[$candidate['field']] = $candidate['column'];
            $usedColumns[$candidate['column']] = true;
        }

        // Keep the admin's field order.
        return array_filter(
            array_replace(array_fill_keys(array_keys(self::FIELDS), null), $mapping),
            fn ($column) => $column !== null
        );
    }

    /** Latest dd.mm.yyyy (or yyyy-mm-dd) date in a heading as yyyymmdd, 0 when none. */
    private static function latestDate(string $heading): int
    {
        $dates = [0];
        if (preg_match_all('/(\d{1,2})[.\/](\d{1,2})[.\/](\d{4})/', $heading, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $dates[] = (int) sprintf('%04d%02d%02d', $match[3], $match[2], $match[1]);
            }
        }
        if (preg_match_all('/(\d{4})-(\d{1,2})-(\d{1,2})/', $heading, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $dates[] = (int) sprintf('%04d%02d%02d', $match[1], $match[2], $match[3]);
            }
        }

        return max($dates);
    }
}
