<?php

namespace App\Console\Commands;

use App\Models\Image;
use App\Models\Product;
use App\Services\CatalogImageCandidateDiscovery;
use App\Services\CatalogImageCandidateInstaller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ReplaceCatalogImages extends Command
{
    protected $signature = 'catalog:replace-images
        {--dry-run : Generate review artifacts only (the default when --approved-only is absent)}
        {--approved-only : Reserved installation gate; only validated approved manifest rows may be installed}
        {--install-probable : Install validated probable candidates into this staging catalogue}
        {--product=* : Limit the audit to one or more existing product IDs}
        {--limit= : Limit the number of products inspected}
        {--output= : Output directory relative to storage/app}';

    protected $description = 'Create a safe, read-only product-image replacement manifest';

    public function handle(): int
    {
        if ($this->option('approved-only')) {
            $this->warn('No images were installed. This command will only install a future manifest row after it has an approved exact-match source image and validation result.');
        }

        $output = trim((string) $this->option('output'), '/');
        $output = $output !== '' ? $output : 'catalog-audits/' . now()->format('Ymd-His') . '-image-replacement';
        $directory = storage_path('app/' . $output);
        File::ensureDirectoryExists($directory . '/source-images');

        $products = Product::query()
            ->with(['brand', 'category', 'images'])
            ->whereHas('brand', fn ($query) => $query->whereIn('name', ['Fanola', 'Rr Line', 'RR Line']))
            ->when($this->option('product'), fn ($query, array $ids) => $query->whereIn('id', $ids))
            ->orderBy('id')
            ->get();

        if ($this->option('limit')) {
            $products = $products->take((int) $this->option('limit'))->values();
        }

        $sharedGroups = $this->sharedLegacyImageGroups($products);
        $discovery = new CatalogImageCandidateDiscovery($directory);
        $manifest = $products->flatMap(function (Product $product) use ($discovery, $sharedGroups) {
            $candidates = $discovery->discover($product);
            if ($candidates === []) {
                return [$this->manifestRow($product, $sharedGroups, [], 1)];
            }

            return collect($candidates)->values()->map(function (array $candidate, int $index) use ($product, $sharedGroups, $discovery) {
                $download = isset($candidate['source_image_url']) && $candidate['source_image_url']
                    ? $discovery->downloadAndValidate(
                        $candidate['source_image_url'],
                        $product->id . '-' . str($product->name)->slug()->limit(80, '') . '-' . ($index + 1)
                    )
                    : [];

                return $this->manifestRow($product, $sharedGroups, array_merge($candidate, $download), $index + 1);
            });
        })->values();

        // A source asset cannot represent two distinct catalogue products. This
        // remains a hard rejection even when future verified candidates are
        // added to discovery.
        $duplicateSources = $manifest->filter(fn (array $row) => filled($row['source_image_url']))
            ->groupBy('source_image_url')
            ->filter(fn ($rows) => $rows->pluck('product_id')->unique()->count() > 1);
        $manifest = $manifest->map(function (array $row) use ($duplicateSources) {
            if (! filled($row['source_image_url']) || ! isset($duplicateSources[$row['source_image_url']])) {
                return $row;
            }

            $row['match_confidence'] = 'manual_source_required';
            $row['replacement_status'] = 'manual_source_required';
            $row['exact_product_match'] = 'not_reviewed';
            $row['rejection_reason'] = 'The same source asset was discovered for multiple distinct products; exact identity cannot be established.';
            $row['review_reason'] = $row['rejection_reason'];

            return $row;
        })->values();

        $this->writeCsv($directory . '/image_replacement_manifest.csv', $manifest->all());
        File::put($directory . '/image_replacement_manifest.json', json_encode([
            'generated_at' => now()->toIso8601String(),
            'read_only' => true,
            'installation_performed' => false,
            'rows' => $manifest->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->writeCsv($directory . '/image_validation.csv', $manifest->map(fn (array $row) => [
            'product_id' => $row['product_id'],
            'source_url' => $row['source_url'],
            'downloaded_path' => $row['downloaded_path'],
            'width' => $row['width'],
            'height' => $row['height'],
            'aspect_ratio' => $row['width'] && $row['height'] ? round($row['width'] / $row['height'], 4) : null,
            'file_size' => $row['file_size'],
            'mime_type' => $row['mime_type'],
            'has_alpha' => $row['has_alpha'],
            'background_type' => $row['background_type'],
            'edge_quality' => $row['has_alpha'] ? 'manual_review_required' : 'not_applicable',
            'derivative_status' => 'not_generated',
            'validation_status' => in_array($row['replacement_status'], ['download_failed', 'validation_failed'], true) ? $row['replacement_status'] : 'not_reviewed',
            'validation_notes' => $row['review_reason'],
        ])->all());

        $this->writeCsv($directory . '/image_replacement_backup.csv', $this->backupRows($products));
        $this->writeCsv($directory . '/image_conflicts.csv', $this->conflictRows($manifest, $sharedGroups));
        File::put($directory . '/README.md', $this->readme($manifest, $sharedGroups, $directory, $discovery->failures()));

        $installed = 0;
        if ($this->option('install-probable')) {
            $installer = app(CatalogImageCandidateInstaller::class);
            foreach ($manifest->filter(fn (array $row) => $row['candidate_rank'] === 1
                && $row['replacement_status'] === 'approved_exact'
                && $row['exact_product_match'] === 'verified'
                && $row['exact_package_match'] === 'verified'
                && in_array($row['exact_shade_match'], ['verified', 'not_applicable'], true)
                && filled($row['downloaded_path'])) as $candidate) {
                $installed += $installer->install($candidate, $directory) ? 1 : 0;
            }
        }

        $this->info("Read-only image replacement review written to {$directory}");
        $this->line("Products: {$products->count()}; candidate installations: {$installed}; existing image changes: {$installed}; database changes: {$installed}.");

        return self::SUCCESS;
    }

    /** @return array<string, array<int, int>> */
    private function sharedLegacyImageGroups($products): array
    {
        $references = [];

        foreach ($products as $product) {
            foreach ($product->images as $image) {
                if ($reference = $image->url ?: $image->name) {
                    $references['reference:' . $reference][] = $product->id;
                }

                if ($hash = $this->localImageHash($image)) {
                    $references['sha256:' . $hash][] = $product->id;
                }
            }
        }

        return collect($references)
            ->map(fn (array $productIds) => collect($productIds)->unique()->sort()->values()->all())
            ->filter(fn (array $ids) => count($ids) > 1)
            ->all();
    }

    /** @param array<string, array<int, int>> $sharedGroups */
    private function isSharedLegacyImage(Image $image, array $sharedGroups): bool
    {
        $reference = $image->url ?: $image->name;

        return ($reference && isset($sharedGroups['reference:' . $reference]))
            || (($hash = $this->localImageHash($image)) !== null && isset($sharedGroups['sha256:' . $hash]));
    }

    private function localImageHash(Image $image): ?string
    {
        if (blank($image->name)) {
            return null;
        }

        $path = public_path('storage/images/' . ltrim($image->name, '/'));

        return is_file($path) ? hash_file('sha256', $path) ?: null : null;
    }

    /** @return array<string, mixed> */
    private function manifestRow(Product $product, array $sharedGroups, array $candidate, int $rank): array
    {
        $primary = $product->images->firstWhere('variant', 'card')
            ?? $product->images->firstWhere('variant', 'detail')
            ?? $product->images->first();
        $shared = $primary && $this->isSharedLegacyImage($primary, $sharedGroups);
        $isHairColor = $product->category?->name === 'Hair Color';
        $confidence = $candidate['match_confidence'] ?? 'no_source';
        $validation = $candidate['validation_status'] ?? 'not_reviewed';
        if ($validation === 'download_failed') {
            $confidence = 'download_failed';
        } elseif ($validation === 'validation_failed') {
            $confidence = 'validation_failed';
        }

        return [
            'product_id' => $product->id,
            'candidate_rank' => $rank,
            'current_name' => $product->name,
            'brand' => $product->brand?->name,
            'category' => $product->category?->name,
            'current_image_count' => $product->images->count(),
            'current_primary_image' => $primary?->url ?: $primary?->name,
            'source_url' => $candidate['source_page_url'] ?? null,
            'source_page_url' => $candidate['source_page_url'] ?? null,
            'source_domain' => $candidate['source_domain'] ?? null,
            'source_type' => $candidate['source_type'] ?? null,
            'source_title' => 'Authoritative page candidate; requires exact package review',
            'source_image_url' => $candidate['source_image_url'] ?? null,
            'downloaded_path' => $candidate['downloaded_path'] ?? null,
            'source_image_width' => $candidate['width'] ?? null,
            'source_image_height' => $candidate['height'] ?? null,
            'source_format' => $candidate['mime_type'] ?? null,
            'width' => $candidate['width'] ?? null,
            'height' => $candidate['height'] ?? null,
            'file_size' => $candidate['file_size'] ?? null,
            'mime_type' => $candidate['mime_type'] ?? null,
            'match_method' => isset($candidate['source_page_url']) ? 'official_sitemap_page_and_asset_extraction' : 'no_exact_source_discovered',
            'match_confidence' => $confidence,
            'exact_product_match' => isset($candidate['source_page_url']) ? 'probable' : 'not_reviewed',
            'exact_package_match' => 'not_reviewed',
            'exact_size_match' => 'not_reviewed',
            'exact_shade_match' => $isHairColor ? 'not_reviewed' : 'not_applicable',
            'image_identity_notes' => 'Downloaded original is review-only. Package, size and shade are not auto-approved from source URLs.',
            'background_type' => $candidate['background_type'] ?? 'unknown',
            'has_alpha' => $candidate['has_alpha'] ?? null,
            'image_quality' => $candidate['visual_quality'] ?? 'not_reviewed',
            'visual_quality' => $candidate['visual_quality'] ?? 'not_reviewed',
            'transparency_quality' => ($candidate['has_alpha'] ?? false) ? 'source_alpha_detected' : 'not_reviewed',
            'recommendation' => ($candidate['has_alpha'] ?? false) ? 'review_preferred_transparent_candidate' : 'review_candidate',
            'rejection_reason' => $candidate['rejection_reason'] ?? null,
            'replacement_status' => $confidence,
            'review_reason' => $shared
                ? 'Current primary asset is shared; never mutate it in place. An exact product-specific replacement is required.'
                : ($candidate['validation_notes'] ?? 'No approved source image has been downloaded and validated.'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function backupRows($products): array
    {
        return $products->map(function (Product $product) {
            $byVariant = $product->images->groupBy(fn (Image $image) => $image->variant ?? 'legacy');

            return [
                'product_id' => $product->id,
                'image_id' => $product->images->pluck('id')->implode('|'),
                'imageable_type' => Product::class,
                'imageable_id' => $product->id,
                'original_path' => $byVariant->get('original', collect())->pluck('name')->implode('|'),
                'card_path' => $byVariant->get('card', collect())->pluck('name')->implode('|'),
                'detail_path' => $byVariant->get('detail', collect())->pluck('name')->implode('|'),
                'transparent_master_path' => $byVariant->get('transparent_master', collect())->pluck('name')->implode('|'),
                'sort_order' => $product->images->pluck('sort_order')->implode('|'),
                'created_at' => $product->images->pluck('created_at')->map(fn ($date) => $date?->toIso8601String())->implode('|'),
            ];
        })->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function conflictRows($manifest, array $sharedGroups): array
    {
        $legacy = collect($sharedGroups)->flatMap(function (array $ids, string $reference) use ($manifest) {
            $products = $manifest->whereIn('product_id', $ids);

            return [[
                'conflict_type' => 'shared_existing_image',
                'product_ids' => implode('|', $ids),
                'current_names' => $products->pluck('current_name')->implode(' | '),
                'image_reference' => $reference,
                'severity' => 'manual_review_required',
                'details' => 'Shared legacy asset must not be mutated in place.',
            ]];
        });
        $candidateDuplicates = $manifest->filter(fn (array $row) => filled($row['source_image_url']))
            ->groupBy('source_image_url')
            ->filter(fn ($rows) => $rows->pluck('product_id')->unique()->count() > 1)
            ->map(function ($rows, string $url) {
                return [
                    'conflict_type' => 'shared_proposed_candidate',
                    'product_ids' => $rows->pluck('product_id')->unique()->sort()->implode('|'),
                    'current_names' => $rows->pluck('current_name')->unique()->implode(' | '),
                    'image_reference' => $url,
                    'severity' => 'manual_review_required',
                    'details' => 'One candidate image is proposed for multiple products; confirm product, size and shade before approval.',
                ];
            });

        return $legacy->concat($candidateDuplicates)->values()->all();
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function writeCsv(string $path, array $rows): void
    {
        $stream = fopen($path, 'wb');
        $headers = $rows !== [] ? array_keys($rows[0]) : [];
        fputcsv($stream, $headers);
        foreach ($rows as $row) {
            fputcsv($stream, array_map(fn ($header) => $row[$header] ?? null, $headers));
        }
        fclose($stream);
    }

    private function readme($manifest, array $sharedGroups, string $directory, array $failures): string
    {
        $count = fn (string $status) => $manifest->where('replacement_status', $status)->count();
        $sourceDomains = $manifest->pluck('source_domain')->filter()->unique()->sort()->implode(', ');
        $productsWithCandidates = $manifest->filter(fn (array $row) => filled($row['source_image_url']))->pluck('product_id')->unique()->count();

        return "# Image Replacement Dry Run\n\n"
            . "This directory is a read-only review package. No product, image record, public image file, or catalogue identity value was changed.\n\n"
            . "- Output directory: {$directory}\n"
            . "- Products inspected: " . $manifest->pluck('product_id')->unique()->count() . "\n"
            . "- Products with candidates: {$productsWithCandidates}\n"
            . "- Fanola: " . $manifest->where('brand', 'Fanola')->pluck('product_id')->unique()->count() . "\n"
            . "- RR Line: " . $manifest->whereIn('brand', ['Rr Line', 'RR Line'])->pluck('product_id')->unique()->count() . "\n"
            . "- Exact/high-confidence candidates: 0\n"
            . "- Probable candidates: {$count('probable')}\n"
            . "- Ambiguous products: {$count('ambiguous')}\n"
            . "- No source: {$count('no_source')}\n"
            . "- Transparent-background candidates: " . $manifest->where('background_type', 'transparent')->count() . "\n"
            . "- White-background candidates: " . $manifest->where('background_type', 'white')->count() . "\n"
            . "- Low-resolution candidates: " . $manifest->where('visual_quality', 'low_resolution')->count() . "\n"
            . "- Download failures: {$count('download_failed')}\n"
            . "- Validation failures: {$count('validation_failed')}\n"
            . "- Duplicate images: " . count($sharedGroups) . "\n"
            . "- Shared-image conflicts: " . count($sharedGroups) . "\n\n"
            . "- Source domains: " . ($sourceDomains !== '' ? $sourceDomains : 'none') . "\n\n"
            . "- Rate-limit/access failures: " . count($failures) . "\n\n"
            . "Only a future exact-match source image with an `approved` manifest status may be installed. Source images remain outside public product storage until reviewed and validated.\n";
    }
}
