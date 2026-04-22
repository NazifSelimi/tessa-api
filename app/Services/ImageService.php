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
    private const CATALOG_MATCH_THRESHOLD = 18.0;
    private const OPAQUE_ALPHA_THRESHOLD = 0.9;
    private const GD_BACKGROUND_FLOOD_THRESHOLD = 32.0;

    /**
     * Store an uploaded image, converting it to WebP for optimization.
     * Writes directly to public/storage/<folder> to match existing image paths.
     *
     * @param UploadedFile $file     The uploaded file
     * @param string       $folder   Subfolder inside public/storage (e.g. 'images')
     * @param int          $maxWidth Maximum width to resize to
     * @param int          $quality  WebP quality (1-100)
     * @return string                Just the filename (e.g. "abc123.webp")
     */
    public function storeAsWebP(
        UploadedFile $file,
        string $folder = 'images',
        int $maxWidth = 1200,
        int $quality = 82,
        bool $normalizeCatalogBackground = false
    ): string {
        $filename = Str::uuid() . '.webp';
        $directory = public_path('storage/' . $folder);

        // Ensure the directory exists
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $destPath = $directory . '/' . $filename;

        // Try GD-based WebP conversion
        if (function_exists('imagecreatefromstring')) {
            $imageData = file_get_contents($file->getPathname());
            $src = @imagecreatefromstring($imageData);

            if ($src !== false) {
                $src = $this->resizeGdIfNeeded($src, $maxWidth);
                $normalized = $this->normalizeCatalogPackshotWithGd($src, $folder, $normalizeCatalogBackground);

                if ($normalized instanceof \GdImage) {
                    imagedestroy($src);
                    $src = $normalized;
                }

                imagewebp($src, $destPath, $quality);
                imagedestroy($src);

                return $filename;
            }
        }

        // Fallback: store original file as-is
        $ext = $file->getClientOriginalExtension();
        $fallbackName = Str::uuid() . '.' . $ext;
        $file->move($directory, $fallbackName);

        return $fallbackName;
    }

    /**
     * Delete an image from the public storage folder.
     */
    public function delete(?string $path, string $folder = 'images'): void
    {
        if (!$path) {
            return;
        }

        $fullPath = public_path('storage/' . $folder . '/' . $path);

        if (file_exists($fullPath)) {
            @unlink($fullPath);
        }
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

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $maxWidth, $newHeight, $origWidth, $origHeight);
        imagedestroy($src);

        return $dst;
    }

    private function normalizeCatalogPackshotWithGd(
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

        if ($this->alreadyMatchesCatalogCanvasGd($image)) {
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

        $isolated = $this->cropOpaqueRegionGd($image, $backgroundMask, $minX, $minY, $width, $height);
        $scale = min(
            self::CATALOG_SUBJECT_MAX_WIDTH / max(1, imagesx($isolated)),
            self::CATALOG_SUBJECT_MAX_HEIGHT / max(1, imagesy($isolated))
        );

        $targetWidth = max(1, (int) round(imagesx($isolated) * $scale));
        $targetHeight = max(1, (int) round(imagesy($isolated) * $scale));
        $resized = $this->resizeGdImage($isolated, $targetWidth, $targetHeight);
        imagedestroy($isolated);

        $canvas = imagecreatetruecolor(self::CATALOG_CANVAS_WIDTH, self::CATALOG_CANVAS_HEIGHT);
        $background = imagecolorallocate($canvas, 250, 211, 229);
        imagefill($canvas, 0, 0, $background);

        $x = (int) floor((self::CATALOG_CANVAS_WIDTH - $targetWidth) / 2);
        $y = (int) floor((self::CATALOG_CANVAS_HEIGHT - $targetHeight) / 2);

        imagealphablending($canvas, true);
        imagesavealpha($canvas, false);
        imagecopy($canvas, $resized, $x, $y, 0, 0, $targetWidth, $targetHeight);
        imagedestroy($resized);

        return $canvas;
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

    private function alreadyMatchesCatalogCanvasGd(\GdImage $image): bool
    {
        $widthMatches = abs(imagesx($image) - self::CATALOG_CANVAS_WIDTH) <= 8;
        $heightMatches = abs(imagesy($image) - self::CATALOG_CANVAS_HEIGHT) <= 8;

        if (!$widthMatches || !$heightMatches) {
            return false;
        }

        $avg = $this->averageColor($this->sampleEdgeColorsGd($image));
        $catalog = ['r' => 250, 'g' => 211, 'b' => 229];

        return $this->colorDistance($avg, $catalog) <= self::CATALOG_MATCH_THRESHOLD;
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
}
