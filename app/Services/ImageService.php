<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class ImageService
{
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
        int $quality = 82
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
                $origWidth = imagesx($src);
                $origHeight = imagesy($src);

                // Resize if wider than max, maintaining aspect ratio
                if ($origWidth > $maxWidth) {
                    $ratio = $maxWidth / $origWidth;
                    $newHeight = (int) round($origHeight * $ratio);
                    $dst = imagecreatetruecolor($maxWidth, $newHeight);

                    // Preserve transparency
                    imagealphablending($dst, false);
                    imagesavealpha($dst, true);

                    imagecopyresampled($dst, $src, 0, 0, 0, 0, $maxWidth, $newHeight, $origWidth, $origHeight);
                    imagedestroy($src);
                    $src = $dst;
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
}
