<?php

namespace App\Services\ProductImport;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Compares a spreadsheet with the catalogue and applies the differences.
 *
 * Rows are matched by Product ID, then article code, then exact name. Empty
 * cells never change anything. Nothing is written until apply(); apply()
 * keeps the old values so the import can be undone.
 */
class ProductImporter
{
    public const PREVIEW_ROWS = 300;
    private const NUMBERS = ['price', 'stylist_price', 'cost_price', 'quantity'];
    private const DIR = 'imports';

    /** Keeps the uploaded rows for the apply step. @return string token */
    public function store(array $sheet, string $filename): string
    {
        $token = (string) Str::uuid();
        Storage::disk('local')->put(self::DIR . "/{$token}.json", json_encode(['filename' => $filename, 'rows' => $sheet['rows']], JSON_UNESCAPED_UNICODE));

        return $token;
    }

    public function load(string $token): array
    {
        $path = self::DIR . "/{$token}.json";
        if (!Str::isUuid($token) || !Storage::disk('local')->exists($path)) {
            throw new RuntimeException('This upload has expired. Please choose the file again.');
        }

        return json_decode(Storage::disk('local')->get($path), true);
    }

    /**
     * @param list<list<mixed>> $rows
     * @param array<string, int|null>|null $mapping field => column, null to guess
     * @param array{create_new?: bool, fields?: list<string>} $options
     */
    public function plan(array $rows, ?array $mapping = null, array $options = []): array
    {
        $headerRow = ColumnMapper::detectHeaderRow($rows);
        $headings = array_map(fn ($cell) => trim((string) $cell), $rows[$headerRow] ?? []);
        $mapping = $this->cleanMapping($mapping ?? ColumnMapper::suggest($headings), count($headings));
        $only = $options['fields'] ?? null;
        $createNew = (bool) ($options['create_new'] ?? false);

        if (!array_intersect(array_keys($mapping), ['id', 'manufacturer_code', 'name'])) {
            return $this->result($headerRow, $headings, $mapping, [], ['A column with the product ID, the article code or the name is needed to find the products.']);
        }

        $products = Product::query()->with(['brand:id,name', 'category:id,name'])->get();
        $byId = $products->keyBy('id');
        $byCode = $products->filter(fn (Product $p) => ValueParser::key($p->manufacturer_code) !== '')->groupBy(fn (Product $p) => ValueParser::key($p->manufacturer_code));
        $byName = $products->groupBy(fn (Product $p) => ValueParser::key($p->name));
        $brands = Brand::pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [ValueParser::key($name) => $id]);
        $categories = Category::pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [ValueParser::key($name) => $id]);

        $cell = fn (array $row, string $field) => isset($mapping[$field]) ? ($row[$mapping[$field]] ?? null) : null;
        $wanted = fn (string $field) => isset($mapping[$field]) && ($only === null || in_array($field, $only, true));

        $lines = [];
        $seen = [];
        foreach (array_slice($rows, $headerRow + 1, null, true) as $index => $row) {
            if (!array_filter($row, fn ($value) => trim((string) $value) !== '')) {
                continue;
            }

            $line = ['line' => $index + 1, 'action' => 'skip', 'productId' => null, 'name' => ValueParser::text($cell($row, 'name')), 'code' => ValueParser::text($cell($row, 'manufacturer_code')), 'changes' => [], 'problems' => [], 'matchedBy' => null];

            // Find the product.
            $product = null;
            $id = ValueParser::number($cell($row, 'id'));
            $codeKey = ValueParser::key($cell($row, 'manufacturer_code'));
            $nameKey = ValueParser::key($cell($row, 'name'));
            if (is_float($id) && $id > 0) {
                $product = $byId->get((int) $id);
                $line['matchedBy'] = 'id';
                if (!$product) {
                    $line['problems'][] = 'No product has ID ' . (int) $id . '.';
                }
            } elseif ($codeKey !== '' && $byCode->has($codeKey)) {
                $line['matchedBy'] = 'code';
                if ($byCode[$codeKey]->count() > 1) {
                    $line['problems'][] = $byCode[$codeKey]->count() . ' products share this article code.';
                } else {
                    $product = $byCode[$codeKey]->first();
                }
            } elseif ($nameKey !== '' && $byName->has($nameKey)) {
                $line['matchedBy'] = 'name';
                if ($byName[$nameKey]->count() > 1) {
                    $line['problems'][] = $byName[$nameKey]->count() . ' products have this exact name; add the product ID or article code.';
                } else {
                    $product = $byName[$nameKey]->first();
                }
            }

            // Read the values.
            $values = [];
            foreach (self::NUMBERS as $field) {
                if (!$wanted($field)) {
                    continue;
                }
                $number = ValueParser::number($cell($row, $field));
                if ($number === false || (is_float($number) && $number < 0)) {
                    $line['problems'][] = ColumnMapper::FIELDS[$field] . ' "' . trim((string) $cell($row, $field)) . '" is not a valid number.';
                } elseif ($number !== null) {
                    $values[$field] = $field === 'quantity' ? (int) round($number) : (float) round($number);
                }
            }
            foreach (['name', 'manufacturer_code'] as $field) {
                $text = ValueParser::text($cell($row, $field));
                if ($wanted($field) && $text !== null) {
                    $values[$field] = $text;
                }
            }
            foreach (['brand' => [$brands, 'brand_id'], 'category' => [$categories, 'category_id']] as $field => [$lookup, $column]) {
                $text = ValueParser::text($cell($row, $field));
                if (!$wanted($field) || $text === null) {
                    continue;
                }
                $found = $lookup[ValueParser::key($text)] ?? null;
                if ($found === null) {
                    $line['problems'][] = ucfirst($field) . ' "' . $text . '" does not exist yet. Add it first or fix the spelling.';
                } else {
                    $values[$column] = (int) $found;
                    $values[$field . '_label'] = $text;
                }
            }
            if ($wanted('visibility')) {
                $audiences = ValueParser::visibility($cell($row, 'visibility'));
                if ($audiences === false) {
                    $line['problems'][] = 'Visibility "' . trim((string) $cell($row, 'visibility')) . '" is not understood (use: everyone, guests, customers, stylists, hidden).';
                } elseif ($audiences !== null) {
                    foreach (Product::AUDIENCES as $audience) {
                        $values['visible_to_' . $audience] = in_array($audience, $audiences, true);
                    }
                }
            }

            if ($product) {
                $line['productId'] = $product->id;
                $line['name'] = $product->name;
                if (isset($seen[$product->id])) {
                    $line['problems'][] = 'Same product as line ' . $seen[$product->id] . '; only the first one is used.';
                } else {
                    $seen[$product->id] = $line['line'];
                    $line['changes'] = $this->changes($product, $values);
                    $line['action'] = $line['problems'] ? 'skip' : ($line['changes'] ? 'update' : 'same');
                }
            } elseif (!$line['problems']) {
                if (($values['name'] ?? '') === '') {
                    $line['problems'][] = 'Not found in the catalogue and the row has no name.';
                } elseif (!$createNew) {
                    $line['action'] = 'new';
                } else {
                    $line['action'] = 'create';
                    $line['changes'] = $this->changes(null, $values);
                }
                $line['name'] = $values['name'] ?? $line['name'];
            }

            $line['values'] = array_diff_key($values, ['brand_label' => 1, 'category_label' => 1]);
            $lines[] = $line;
        }

        return $this->result($headerRow, $headings, $mapping, $lines, []);
    }

    /** Applies a plan. @return array{updated: int, created: int, undoable: bool} */
    public function apply(string $token, array $plan): array
    {
        $undo = ['updated' => [], 'created' => []];

        DB::transaction(function () use ($plan, &$undo) {
            foreach ($plan['lines'] as $line) {
                if ($line['action'] === 'update') {
                    $product = Product::find($line['productId']);
                    if (!$product) {
                        continue;
                    }
                    $fields = array_keys($line['changes']);
                    $undo['updated'][$product->id] = array_map(fn ($value) => is_bool($value) ? $value : (string) $value, array_intersect_key($product->getAttributes(), array_flip($fields)));
                    $product->forceFill(array_intersect_key($line['values'], array_flip($fields)))->save();
                } elseif ($line['action'] === 'create') {
                    $product = new Product();
                    $product->forceFill([
                        'quantity' => 0,
                        'price' => 0,
                        ...$line['values'],
                    ])->save();
                    $undo['created'][] = $product->id;
                }
            }
        });

        Storage::disk('local')->put(self::DIR . "/{$token}-undo.json", json_encode($undo));

        return ['updated' => count($undo['updated']), 'created' => count($undo['created']), 'undoable' => true];
    }

    /** Puts back what apply() changed and removes the products it created. */
    public function undo(string $token): array
    {
        $path = self::DIR . "/{$token}-undo.json";
        if (!Str::isUuid($token) || !Storage::disk('local')->exists($path)) {
            throw new RuntimeException('This import can no longer be undone.');
        }
        $undo = json_decode(Storage::disk('local')->get($path), true);
        $kept = 0;

        DB::transaction(function () use ($undo, &$kept) {
            foreach ($undo['updated'] as $id => $values) {
                Product::whereKey($id)->first()?->forceFill($values)->save();
            }
            foreach ($undo['created'] as $id) {
                $product = Product::find($id);
                if ($product && DB::table('items')->where('product_id', $id)->exists()) {
                    $kept++; // already ordered: keep it

                    continue;
                }
                $product?->delete();
            }
        });
        Storage::disk('local')->delete($path);

        return ['restored' => count($undo['updated']), 'removed' => count($undo['created']) - $kept, 'kept' => $kept];
    }

    /** @return array<string, array{from: mixed, to: mixed}> */
    private function changes(?Product $product, array $values): array
    {
        $changes = [];
        foreach ($values as $field => $to) {
            if (str_ends_with($field, '_label')) {
                continue;
            }
            $from = $product?->getAttribute($field);
            $same = match (true) {
                $product === null => false,
                is_bool($to) => (bool) $from === $to,
                is_int($to), is_float($to) => $from !== null && (float) $from === (float) $to,
                default => trim((string) $from) === (string) $to,
            };
            if ($same) {
                continue;
            }
            $shown = match ($field) {
                'brand_id' => [$product?->brand?->name, $values['brand_label'] ?? $to],
                'category_id' => [$product?->category?->name, $values['category_label'] ?? $to],
                default => [is_numeric($from) && !is_bool($to) && !is_string($to) ? $from + 0 : $from, $to],
            };
            $changes[$field] = ['from' => $shown[0], 'to' => $shown[1]];
        }

        return $changes;
    }

    private function cleanMapping(array $mapping, int $width): array
    {
        $clean = [];
        foreach ($mapping as $field => $column) {
            if (isset(ColumnMapper::FIELDS[$field]) && is_numeric($column) && (int) $column >= 0 && (int) $column < $width) {
                $clean[$field] = (int) $column;
            }
        }

        return $clean;
    }

    private function result(int $headerRow, array $headings, array $mapping, array $lines, array $errors): array
    {
        $count = fn (string $action) => count(array_filter($lines, fn (array $line) => $line['action'] === $action));

        return [
            'headerRow' => $headerRow + 1,
            'headings' => $headings,
            'mapping' => $mapping,
            'fields' => ColumnMapper::FIELDS,
            'errors' => $errors,
            'summary' => [
                'rows' => count($lines),
                'update' => $count('update'),
                'create' => $count('create'),
                'new' => $count('new'),
                'same' => $count('same'),
                'problems' => count(array_filter($lines, fn (array $line) => $line['problems'] !== [])),
            ],
            'lines' => $lines,
        ];
    }
}
