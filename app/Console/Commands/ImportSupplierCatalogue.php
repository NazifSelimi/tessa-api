<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ImportSupplierCatalogue extends Command
{
    protected $signature = 'catalog:import-supplier
        {source : CSV with code,description,retail,wholesale columns}
        {--apply : Write new rows; without this option the command only reports}
        {--refresh-imported : Correct the Latin display names of rows imported by this command}
        {--batch= : Stable batch label stored with imported placeholder media}';

    protected $description = 'Import a supplier price list as reviewable catalogue products without changing existing products';

    private const FALLBACK_BRAND = 'Supplier catalogue';
    private const FALLBACK_CATEGORY = 'Catalogue review';

    public function handle(): int
    {
        $source = (string) $this->argument('source');
        if (! is_file($source)) {
            $this->error("CSV not found: {$source}");
            return self::FAILURE;
        }

        foreach (['manufacturer_code', 'is_active'] as $column) {
            if (! Schema::hasColumn('products', $column)) {
                $this->error("The product identity migration is required (missing products.{$column}).");
                return self::FAILURE;
            }
        }

        $rows = $this->readCsv($source);
        $batch = (string) ($this->option('batch') ?: 'supplier-pdf-' . now()->format('Ymd-His'));
        $summary = ['rows' => count($rows), 'created' => 0, 'existing' => 0, 'invalid' => 0];

        if (! $this->option('apply')) {
            foreach ($rows as $row) {
                if (! $this->validRow($row)) {
                    $summary['invalid']++;
                    continue;
                }
                Product::where('manufacturer_code', $row['code'])->exists()
                    ? $summary['existing']++
                    : $summary['created']++;
            }
            $this->table(['CSV rows', 'Would create', 'Already imported', 'Invalid'], [[$summary['rows'], $summary['created'], $summary['existing'], $summary['invalid']]]);
            $this->warn('Dry run only. Re-run with --apply after reviewing this summary.');
            return self::SUCCESS;
        }

        $placeholder = $this->ensurePlaceholder();
        DB::transaction(function () use ($rows, $batch, $placeholder, &$summary) {
            foreach ($rows as $row) {
                if (! $this->validRow($row)) {
                    $summary['invalid']++;
                    continue;
                }

                $existing = Product::query()->where('manufacturer_code', $row['code'])->lockForUpdate()->first();
                if ($existing) {
                    if ($this->option('refresh-imported') && $this->isSupplierImport($existing)) {
                        $name = $this->latinName($row['description']);
                        [$brand, $category] = $this->resolveGrouping($name);
                        $existing->update([
                            'name' => $name,
                            'brand_id' => $brand->id,
                            'category_id' => $category->id,
                        ]);
                    }
                    $summary['existing']++;
                    continue;
                }

                $name = $this->latinName($row['description']);
                [$brand, $category] = $this->resolveGrouping($name);
                $product = Product::query()->create([
                    'name' => $name,
                    'brand_id' => $brand->id,
                    'category_id' => $category->id,
                    // Visible on staging, but not orderable until the owner supplies stock.
                    'quantity' => 0,
                    'price' => $this->price($row['retail']),
                    // One PDF test row has no wholesale price. Retain its printed retail
                    // price rather than inventing a discount; the metadata keeps it visible.
                    'stylist_price' => $this->price($row['wholesale']) ?? $this->price($row['retail']),
                    'stylist_only' => false,
                ]);
                $product->forceFill([
                    'manufacturer_code' => $row['code'],
                    'is_active' => true,
                ])->save();

                $product->images()->create([
                    'name' => $placeholder,
                    'alt' => $name . ' - image pending review',
                    'sort_order' => 0,
                    'variant' => 'card',
                    'background' => 'generated',
                    'review_status' => 'needs_review',
                    'metadata' => [
                        'placeholder' => true,
                        'source' => 'supplier PDF price list',
                        'supplier_code' => $row['code'],
                        'import_batch' => $batch,
                        'original_description' => $row['description'],
                        'wholesale_missing' => $this->price($row['wholesale']) === null,
                    ],
                ]);
                $summary['created']++;
            }
        });

        $this->table(['CSV rows', 'Created', 'Existing skipped', 'Invalid skipped'], [[$summary['rows'], $summary['created'], $summary['existing'], $summary['invalid']]]);
        $this->info('New rows are visible in staging and in admin, with generated review placeholders. Existing products were not altered.');
        return self::SUCCESS;
    }

    private function readCsv(string $source): array
    {
        $handle = fopen($source, 'rb');
        $header = fgetcsv($handle);
        if ($header === false) {
            throw new RuntimeException('CSV is empty.');
        }
        $header = array_map(fn ($value) => strtolower(trim((string) $value)), $header);
        foreach (['code', 'description', 'retail', 'wholesale'] as $required) {
            if (! in_array($required, $header, true)) {
                throw new RuntimeException("CSV must include {$required}.");
            }
        }
        $rows = [];
        while (($values = fgetcsv($handle)) !== false) {
            if (count($values) !== count($header)) continue;
            $rows[] = array_combine($header, $values);
        }
        fclose($handle);
        return $rows;
    }

    private function validRow(array $row): bool
    {
        return trim((string) $row['code']) !== ''
            && trim((string) $row['description']) !== ''
            && $this->price($row['retail']) !== null;
    }

    private function price(?string $value): ?float
    {
        $normalized = str_replace(',', '', trim((string) $value));
        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function resolveGrouping(string $name): array
    {
        $brandName = match (true) {
            str_contains(strtolower($name), 'fanola') || preg_match('/\b(no yellow|no orange|no red|fiber fix|fantouch|botugen|nutri|vitamins|wonder|caleido)\b/i', $name) => 'Fanola',
            preg_match('/\b(rr|real|star|oro puro|oro therapy|serical)\b/i', $name) => 'Rr Line',
            default => self::FALLBACK_BRAND,
        };
        $categoryName = match (true) {
            preg_match('/\b(shampoo|shampon|sampon)\b/i', $name) => 'Shampoo',
            preg_match('/\b(mask|maska)\b/i', $name) => 'Mask',
            preg_match('/\b(toner|color|farba|boja)\b/i', $name) => 'Hair Color',
            preg_match('/\b(activator|aktivator|oksidant|hydrogen|vol)\b/i', $name) => 'Activator',
            preg_match('/\b(bleach|blansh|decolor|dekolor)\b/i', $name) => 'Bleach and De Color',
            preg_match('/\b(spray|sprei|lak|mousse|gel|paste|vosok|wax)\b/i', $name) => 'Styling',
            preg_match('/tester/i', $name) => 'Tester',
            preg_match('/\b(conditioner|kondicioner|balsam)\b/i', $name) => 'Conditioner',
            preg_match('/\b(lotion|losion|ampul)\b/i', $name) => 'Lotion',
            preg_match('/\b(filler|filer)\b/i', $name) => 'Filler',
            preg_match('/\b(fluid)\b/i', $name) => 'Fluid',
            preg_match('/\b(set|komplet)\b/i', $name) => 'Sets',
            default => self::FALLBACK_CATEGORY,
        };
        return [
            Brand::firstOrCreate(['name' => $brandName]),
            Category::firstOrCreate(['name' => $categoryName]),
        ];
    }

    private function latinName(string $value): string
    {
        $map = ['а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'ѓ' => 'gj', 'е' => 'e', 'ж' => 'zh', 'з' => 'z', 'ѕ' => 'dz', 'и' => 'i', 'ј' => 'j', 'к' => 'k', 'л' => 'l', 'љ' => 'lj', 'м' => 'm', 'н' => 'n', 'њ' => 'nj', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'ќ' => 'kj', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch', 'џ' => 'dzh', 'ш' => 'sh'];
        $upper = [];
        foreach ($map as $from => $to) $upper[mb_strtoupper($from)] = ucfirst($to);
        $value = strtr($value, $map + $upper);
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    private function ensurePlaceholder(): string
    {
        $relative = 'catalog-placeholders/pdf-product.svg';
        $path = public_path('storage/images/' . $relative);
        if (! is_file($path)) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="800" viewBox="0 0 800 800"><rect width="800" height="800" fill="#f4f0eb"/><rect x="220" y="150" width="360" height="470" rx="28" fill="#fff" stroke="#c9b8b0" stroke-width="8"/><text x="400" y="365" text-anchor="middle" font-family="Arial,sans-serif" font-size="34" fill="#463c39">TESSA</text><text x="400" y="415" text-anchor="middle" font-family="Arial,sans-serif" font-size="24" fill="#7b6962">IMAGE PENDING</text><text x="400" y="455" text-anchor="middle" font-family="Arial,sans-serif" font-size="20" fill="#7b6962">ADMIN REVIEW</text></svg>');
        }
        return $relative;
    }

    private function isSupplierImport(Product $product): bool
    {
        return $product->images()
            ->where('name', 'catalog-placeholders/pdf-product.svg')
            ->where('review_status', 'needs_review')
            ->exists();
    }
}
