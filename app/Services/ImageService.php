<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use SplQueue;

class ImageService
{
    private const CATALOG_CANVAS_WIDTH = 530;
    private const CATALOG_CANVAS_HEIGHT = 633;
    private const CATALOG_SUBJECT_MAX_WIDTH = 190;
    private const CATALOG_SUBJECT_MAX_HEIGHT = 530;
    private const EDGE_INSET = 5;
    private const EDGE_VARIANCE_THRESHOLD = 18.0;
    private const OPAQUE_ALPHA_THRESHOLD = 0.9;
    private const GD_BACKGROUND_FLOOD_THRESHOLD = 32.0;

    /**
     * Store an uploaded image and return the preferred display filename.
     * Newer callers should use storeProductImageAssets() to preserve originals
     * and create multiple catalog variants.
     */
    public function storeAsWebP(
        UploadedFile $file,
        string $folder = 'images',
        int $maxWidth = 1200,
        int $quality = 82,
        bool $normalizeCatalogBackground = false
    ): string {
        $assets = $this->storeProductImageAssets(
            $file,
            $folder,
            $maxWidth,
            $quality,
            $normalizeCatalogBackground
        );

        $preferred = collect($assets)->firstWhere('variant', 'detail')
            ?? collect($assets)->firstWhere('variant', 'legacy')
            ?? $assets[0]
            ?? null;

        return (string) ($preferred['name'] ?? '');
    }

    /**
     * Store the untouched original plus any derived catalog assets.
     *
     * @return array<int, array<string, mixed>>
     */
    public function storeProductImageAssets(
        UploadedFile $file,
        string $folder = 'images',
        int $maxWidth = 1200,
        int $quality = 82,
        bool $normalizeCatalogBackground = false
    ): array {
        $directory = public_path('storage/' . $folder);
        $this->ensureDirectoryExists($directory);

        $originalRelativePath = 'originals/' . Str::uuid() . '.' . $this->originalExtension($file);
        $originalAbsolutePath = $directory . '/' . $originalRelativePath;
        $this->ensureDirectoryExists(dirname($originalAbsolutePath));
        copy($file->getPathname(), $originalAbsolutePath);

        $alt = $this->normalizeAltText($file->getClientOriginalName());
        $assets = [[
            'name' => $originalRelativePath,
            'alt' => $alt,
            'sort_order' => 30,
            'variant' => 'original',
            'background' => 'unknown',
            'review_status' => 'preserved',
            'metadata' => $this->originalMetadata($file, $normalizeCatalogBackground),
        ]];

        if (!function_exists('imagecreatefromstring')) {
            $detailRelativePath = 'catalog/detail/' . Str::uuid() . '.' . $this->originalExtension($file);
            $detailAbsolutePath = $directory . '/' . $detailRelativePath;
            $this->ensureDirectoryExists(dirname($detailAbsolutePath));
            copy($file->getPathname(), $detailAbsolutePath);

            $assets[] = [
                'name' => $detailRelativePath,
                'alt' => $alt,
                'sort_order' => 10,
                'variant' => 'detail',
                'background' => 'unknown',
                'review_status' => $normalizeCatalogBackground ? 'needs_review' : 'ready',
                'metadata' => [
                    'derived_from' => 'original',
                    'strategy' => 'copied-original',
                ],
            ];

            return $assets;
        }

        $imageData = file_get_contents($file->getPathname());
        $src = $imageData !== false ? @imagecreatefromstring($imageData) : false;

        if (!$src instanceof \GdImage) {
            $detailRelativePath = 'catalog/detail/' . Str::uuid() . '.' . $this->originalExtension($file);
            $detailAbsolutePath = $directory . '/' . $detailRelativePath;
            $this->ensureDirectoryExists(dirname($detailAbsolutePath));
            copy($file->getPathname(), $detailAbsolutePath);

            $assets[] = [
                'name' => $detailRelativePath,
                'alt' => $alt,
                'sort_order' => 10,
                'variant' => 'detail',
                'background' => 'unknown',
                'review_status' => $normalizeCatalogBackground ? 'needs_review' : 'ready',
                'metadata' => [
                    'derived_from' => 'original',
                    'strategy' => 'copied-original',
                ],
            ];

            return $assets;
        }

        $src = $this->resizeGdIfNeeded($src, $maxWidth);
        $hasTransparentSource = $this->hasTransparentPixelsGd($src);
        $transparentMaster = $this->extractTransparentPackshotWithGd($src, $folder, $normalizeCatalogBackground);
        $baseImage = $transparentMaster instanceof \GdImage ? $transparentMaster : $src;
        $hasTransparentMaster = $transparentMaster instanceof \GdImage || $hasTransparentSource;
        $baseBackground = $hasTransparentMaster
            ? 'transparent'
            : 'opaque';
        $reviewStatus = $normalizeCatalogBackground && !$hasTransparentMaster
            ? 'needs_review'
            : 'ready';

        $cardVariant = $this->createCatalogCardVariantGd($baseImage);
        $cardRelativePath = 'catalog/card/' . Str::uuid() . '.webp';
        $this->storeGdImage($cardVariant, $directory . '/' . $cardRelativePath, $quality);
        imagedestroy($cardVariant);

        $assets[] = [
            'name' => $cardRelativePath,
            'alt' => $alt,
            'sort_order' => 0,
            'variant' => 'card',
            'background' => 'transparent',
            'review_status' => $reviewStatus,
            'metadata' => [
                'derived_from' => $hasTransparentMaster ? 'transparent_master' : 'detail',
                'strategy' => 'catalog-card',
            ],
        ];

        $detailRelativePath = 'catalog/detail/' . Str::uuid() . '.webp';
        $this->storeGdImage($baseImage, $directory . '/' . $detailRelativePath, $quality);

        $assets[] = [
            'name' => $detailRelativePath,
            'alt' => $alt,
            'sort_order' => 10,
            'variant' => 'detail',
            'background' => $baseBackground,
            'review_status' => $reviewStatus,
            'metadata' => [
                'derived_from' => $hasTransparentMaster ? 'transparent_master' : 'original',
                'strategy' => 'detail',
            ],
        ];

        if ($hasTransparentMaster) {
            $masterRelativePath = 'catalog/masters/' . Str::uuid() . '.webp';
            $masterSource = $transparentMaster instanceof \GdImage ? $transparentMaster : $src;
            $this->storeGdImage($masterSource, $directory . '/' . $masterRelativePath, $quality);

            $assets[] = [
                'name' => $masterRelativePath,
                'alt' => $alt,
                'sort_order' => 20,
                'variant' => 'transparent_master',
                'background' => 'transparent',
                'review_status' => 'ready',
                'metadata' => [
                    'derived_from' => 'original',
                    'strategy' => $transparentMaster instanceof \GdImage
                        ? 'transparent-master-extracted'
                        : 'transparent-master-uploaded',
                ],
            ];

        }

        if ($transparentMaster instanceof \GdImage) {
            imagedestroy($transparentMaster);
        }

        imagedestroy($src);

        return $assets;
    }

    /**
     * Delete an image from the public storage folder.
     */
    public function delete(?string $path, string $folder = 'images'): void
    {
        if (!$path) {
            return;
        }

        $fullPath = public_path('storage/' . $folder . '/' . ltrim($path, '/'));

        if (file_exists($fullPath)) {
            @unlink($fullPath);
        }
    }

    private function normalizeAltText(string $originalName): ?string
    {
        $alt = trim(pathinfo($originalName, PATHINFO_FILENAME));

        return $alt !== '' ? $alt : null;
    }

    private function originalMetadata(UploadedFile $file, bool $normalizeCatalogBackground): array
    {
        return array_filter([
            'original_filename' => $file->getClientOriginalName(),
            'original_mime_type' => $file->getClientMimeType(),
            'original_size' => $file->getSize(),
            'requested_transparent_master' => $normalizeCatalogBackground,
        ], static fn ($value) => $value !== null);
    }

    private function originalExtension(UploadedFile $file): string
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        return $extension !== '' ? $extension : 'bin';
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }

    private function storeGdImage(\GdImage $image, string $absolutePath, int $quality): void
    {
        $this->ensureDirectoryExists(dirname($absolutePath));
        imagewebp($image, $absolutePath, $quality);
    }

    private function createCatalogCardVariantGd(\GdImage $source): \GdImage
    {
        $targetWidth = imagesx($source);
        $targetHeight = imagesy($source);
        $scale = min(
            self::CATALOG_SUBJECT_MAX_WIDTH / max(1, $targetWidth),
            self::CATALOG_SUBJECT_MAX_HEIGHT / max(1, $targetHeight),
            1
        );

        $resized = $this->resizeGdImage(
            $source,
            max(1, (int) round($targetWidth * $scale)),
            max(1, (int) round($targetHeight * $scale))
        );

        $canvas = imagecreatetruecolor(self::CATALOG_CANVAS_WIDTH, self::CATALOG_CANVAS_HEIGHT);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparent);

        $x = (int) floor((self::CATALOG_CANVAS_WIDTH - imagesx($resized)) / 2);
        $y = (int) floor((self::CATALOG_CANVAS_HEIGHT - imagesy($resized)) / 2);

        imagecopy($canvas, $resized, $x, $y, 0, 0, imagesx($resized), imagesy($resized));
        imagedestroy($resized);

        return $canvas;
    }

    private function edgeSeedPoints(int $width, int $height): array
    {
        $inset = max(1, min(self::EDGE_INSET, (int) floor(min($width, $height) / 8)));
        $midX = max($inset, (int) floor($width / 2));
        $midY = max($inset, (int) floor($height / 2));

        return [
            [$inset, $inset],
            [$width - $inset - 1, $inset],
            [$inset, $height - $inset - 1],
            [$width - $inset - 1, $height - $inset - 1],
            [$midX, $inset],
            [$midX, $height - $inset - 1],
            [$inset, $midY],
            [$width - $inset - 1, $midY],
        ];
    }

    private function averageColor(array $samples): array
    {
        $count = max(1, count($samples));
        $sum = ['r' => 0.0, 'g' => 0.0, 'b' => 0.0];

        foreach ($samples as $sample) {
            $sum['r'] += $sample['r'];
            $sum['g'] += $sample['g'];
            $sum['b'] += $sample['b'];
        }

        return [
            'r' => $sum['r'] / $count,
            'g' => $sum['g'] / $count,
            'b' => $sum['b'] / $count,
        ];
    }

    private function colorDistance(array $left, array $right): float
    {
        return sqrt(
            (($left['r'] - $right['r']) ** 2) +
            (($left['g'] - $right['g']) ** 2) +
            (($left['b'] - $right['b']) ** 2)
        );
    }

    private function resizeGdIfNeeded(\GdImage $src, int $maxWidth): \GdImage
    {
        $origWidth = imagesx($src);
        $origHeight = imagesy($src);

        if ($origWidth <= $maxWidth) {
            return $src;
        }

        $ratio = $maxWidth / $origWidth;
        $newHeight = (int) round($origHeight * $ratio);
        $dst = imagecreatetruecolor($maxWidth, $newHeight);

        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $maxWidth, $newHeight, $origWidth, $origHeight);
        imagedestroy($src);

        return $dst;
    }

    private function extractTransparentPackshotWithGd(
        \GdImage $image,
        string $folder,
        bool $normalizeCatalogBackground
    ): ?\GdImage {
        if (!$normalizeCatalogBackground || $folder !== 'images') {
            return null;
        }

        if (!$this->hasUniformEdgeBackgroundGd($image)) {
            return null;
        }

        $backgroundMask = $this->buildBackgroundMaskGd($image);
        $bounds = $this->findOpaqueBoundsGd($image, $backgroundMask);

        if ($bounds === null) {
            return null;
        }

        [$minX, $minY, $maxX, $maxY] = $bounds;
        $width = $maxX - $minX + 1;
        $height = $maxY - $minY + 1;
        $coverage = ($width * $height) / max(1, imagesx($image) * imagesy($image));

        if ($coverage > 0.92 || $width < 20 || $height < 20) {
            return null;
        }

        return $this->cropOpaqueRegionGd($image, $backgroundMask, $minX, $minY, $width, $height);
    }

    private function hasUniformEdgeBackgroundGd(\GdImage $image): bool
    {
        $samples = $this->sampleEdgeColorsGd($image);
        $avg = $this->averageColor($samples);

        foreach ($samples as $sample) {
            if ($this->colorDistance($sample, $avg) > self::EDGE_VARIANCE_THRESHOLD) {
                return false;
            }
        }

        return true;
    }

    private function sampleEdgeColorsGd(\GdImage $image): array
    {
        $samples = [];

        foreach ($this->edgeSeedPoints(imagesx($image), imagesy($image)) as [$x, $y]) {
            $samples[] = $this->gdColorAt($image, $x, $y);
        }

        return $samples;
    }

    private function buildBackgroundMaskGd(\GdImage $image): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $mask = array_fill(0, $height, str_repeat("\0", $width));
        $samples = $this->sampleEdgeColorsGd($image);
        $background = $this->averageColor($samples);
        $queue = new SplQueue();

        foreach ($this->edgeSeedPoints($width, $height) as [$x, $y]) {
            $color = $this->gdColorAt($image, $x, $y);

            if ($this->colorDistance($color, $background) > self::GD_BACKGROUND_FLOOD_THRESHOLD) {
                continue;
            }

            if ($mask[$y][$x] === "\1") {
                continue;
            }

            $mask[$y][$x] = "\1";
            $queue->enqueue($y * $width + $x);
        }

        while (!$queue->isEmpty()) {
            $position = $queue->dequeue();
            $x = $position % $width;
            $y = intdiv($position, $width);

            foreach ([[$x - 1, $y], [$x + 1, $y], [$x, $y - 1], [$x, $y + 1]] as [$nextX, $nextY]) {
                if ($nextX < 0 || $nextX >= $width || $nextY < 0 || $nextY >= $height) {
                    continue;
                }

                if ($mask[$nextY][$nextX] === "\1") {
                    continue;
                }

                $color = $this->gdColorAt($image, $nextX, $nextY);

                if ($this->colorDistance($color, $background) > self::GD_BACKGROUND_FLOOD_THRESHOLD) {
                    continue;
                }

                $mask[$nextY][$nextX] = "\1";
                $queue->enqueue($nextY * $width + $nextX);
            }
        }

        return $mask;
    }

    private function findOpaqueBoundsGd(\GdImage $image, array $backgroundMask): ?array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $minX = $width;
        $minY = $height;
        $maxX = -1;
        $maxY = -1;
        $inset = max(1, min(self::EDGE_INSET, (int) floor(min($width, $height) / 8)));

        for ($y = $inset; $y < ($height - $inset); $y++) {
            for ($x = $inset; $x < ($width - $inset); $x++) {
                if ($backgroundMask[$y][$x] === "\1") {
                    continue;
                }

                $alpha = $this->gdAlphaAt($image, $x, $y);
                if ($alpha < self::OPAQUE_ALPHA_THRESHOLD) {
                    continue;
                }

                $minX = min($minX, $x);
                $minY = min($minY, $y);
                $maxX = max($maxX, $x);
                $maxY = max($maxY, $y);
            }
        }

        if ($maxX < $minX || $maxY < $minY) {
            return null;
        }

        return [$minX, $minY, $maxX, $maxY];
    }

    private function cropOpaqueRegionGd(
        \GdImage $image,
        array $backgroundMask,
        int $minX,
        int $minY,
        int $width,
        int $height
    ): \GdImage {
        $cropped = imagecreatetruecolor($width, $height);
        imagealphablending($cropped, false);
        imagesavealpha($cropped, true);
        $transparent = imagecolorallocatealpha($cropped, 0, 0, 0, 127);
        imagefill($cropped, 0, 0, $transparent);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $sourceX = $minX + $x;
                $sourceY = $minY + $y;

                if ($backgroundMask[$sourceY][$sourceX] === "\1") {
                    continue;
                }

                $rgba = imagecolorat($image, $sourceX, $sourceY);
                imagesetpixel($cropped, $x, $y, $rgba);
            }
        }

        return $cropped;
    }

    private function resizeGdImage(\GdImage $source, int $width, int $height): \GdImage
    {
        $target = imagecreatetruecolor($width, $height);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        imagefill($target, 0, 0, $transparent);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));

        return $target;
    }

    private function gdColorAt(\GdImage $image, int $x, int $y): array
    {
        $rgba = imagecolorat($image, $x, $y);

        return [
            'r' => ($rgba >> 16) & 0xFF,
            'g' => ($rgba >> 8) & 0xFF,
            'b' => $rgba & 0xFF,
        ];
    }

    private function gdAlphaAt(\GdImage $image, int $x, int $y): float
    {
        $rgba = imagecolorat($image, $x, $y);
        $alpha = ($rgba & 0x7F000000) >> 24;

        return 1.0 - ($alpha / 127.0);
    }

    private function hasTransparentPixelsGd(\GdImage $image): bool
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $stepX = max(1, (int) floor($width / 20));
        $stepY = max(1, (int) floor($height / 20));

        for ($y = 0; $y < $height; $y += $stepY) {
            for ($x = 0; $x < $width; $x += $stepX) {
                if ($this->gdAlphaAt($image, $x, $y) < 0.98) {
                    return true;
                }
            }
        }

        return false;
    }
}
