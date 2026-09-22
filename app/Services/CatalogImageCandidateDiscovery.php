<?php

namespace App\Services;

use App\Models\Product;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CatalogImageCandidateDiscovery
{
    private const SOURCES = [
        'Fanola' => ['sitemap' => 'https://www.fanola.it/sitemap.xml', 'host' => 'fanola.it', 'type' => 'official_manufacturer'],
        // These are Fanola ranges stored as separate local brands.
        'Oro Therapy' => ['sitemap' => 'https://www.fanola.it/sitemap.xml', 'host' => 'fanola.it', 'type' => 'official_manufacturer'],
        'No Yellow Color' => ['sitemap' => 'https://www.fanola.it/sitemap.xml', 'host' => 'fanola.it', 'type' => 'official_manufacturer'],
        'Rr Line' => ['sitemap' => 'https://www.rrline.it/sitemap.xml', 'host' => 'rrline.it', 'type' => 'official_manufacturer'],
        'RR Line' => ['sitemap' => 'https://www.rrline.it/sitemap.xml', 'host' => 'rrline.it', 'type' => 'official_manufacturer'],
    ];

    /** @var array<string, array<int, string>> */
    private array $sitemaps = [];

    /** @var array<string, string> */
    private array $pages = [];

    /** @var array<string, mixed> */
    private array $failures = [];

    public function __construct(private readonly string $directory)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function discover(Product $product): array
    {
        $source = self::SOURCES[$product->brand?->name ?? ''] ?? null;
        if ($source === null) {
            return [];
        }

        // A shade-only legacy name cannot establish a colour range or packaging generation.
        // Keep it review-only rather than assigning a plausible but unsafe range image.
        if ($product->category?->name === 'Hair Color' && count($this->descriptiveTokens($product->name)) < 2) {
            return [[
                'source_page_url' => null,
                'source_domain' => null,
                'source_type' => $source['type'],
                'source_image_url' => null,
                'match_confidence' => 'ambiguous',
                'rejection_reason' => 'Colour title lacks enough range context for a safe manufacturer page match.',
            ]];
        }

        $pages = $this->sitemapPages($source['sitemap'], $source['host']);
        $pageUrl = $this->bestPage($product->name, $pages);
        if ($pageUrl === null) {
            return [];
        }

        $html = $this->page($pageUrl);
        if ($html === null) {
            return [[
                'source_page_url' => $pageUrl,
                'source_domain' => parse_url($pageUrl, PHP_URL_HOST),
                'source_type' => $source['type'],
                'source_image_url' => null,
                'match_confidence' => 'no_source',
                'rejection_reason' => 'Official page could not be read; see discovery failures.',
            ]];
        }

        if ($this->imageUrls($html, $pageUrl) === []) {
            return [[
                'source_page_url' => $pageUrl,
                'source_domain' => parse_url($pageUrl, PHP_URL_HOST),
                'source_type' => $source['type'],
                'source_image_url' => null,
                'match_confidence' => 'no_source',
                'rejection_reason' => 'No image URL could be extracted from the official page.',
            ]];
        }

        // A manufacturer page match is useful coverage evidence, but without a
        // stored code/package verification it remains review-only. Return one
        // primary packshot rather than treating a page gallery as alternatives.
        $image = $this->imageUrls($html, $pageUrl)[0];

        return [[
            'source_page_url' => $pageUrl,
            'source_domain' => parse_url($pageUrl, PHP_URL_HOST),
            'source_type' => $source['type'],
            'source_image_url' => $image,
            'match_confidence' => 'needs_review',
            'rejection_reason' => $product->category?->name === 'Hair Color'
                ? 'Manufacturer page is plausible, but exact colour range, shade and package size require review.'
                : 'Manufacturer page is plausible, but exact product code and package size require review.',
        ]];
    }

    /** @return array<string, mixed> */
    public function failures(): array
    {
        return $this->failures;
    }

    /** @return array<string, mixed> */
    public function downloadAndValidate(string $url, string $filename): array
    {
        try {
            $response = Http::accept('image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8')
                ->timeout(20)->retry(2, 700)->get($url);
            if (! $response->successful()) {
                return ['downloaded_path' => null, 'validation_status' => 'download_failed', 'validation_notes' => 'HTTP ' . $response->status()];
            }

            $body = $response->body();
            if (strlen($body) > 15 * 1024 * 1024 || @getimagesizefromstring($body) === false) {
                return ['downloaded_path' => null, 'validation_status' => 'validation_failed', 'validation_notes' => 'Response is not a valid image or exceeds the 15 MB safety limit.'];
            }

            $extension = $this->extension($response->header('Content-Type'), $url);
            $path = $this->directory . '/source-images/' . $filename . '.' . $extension;
            File::put($path, $body);
            $size = getimagesize($path);
            $width = $size[0] ?? null;
            $height = $size[1] ?? null;
            $hasAlpha = $this->hasAlpha($path);
            $background = $hasAlpha ? 'transparent' : $this->background($path);
            $lowResolution = ($width ?? 0) < 1000 || ($height ?? 0) < 1000;

            return [
                'downloaded_path' => 'source-images/' . basename($path),
                'http_status' => $response->status(),
                'width' => $width,
                'height' => $height,
                'file_size' => filesize($path),
                'mime_type' => $size['mime'] ?? $response->header('Content-Type'),
                'has_alpha' => $hasAlpha,
                'background_type' => $background,
                'visual_quality' => $lowResolution ? 'low_resolution' : 'source_requires_manual_visual_review',
                'validation_status' => $lowResolution ? 'validation_failed' : 'not_reviewed',
                'validation_notes' => $lowResolution ? 'Below the 1000px minimum; retained only for review.' : 'File dimensions and image content verified; identity and visual defects require review.',
            ];
        } catch (\Throwable $exception) {
            return ['downloaded_path' => null, 'validation_status' => 'download_failed', 'validation_notes' => $exception->getMessage()];
        }
    }

    /** @return array<int, string> */
    private function sitemapPages(string $url, string $host): array
    {
        if (isset($this->sitemaps[$url])) {
            return $this->sitemaps[$url];
        }

        $body = $this->get($url);
        if ($body === null) {
            return $this->sitemaps[$url] = [];
        }

        preg_match_all('/<loc>([^<]+)<\\/loc>/i', $body, $matches);
        return $this->sitemaps[$url] = collect($matches[1] ?? [])
            ->map(fn (string $page) => trim(html_entity_decode($page)))
            ->filter(fn (string $page) => str_ends_with((string) parse_url($page, PHP_URL_HOST), $host))
            ->filter(fn (string $page) => str_contains($page, '/product'))
            ->unique()
            ->values()
            ->all();
    }

    private function bestPage(string $name, array $pages): ?string
    {
        $tokens = $this->descriptiveTokens($name);
        $best = null;
        $bestScore = 0.0;

        foreach ($pages as $page) {
            $haystack = $this->normalise((string) parse_url($page, PHP_URL_PATH));
            $hits = count(array_filter($tokens, fn (string $token) => str_contains($haystack, $token)));
            $score = $tokens === [] ? 0.0 : $hits / count($tokens);
            if ($score > $bestScore) {
                $best = $page;
                $bestScore = $score;
            }
        }

        return $bestScore >= 0.72 ? $best : null;
    }

    private function page(string $url): ?string
    {
        return $this->pages[$url] ??= $this->get($url);
    }

    /** @return array<int, string> */
    private function imageUrls(string $html, string $pageUrl): array
    {
        $urls = [];
        libxml_use_internal_errors(true);
        $document = new DOMDocument();
        if (@$document->loadHTML($html)) {
            $xpath = new DOMXPath($document);
            foreach ($xpath->query('//meta[@property="og:image"]/@content | //meta[@name="twitter:image"]/@content | //img/@src | //source/@srcset') as $node) {
                $urls[] = $node->nodeValue;
            }
        }

        preg_match_all('/https?(?:\\\\u002F|%3A|:)[^"\\\\ ]+?\\.(?:png|webp|jpe?g)(?:[^"\\\\ ]*)/i', $html, $matches);
        $urls = array_merge($urls, $matches[0] ?? []);

        return collect($urls)
            ->flatMap(function (string $url) use ($pageUrl) {
                $url = html_entity_decode(str_replace('\\/', '/', $url));
                $url = rawurldecode($url);
                return str_contains($url, ',') ? explode(',', $url) : [$url];
            })
            ->map(fn (string $url) => trim(explode(' ', $url)[0]))
            ->map(fn (string $url) => str_starts_with($url, '//') ? 'https:' . $url : $url)
            ->map(function (string $url) use ($pageUrl) {
                if (! str_starts_with($url, '/')) {
                    return $url;
                }

                $origin = parse_url($pageUrl, PHP_URL_SCHEME) . '://' . parse_url($pageUrl, PHP_URL_HOST);
                return $origin . $url;
            })
            ->filter(fn (string $url) => filter_var($url, FILTER_VALIDATE_URL) && preg_match('/\\.(png|webp|jpe?g)(?:$|[?&])/i', $url))
            ->filter(fn (string $url) => ! str_contains(strtolower($url), 'logo'))
            ->unique()
            ->values()
            ->all();
    }

    private function get(string $url): ?string
    {
        try {
            $response = Http::accept('text/html,application/xml,image/*')->timeout(15)->retry(2, 500)->get($url);
            if (! $response->successful()) {
                $this->failures[$url] = 'HTTP ' . $response->status();
                return null;
            }

            return $response->body();
        } catch (\Throwable $exception) {
            $this->failures[$url] = $exception->getMessage();
            return null;
        }
    }

    private function normalise(string $value): string
    {
        return trim(preg_replace('/\\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', strtolower(Str::ascii($value)))) ?? '');
    }

    /** @return array<int, string> */
    private function descriptiveTokens(string $value): array
    {
        return array_values(array_filter(
            explode(' ', $this->normalise($value)),
            fn (string $token) => strlen($token) > 2 && ! preg_match('/^\\d+(ml|g|l)?$/', $token)
        ));
    }

    private function extension(?string $contentType, string $url): string
    {
        return match (strtolower((string) $contentType)) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/jpeg', 'image/jpg' => 'jpg',
            default => strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'img',
        };
    }

    private function hasAlpha(string $path): bool
    {
        if (! function_exists('imagecreatefromstring')) {
            return false;
        }
        $image = @imagecreatefromstring((string) file_get_contents($path));
        if (! $image instanceof \GdImage) {
            return false;
        }
        $transparent = false;
        for ($x = 0; $x < imagesx($image); $x += max(1, intdiv(imagesx($image), 20))) {
            for ($y = 0; $y < imagesy($image); $y += max(1, intdiv(imagesy($image), 20))) {
                if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) > 0) {
                    $transparent = true;
                    break 2;
                }
            }
        }
        imagedestroy($image);
        return $transparent;
    }

    private function background(string $path): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return 'unknown';
        }
        $image = @imagecreatefromstring((string) file_get_contents($path));
        if (! $image instanceof \GdImage) {
            return 'unknown';
        }
        $points = [[0, 0], [imagesx($image) - 1, 0], [0, imagesy($image) - 1], [imagesx($image) - 1, imagesy($image) - 1]];
        $light = 0;
        foreach ($points as [$x, $y]) {
            $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y));
            $light += min($rgb['red'], $rgb['green'], $rgb['blue']) > 220 ? 1 : 0;
        }
        imagedestroy($image);
        return $light === 4 ? 'white' : 'unknown';
    }
}
