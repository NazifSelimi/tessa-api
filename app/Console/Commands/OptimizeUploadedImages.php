<?php

namespace App\Console\Commands;

use App\Models\Image;
use App\Services\ImageService;
use Illuminate\Console\Command;

/**
 * Photos uploaded in the admin used to be shown at full size (often multi-MB
 * PNGs). This gives each one a 1200 px WebP copy for the shop. The uploaded
 * file stays on disk and is named in metadata.original_upload.
 */
class OptimizeUploadedImages extends Command
{
    protected $signature = 'images:optimize-uploads {--dry-run : Only count what would change}';

    protected $description = 'Give admin-uploaded product photos a web-sized WebP copy';

    public function handle(ImageService $images): int
    {
        $rows = Image::query()
            ->where('name', 'like', 'admin-review/%')
            ->get();
        $this->info("{$rows->count()} uploaded photo(s) without a web copy.");
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $done = 0;
        foreach ($rows as $image) {
            $web = $images->webCopy('images/' . $image->name);
            if (!$web) {
                $this->warn("Skipped image {$image->id}: not readable as an image.");
                continue;
            }
            $metadata = $image->metadata ?? [];
            $metadata['original_upload'] = $image->name;
            $image->update(['name' => substr($web, strlen('images/')), 'metadata' => $metadata]);
            $done++;
        }
        $this->info("Made {$done} web copies.");

        return self::SUCCESS;
    }
}
