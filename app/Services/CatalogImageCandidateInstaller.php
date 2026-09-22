<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class CatalogImageCandidateInstaller
{
    public function __construct(private readonly ImageService $images)
    {
    }

    /** @param array<string, mixed> $candidate */
    public function install(array $candidate, string $auditDirectory): bool
    {
        $relativePath = $candidate['downloaded_path'] ?? null;
        $path = $relativePath ? $auditDirectory . '/' . $relativePath : null;
        if (! is_string($path) || ! is_file($path)) {
            return false;
        }

        return DB::transaction(function () use ($candidate, $path) {
            $product = Product::query()->with('images')->findOrFail($candidate['product_id']);
            $sourceUrl = (string) ($candidate['source_image_url'] ?? '');
            if ($product->images->contains(fn ($image) => ($image->metadata['catalog_audit_source'] ?? null) === $sourceUrl)) {
                return false;
            }

            // Preserve legacy image records and files; the new card becomes the preferred image.
            $product->images()->increment('sort_order', 100);
            $upload = new UploadedFile($path, basename($path), mime_content_type($path) ?: null, null, true);
            foreach ($this->images->storeProductImageAssets($upload, 'images', 1200, 82, false) as $asset) {
                $asset['metadata'] = array_merge($asset['metadata'] ?? [], [
                    'catalog_audit_source' => $sourceUrl,
                    'catalog_audit_directory' => basename($auditDirectory),
                    'catalog_candidate_rank' => $candidate['candidate_rank'] ?? null,
                ]);
                $asset['review_status'] = 'approved';
                $product->images()->create($asset);
            }

            return true;
        });
    }
}
