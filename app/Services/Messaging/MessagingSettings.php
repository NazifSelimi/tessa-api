<?php

namespace App\Services\Messaging;

use App\Models\Setting;
use App\Services\BusinessProfile;
use App\Services\FeatureService;
use Illuminate\Support\Facades\Crypt;

/**
 * Settings for Viber / WhatsApp messages on /admin/messages:
 *  - the admin's own message templates (MK / SQ / EN) for the free one-click links;
 *  - the optional automatic WhatsApp Cloud API (Meta). It is OFF until an admin
 *    connects their own Meta account and switches it on. Meta bills per message,
 *    so nothing is ever sent while it is off. The access token is stored encrypted
 *    and never sent back to the browser.
 */
class MessagingSettings
{
    /** Order statuses that can send an automatic WhatsApp message. */
    public const STATUSES = [
        'received' => 'Order received (just placed)',
        'confirmed' => 'Order confirmed',
        'shipped' => 'Order shipped',
        'cancelled' => 'Order cancelled',
    ];

    /**
     * Automatic WhatsApp is a Premium plan feature. The only place the gate lives:
     * routes use `feature:whatsapp_auto` and the sender asks premiumAllowed().
     * Until plan tiers exist, FeatureService treats the unknown key as on.
     */
    public const PREMIUM_FEATURE = 'whatsapp_auto';

    public static function premiumAllowed(): bool
    {
        return app(FeatureService::class)->enabled(self::PREMIUM_FEATURE);
    }

    public const DEFAULT_API_VERSION = 'v23.0';

    public function __construct(private readonly BusinessProfile $business) {}

    // ---- Free click-to-send templates -------------------------------------------------

    /** @return array{templates: list<array<string, mixed>>, shop: string, link: string} */
    public function templates(): array
    {
        $stored = json_decode((string) Setting::get('messaging.templates', '[]'), true);
        $profile = $this->business->all();

        return [
            // Only templates the admin edited or added; the UI fills in the built-in ones.
            'templates' => is_array($stored) ? array_values($stored) : [],
            'shop' => (string) Setting::get('messaging.shop', '') ?: (string) $profile['name'],
            'link' => (string) Setting::get('messaging.link', '') ?: (string) $profile['website'],
        ];
    }

    /** @param array{templates?: list<array<string, mixed>>, shop?: string|null, link?: string|null} $data */
    public function saveTemplates(array $data): array
    {
        if (array_key_exists('templates', $data)) {
            $clean = collect($data['templates'])->map(fn (array $t) => [
                'key' => (string) $t['key'],
                'label' => trim((string) ($t['label'] ?? '')),
                'mk' => (string) ($t['mk'] ?? ''),
                'sq' => (string) ($t['sq'] ?? ''),
                'en' => (string) ($t['en'] ?? ''),
                'custom' => (bool) ($t['custom'] ?? false),
            ])->unique('key')->values()->all();
            Setting::put('messaging.templates', json_encode($clean, JSON_UNESCAPED_UNICODE));
        }
        foreach (['shop', 'link'] as $field) {
            if (array_key_exists($field, $data)) {
                Setting::put("messaging.$field", trim((string) $data[$field]));
            }
        }

        return $this->templates();
    }

    // ---- Optional automatic WhatsApp (Cloud API) --------------------------------------

    public function raw(string $key, mixed $default = null): mixed
    {
        return Setting::get("whatsapp.$key", $default);
    }

    /** @return array<string, array{name: string, language: string, params: int}> */
    public function statusTemplates(): array
    {
        $stored = json_decode((string) $this->raw('status_templates', '{}'), true);
        $stored = is_array($stored) ? $stored : [];
        $result = [];
        foreach (array_keys(self::STATUSES) as $status) {
            $row = is_array($stored[$status] ?? null) ? $stored[$status] : [];
            $result[$status] = [
                'name' => trim((string) ($row['name'] ?? '')),
                'language' => trim((string) ($row['language'] ?? '')) ?: 'mk',
                'params' => max(0, min(3, (int) ($row['params'] ?? 0))),
            ];
        }

        return $result;
    }

    public function enabled(): bool
    {
        return (bool) (int) $this->raw('enabled', 0);
    }

    public function connected(): bool
    {
        return $this->phoneNumberId() !== '' && $this->accessToken() !== null;
    }

    public function phoneNumberId(): string
    {
        return trim((string) $this->raw('phone_number_id', ''));
    }

    public function apiVersion(): string
    {
        $version = trim((string) $this->raw('api_version', ''));

        return preg_match('/^v\d+\.\d+$/', $version) ? $version : self::DEFAULT_API_VERSION;
    }

    public function testNumber(): ?string
    {
        return PhoneNumber::normalize((string) $this->raw('test_number', ''));
    }

    public function accessToken(): ?string
    {
        $stored = (string) $this->raw('access_token', '');
        if ($stored === '') {
            return null;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Settings for the admin page; the token is never sent back. */
    public function whatsappForAdmin(): array
    {
        $log = json_decode((string) $this->raw('log', '[]'), true);

        return [
            'enabled' => $this->enabled(),
            'connected' => $this->connected(),
            'phoneNumberId' => $this->phoneNumberId(),
            'businessAccountId' => (string) $this->raw('business_account_id', ''),
            'apiVersion' => $this->apiVersion(),
            'hasToken' => (string) $this->raw('access_token', '') !== '',
            'testNumber' => (string) $this->raw('test_number', ''),
            'statusTemplates' => $this->statusTemplates(),
            'statuses' => collect(self::STATUSES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'isProduction' => app()->isProduction(),
            'recent' => is_array($log) ? $log : [],
        ];
    }

    public function saveWhatsApp(array $data): array
    {
        $map = [
            'phoneNumberId' => 'phone_number_id', 'businessAccountId' => 'business_account_id',
            'apiVersion' => 'api_version', 'testNumber' => 'test_number',
        ];
        foreach ($map as $input => $key) {
            if (array_key_exists($input, $data)) {
                Setting::put("whatsapp.$key", trim((string) $data[$input]));
            }
        }
        // Empty token field = keep the saved one; "clearToken" removes it (and switches sending off).
        if (! empty($data['accessToken'])) {
            Setting::put('whatsapp.access_token', Crypt::encryptString(trim((string) $data['accessToken'])));
        } elseif (! empty($data['clearToken'])) {
            Setting::put('whatsapp.access_token', '');
            Setting::put('whatsapp.enabled', 0);
        }
        if (array_key_exists('statusTemplates', $data)) {
            $templates = [];
            foreach (array_keys(self::STATUSES) as $status) {
                $row = $data['statusTemplates'][$status] ?? [];
                $templates[$status] = [
                    'name' => trim((string) ($row['name'] ?? '')),
                    'language' => trim((string) ($row['language'] ?? '')) ?: 'mk',
                    'params' => max(0, min(3, (int) ($row['params'] ?? 0))),
                ];
            }
            Setting::put('whatsapp.status_templates', json_encode($templates));
        }
        if (array_key_exists('enabled', $data)) {
            Setting::put('whatsapp.enabled', $data['enabled'] && $this->connected() ? 1 : 0);
        }

        return $this->whatsappForAdmin();
    }

    /** Keeps the last 20 automatic sends so the admin can see what happened. */
    public function remember(array $entry): void
    {
        $log = json_decode((string) $this->raw('log', '[]'), true);
        $log = is_array($log) ? $log : [];
        array_unshift($log, $entry + ['at' => now()->toIso8601String()]);
        Setting::put('whatsapp.log', json_encode(array_slice($log, 0, 20), JSON_UNESCAPED_UNICODE));
    }
}
