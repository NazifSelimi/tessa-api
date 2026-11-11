<?php

namespace App\Services\Documents;

use App\Services\BusinessProfile;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The shop details printed on PDF documents (name, contacts, logo, colour).
 * The only place the PDFs read the Business profile, so a change there means
 * one change here. If the profile cannot be read the defaults below are used.
 */
class DocumentShop
{
    public const DEFAULTS = [
        'name' => 'Tessa',
        'legal_name' => '',
        'tagline' => '',
        'email' => 'tessa@tessa.mk',
        'phone' => '078 286 003',
        'phone_2' => '042 333 003',
        'address' => '',
        'city' => '',
        'country' => '',
        'website' => '',
        'logo_url' => '',
        'brand_color' => '#9b5364',
        'default_locale' => 'mk',
    ];

    private ?array $details = null;

    private ?string $logo = null;

    private bool $logoLoaded = false;

    public function __construct(private readonly ?BusinessProfile $profile = null) {}

    /** @return array<string, string> */
    public function details(): array
    {
        if ($this->details !== null) {
            return $this->details;
        }

        $stored = [];
        try {
            $stored = $this->profile?->all() ?? [];
        } catch (Throwable) {
            // No database / no settings yet: print the defaults.
        }

        $details = self::DEFAULTS;
        foreach (self::DEFAULTS as $key => $default) {
            $value = trim((string) ($stored[$key] ?? ''));
            if ($value !== '') {
                $details[$key] = $value;
            }
        }
        if (! preg_match('/^#[0-9a-fA-F]{3,8}$/', $details['brand_color'])) {
            $details['brand_color'] = self::DEFAULTS['brand_color'];
        }
        $details['website_label'] = (string) preg_replace('#^https?://(www\.)?#', '', rtrim($details['website'], '/'));

        return $this->details = $details;
    }

    public function get(string $key): string
    {
        return (string) ($this->details()[$key] ?? '');
    }

    /** Phones joined for one line, e.g. "078 286 003 · 042 333 003". */
    public function phones(): string
    {
        return implode(' · ', array_filter([$this->get('phone'), $this->get('phone_2')]));
    }

    /** One line address, empty when the shop has not entered one yet. */
    public function addressLine(): string
    {
        return implode(', ', array_filter([$this->get('address'), $this->get('city'), $this->get('country')]));
    }

    public function defaultLocale(): string
    {
        $locale = $this->get('default_locale');

        return in_array($locale, DocumentText::LOCALES, true) ? $locale : 'mk';
    }

    /**
     * The logo as a data URI that dompdf can draw without going online, or
     * null (the shop name is printed instead). Local files are read directly;
     * a web address is fetched once with a short timeout.
     */
    public function logoDataUri(): ?string
    {
        if ($this->logoLoaded) {
            return $this->logo;
        }
        $this->logoLoaded = true;

        $url = $this->get('logo_url');
        if ($url === '') {
            return null;
        }

        try {
            $bytes = null;
            $local = $this->localPath($url);
            if ($local !== null) {
                $bytes = file_get_contents($local) ?: null;
            } elseif (preg_match('#^https?://#', $url)) {
                $response = Http::timeout(4)->connectTimeout(3)->get($url);
                $bytes = $response->successful() ? $response->body() : null;
            }
            if ($bytes === null || strlen($bytes) > 3_000_000) {
                return null;
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';
            if (! in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
                return null;
            }

            return $this->logo = 'data:' . $mime . ';base64,' . base64_encode($bytes);
        } catch (Throwable) {
            return null;
        }
    }

    /** Map our own /storage/... URL to the file on disk. */
    private function localPath(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || str_contains($path, '..')) {
            return null;
        }
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $host = parse_url($url, PHP_URL_HOST);
        if ($host !== null && $host !== $appHost) {
            return null;
        }
        $file = public_path(ltrim($path, '/'));

        return is_file($file) ? $file : null;
    }
}
