<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Imagick;
use ImagickPixel;

class ImageService
{
    private const CATALOG_CANVAS_WIDTH = 530;
    private const CATALOG_CANVAS_HEIGHT = 633;
    private const CATALOG_SUBJECT_MAX_WIDTH = 190;
    private const CATALOG_SUBJECT_MAX_HEIGHT = 530;
    private const CATALOG_BACKGROUND = 'rgb(250,211,229)';
    private const EDGE_INSET = 5;
    private const EDGE_VARIANCE_THRESHOLD = 18.0;
    private const CATALOG_MATCH_THRESHOLD = 18.0;
    private const CATALOG_FLOOD_FILL_FUZZ = 3200.0;
    private const OPAQUE_ALPHA_THRESHOLD = 0.9;

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

        if ($this->storeWithImagick($file, $destPath, $folder, $maxWidth, $quality, $normalizeCatalogBackground)) {
            return $filename;
        }

        // Try GD-based WebP conversion
        if (function_exists('imagecreatefromstring')) {
            $imageData = file_get_contents($file->getPathname());
            $src = @imagecreatefromstring($imageData);

            if ($src !== false) {
                $src = $this->resizeGdIfNeeded($src, $maxWidth);

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

    private function storeWithImagick(
        UploadedFile $file,
        string $destPath,
        string $folder,
        int $maxWidth,
        int $quality,
        bool $normalizeCatalogBackground
    ): bool {
        if (!class_exists(Imagick::class)) {
            return false;
        }

        try {
            $image = new Imagick($file->getPathname());

            if ($image->getImageWidth() > $maxWidth) {
                $image->resizeImage($maxWidth, 0, Imagick::FILTER_LANCZOS, 1, true);
            }

            $normalized = $this->normalizeCatalogPackshot($image, $folder, $normalizeCatalogBackground);

            if ($normalized instanceof Imagick) {
                $image->clear();
                $image->destroy();
                $image = $normalized;
            }

            $image->stripImage();
            $image->setImageFormat('webp');
            $image->setImageCompressionQuality($quality);
            $image->writeImage($destPath);
            $image->clear();
            $image->destroy();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function normalizeCatalogPackshot(Imagick $image, string $folder, bool $normalizeCatalogBackground): ?Imagick
    {
        if (!$normalizeCatalogBackground || $folder !== 'images') {
            return null;
        }

        if (!$this->hasUniformEdgeBackground($image)) {
            return null;
        }

        if ($this->alreadyMatchesCatalogCanvas($image)) {
            return null;
        }

        $isolated = clone $image;
        $isolated->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);

        foreach ($this->edgeSeedPoints($isolated->getImageWidth(), $isolated->getImageHeight()) as [$x, $y]) {
            $seed = $isolated->getImagePixelColor($x, $y);
            $isolated->floodfillPaintImage(
                new ImagickPixel('transparent'),
                self::CATALOG_FLOOD_FILL_FUZZ,
                $seed,
                $x,
                $y,
                false
            );
        }

        $bounds = $this->findOpaqueBounds($isolated);

        if ($bounds === null) {
            $isolated->clear();
            $isolated->destroy();

            return null;
        }

        [$minX, $minY, $maxX, $maxY] = $bounds;
        $width = $maxX - $minX + 1;
        $height = $maxY - $minY + 1;

        $coverage = ($width * $height) / max(1, $isolated->getImageWidth() * $isolated->getImageHeight());
        if ($coverage > 0.92 || $width < 20 || $height < 20) {
            $isolated->clear();
            $isolated->destroy();

            return null;
        }

        $isolated->cropImage($width, $height, $minX, $minY);
        $isolated->setImagePage(0, 0, 0, 0);

        $scale = min(
            self::CATALOG_SUBJECT_MAX_WIDTH / max(1, $isolated->getImageWidth()),
            self::CATALOG_SUBJECT_MAX_HEIGHT / max(1, $isolated->getImageHeight())
        );

        $targetWidth = max(1, (int) round($isolated->getImageWidth() * $scale));
        $targetHeight = max(1, (int) round($isolated->getImageHeight() * $scale));

        $isolated->resizeImage($targetWidth, $targetHeight, Imagick::FILTER_LANCZOS, 1, true);

        $canvas = new Imagick();
        $canvas->newImage(
            self::CATALOG_CANVAS_WIDTH,
            self::CATALOG_CANVAS_HEIGHT,
            new ImagickPixel(self::CATALOG_BACKGROUND)
        );
        $canvas->setImageFormat('webp');

        $x = (int) floor((self::CATALOG_CANVAS_WIDTH - $targetWidth) / 2);
        $y = (int) floor((self::CATALOG_CANVAS_HEIGHT - $targetHeight) / 2);

        $canvas->compositeImage($isolated, Imagick::COMPOSITE_OVER, $x, $y);

        $isolated->clear();
        $isolated->destroy();

        return $canvas;
    }

    private function alreadyMatchesCatalogCanvas(Imagick $image): bool
    {
        $widthMatches = abs($image->getImageWidth() - self::CATALOG_CANVAS_WIDTH) <= 8;
        $heightMatches = abs($image->getImageHeight() - self::CATALOG_CANVAS_HEIGHT) <= 8;

        if (!$widthMatches || !$heightMatches) {
            return false;
        }

        $avg = $this->averageColor($this->sampleEdgeColors($image));
        $catalog = ['r' => 250, 'g' => 211, 'b' => 229];

        return $this->colorDistance($avg, $catalog) <= self::CATALOG_MATCH_THRESHOLD;
    }

    private function hasUniformEdgeBackground(Imagick $image): bool
    {
        $samples = $this->sampleEdgeColors($image);
        $avg = $this->averageColor($samples);

        foreach ($samples as $sample) {
            if ($this->colorDistance($sample, $avg) > self::EDGE_VARIANCE_THRESHOLD) {
                return false;
            }
        }

        return true;
    }

    private function sampleEdgeColors(Imagick $image): array
    {
        $samples = [];

        foreach ($this->edgeSeedPoints($image->getImageWidth(), $image->getImageHeight()) as [$x, $y]) {
            $pixel = $image->getImagePixelColor($x, $y)->getColor();
            $samples[] = [
                'r' => (int) round($pixel['r']),
                'g' => (int) round($pixel['g']),
                'b' => (int) round($pixel['b']),
            ];
        }

        return $samples;
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

    private function findOpaqueBounds(Imagick $image): ?array
    {
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        $minX = $width;
        $minY = $height;
        $maxX = -1;
        $maxY = -1;
        $inset = max(1, min(self::EDGE_INSET, (int) floor(min($width, $height) / 8)));

        $iterator = $image->getPixelIterator();

        foreach ($iterator as $y => $row) {
            if ($y < $inset || $y > ($height - $inset - 1)) {
                continue;
            }

            foreach ($row as $x => $pixel) {
                if ($x < $inset || $x > ($width - $inset - 1)) {
                    continue;
                }

                $alpha = $pixel->getColor(true)['a'] ?? 0.0;
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
}
