<?php

namespace App\Console\Commands;

use App\Models\Image;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class AuditCatalogImages extends Command
{
    protected $signature = 'catalog:audit-images {--brand=* : Brand name to audit (defaults to Fanola and RR Line)} {--output= : Output directory relative to storage/app}';

    protected $description = 'Create a read-only CSV and JSON audit of catalogue images';

    public function handle(): int
    {
        $brands = collect($this->option('brand'))
            ->filter()
            ->whenEmpty(fn ($items) => collect(['Fanola', 'RR Line']))
            ->map(fn (string $brand) => trim($brand))
            ->values();

        $output = trim((string) $this->option('output'), '/');
        $output = $output !== '' ? $output : 'catalog-audits/' . now()->format('Ymd-His');
        $directory = storage_path('app/' . $output);
        File::ensureDirectoryExists($directory);

        $products = Product::query()
            ->with(['brand', 'category', 'images'])
            ->whereHas('brand', fn ($query) => $query->whereIn('name', $brands))
            ->orderBy('id')
            ->get();

        $rows = $products->flatMap(function (Product $product) {
            if ($product->images->isEmpty()) {
                return [array_merge($this->productFields($product), $this->emptyImageFields())];
            }

            return $product->images->map(fn (Image $image) => array_merge(
                $this->productFields($product),
                $this->imageFields($image)
            ));
        })->values();

        $duplicatePaths = $rows->filter(fn (array $row) => filled($row['image_reference']))
            ->groupBy('image_reference')
            ->filter(fn ($matches) => $matches->pluck('product_id')->unique()->count() > 1)
            ->keys()
            ->flip();

        $duplicateHashes = $rows->filter(fn (array $row) => filled($row['sha256']))
            ->groupBy('sha256')
            ->filter(fn ($matches) => $matches->pluck('product_id')->unique()->count() > 1)
            ->keys()
            ->flip();

        $rows = $rows->map(function (array $row) use ($duplicatePaths, $duplicateHashes) {
            $flags = array_filter(explode('|', $row['flags']));
            if (isset($duplicatePaths[$row['image_reference'] ?? '']) || isset($duplicateHashes[$row['sha256'] ?? ''])) {
                $flags[] = 'shared_image_manual_review';
            }
            $row['flags'] = implode('|', array_unique($flags));
            return $row;
        });

        $csvPath = $directory . '/image_audit.csv';
        $stream = fopen($csvPath, 'wb');
        $headers = $rows->first() ? array_keys($rows->first()) : array_keys(array_merge($this->productFields(new Product()), $this->emptyImageFields()));
        fputcsv($stream, $headers);
        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn ($header) => $row[$header] ?? null, $headers));
        }
        fclose($stream);

        $jsonPath = $directory . '/image_audit.json';
        File::put($jsonPath, json_encode([
            'generated_at' => now()->toIso8601String(),
            'read_only' => true,
            'brands' => $brands->all(),
            'image_standard' => ['preferred_ratio' => '1:1', 'minimum_pixels' => '1000x1000', 'delivery_format' => 'WebP'],
            'schema_limitations' => ['EAN, manufacturer SKU, explicit shade, variant, and size columns are not present in the current products schema.'],
            'manual_review_required_for' => ['wrong product, shade, size, crop, blur, watermark, background, and source-rights checks'],
            'rows' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        File::put($directory . '/image_sources.csv', "product_id,product_name,brand,ean_or_sku,source_url,image_url,confidence,match_reason,status\n");

        $this->info("Read-only audit written to {$directory}");
        $this->line("Products: {$products->count()}; image records: {$rows->whereNotNull('image_id')->count()}; missing-image products: {$rows->whereNull('image_id')->count()}");
        return self::SUCCESS;
    }

    private function productFields(Product $product): array
    {
        return [
            'product_id' => $product->id,
            'brand' => $product->brand?->name,
            'product_name' => $product->name,
            'category' => $product->category?->name,
            // These are deliberately blank: inferring shade or size from names is not a verified match.
            'variant' => null,
            'shade' => null,
            'size_or_volume' => null,
            'ean_or_sku' => null,
        ];
    }

    private function emptyImageFields(): array
    {
        return ['image_id' => null, 'image_reference' => null, 'image_url' => null, 'image_variant' => null, 'exists' => false, 'width' => null, 'height' => null, 'mime_type' => null, 'file_size_bytes' => null, 'sha256' => null, 'flags' => 'missing_image'];
    }

    private function imageFields(Image $image): array
    {
        $reference = $image->url ?: $image->name;
        $path = $this->localPath($image);
        $exists = $path !== null && is_file($path);
        $size = $exists ? @getimagesize($path) : false;
        $flags = [];

        if (! $exists) {
            $flags[] = Str::startsWith((string) $reference, ['http://', 'https://']) ? 'remote_image_unverified' : 'missing_or_broken_file';
        }
        if ($exists && $size !== false && ($size[0] < 1000 || $size[1] < 1000)) {
            $flags[] = 'below_1000px_standard';
        }
        if ($exists && $size !== false && abs(($size[0] / max(1, $size[1])) - 1) > 0.08 && $image->variant !== 'card') {
            $flags[] = 'non_square_manual_review';
        }
        if (($image->review_status ?? null) === 'needs_review') {
            $flags[] = 'pipeline_review_pending';
        }

        return [
            'image_id' => $image->id,
            'image_reference' => $reference,
            'image_url' => $image->url ?: $image->publicUrl(),
            'image_variant' => $image->variant ?? 'legacy',
            'exists' => $exists,
            'width' => $size[0] ?? null,
            'height' => $size[1] ?? null,
            'mime_type' => $size['mime'] ?? null,
            'file_size_bytes' => $exists ? filesize($path) : null,
            'sha256' => $exists ? hash_file('sha256', $path) : null,
            'flags' => implode('|', $flags),
        ];
    }

    private function localPath(Image $image): ?string
    {
        if (! is_string($image->name) || $image->name === '' || Str::startsWith($image->name, ['http://', 'https://'])) {
            return null;
        }

        return public_path('storage/images/' . ltrim($image->name, '/'));
    }
}
