<?php

namespace App\Services;

use App\Models\Setting;

/**
 * The shop's identity: name, logo, colours and contact details. Everything the
 * customer sees (emails, footer, posts) reads it from here instead of having
 * one shop's details written into the code, so the platform can run any shop.
 */
class BusinessProfile
{
    public const FIELDS = [
        'name' => 'Tessa',
        'legal_name' => '',
        'tagline' => 'Professional hair care',
        'email' => 'tessa@tessa.mk',
        'phone' => '078 286 003',
        'phone_2' => '042 333 003',
        'address' => '',
        'city' => '',
        'country' => 'North Macedonia',
        'website' => '',
        'instagram' => '',
        'facebook' => '',
        'logo_url' => '',
        'brand_color' => '#9b5364',
        'default_locale' => 'mk',
    ];

    private ?array $cache = null;

    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $stored = json_decode((string) Setting::get('business.profile', '{}'), true);
        $profile = array_merge(self::FIELDS, is_array($stored) ? array_intersect_key($stored, self::FIELDS) : []);
        if ($profile['website'] === '') {
            $profile['website'] = rtrim((string) config('tessa.frontend_url', config('app.url')), '/');
        }
        if ($profile['logo_url'] === '') {
            $profile['logo_url'] = rtrim((string) config('tessa.frontend_url', config('app.url')), '/') . '/email/logo.png';
        }

        return $this->cache = $profile;
    }

    public function get(string $key): string
    {
        return (string) ($this->all()[$key] ?? '');
    }

    public function update(array $values): array
    {
        $stored = json_decode((string) Setting::get('business.profile', '{}'), true);
        $stored = is_array($stored) ? $stored : [];
        foreach (array_intersect_key($values, self::FIELDS) as $key => $value) {
            $stored[$key] = trim((string) ($value ?? ''));
        }
        Setting::put('business.profile', json_encode($stored, JSON_UNESCAPED_UNICODE));
        $this->cache = null;

        return $this->all();
    }

    /** Website host for display, e.g. "tessa.mk". */
    public function websiteLabel(): string
    {
        return preg_replace('#^https?://(www\.)?#', '', rtrim($this->get('website'), '/'));
    }
}
