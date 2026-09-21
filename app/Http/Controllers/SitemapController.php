<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductCollection;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    private const LOCALES = ['mk', 'sq', 'en'];

    public function __invoke(): Response
    {
        $baseUrl = rtrim((string) config('tessa.site_url', 'https://tessa.mk'), '/');
        $today = now()->toDateString();
        $paths = collect([
            $this->entry('', $today, '1.0'),
            $this->entry('/shop', $today, '0.9'),
            $this->entry('/for-professionals', $today, '0.7'),
            $this->entry('/quiz', $today, '0.8'),
            $this->entry('/privacy', $today, '0.3'),
            $this->entry('/terms', $today, '0.3'),
            $this->entry('/returns', $today, '0.5'),
            $this->entry('/delivery', $today, '0.5'),
            $this->entry('/contact', $today, '0.6'),
        ]);

        ProductCollection::query()
            ->select(['slug', 'updated_at'])
            ->orderBy('sort_priority')
            ->get()
            ->each(fn (ProductCollection $collection) => $paths->push($this->entry(
                '/collections/'.$collection->slug,
                $collection->updated_at?->toDateString() ?? $today,
                '0.8'
            )));

        Product::query()
            ->where('stylist_only', false)
            ->select(['id', 'updated_at'])
            ->orderBy('id')
            ->chunkById(500, function ($products) use ($paths, $today) {
                foreach ($products as $product) {
                    $paths->push($this->entry(
                        '/product/'.$product->id,
                        $product->updated_at?->toDateString() ?? $today,
                        '0.7'
                    ));
                }
            });

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" ';
        $xml .= 'xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n";

        foreach ($paths as $entry) {
            foreach (self::LOCALES as $locale) {
                $xml .= $this->renderUrl($baseUrl, $entry, $locale);
            }
        }

        $xml .= '</urlset>'."\n";

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    private function entry(string $path, string $lastmod, string $priority): array
    {
        return compact('path', 'lastmod', 'priority');
    }

    private function renderUrl(string $baseUrl, array $entry, string $locale): string
    {
        $xml = "  <url>\n";
        $xml .= '    <loc>'.$this->escape($baseUrl.'/'.$locale.$entry['path'])."</loc>\n";
        $xml .= '    <lastmod>'.$entry['lastmod']."</lastmod>\n";
        $xml .= '    <priority>'.$entry['priority']."</priority>\n";

        foreach (self::LOCALES as $alternateLocale) {
            $href = $baseUrl.'/'.$alternateLocale.$entry['path'];
            $xml .= '    <xhtml:link rel="alternate" hreflang="'.$alternateLocale;
            $xml .= '" href="'.$this->escape($href).'" />'."\n";
        }

        $xml .= '    <xhtml:link rel="alternate" hreflang="x-default" href="';
        $xml .= $this->escape($baseUrl.'/mk'.$entry['path']).'" />'."\n";
        $xml .= "  </url>\n";

        return $xml;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
