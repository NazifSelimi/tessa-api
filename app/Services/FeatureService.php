<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Which switchable features are on for this shop. Each feature has an on/off
 * switch stored in the Setting "features"; anything not stored falls back to
 * the default in config/features.php.
 */
class FeatureService
{
    private const SWITCHES = 'features';

    /** @var array<string, bool>|null */
    private ?array $switches = null;

    /** @return array<string, array{label: string, description: string, area: string, default: bool}> */
    public function registry(): array
    {
        return config('features', []);
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->registry());
    }

    /** Switched on. Unknown keys are never switched off by accident. */
    public function enabled(string $key): bool
    {
        if (! $this->exists($key)) {
            return true;
        }

        return $this->switchedOn($key);
    }

    public function switchedOn(string $key): bool
    {
        return $this->switches()[$key] ?? (bool) ($this->registry()[$key]['default'] ?? true);
    }

    /** Public state for the shop and admin UI: {key: {enabled}}. */
    public function states(): array
    {
        $states = [];
        foreach (array_keys($this->registry()) as $key) {
            $states[$key] = ['enabled' => $this->switchedOn($key)];
        }

        return $states;
    }

    /** Full list for the admin Features page. */
    public function adminList(): array
    {
        return [
            'features' => collect($this->registry())->map(fn (array $feature, string $key) => [
                'key' => $key,
                'label' => $feature['label'],
                'description' => $feature['description'],
                'area' => $feature['area'],
                'enabled' => $this->switchedOn($key),
                'default' => (bool) ($feature['default'] ?? true),
            ])->values()->all(),
        ];
    }

    /** @param array<string, bool> $changes */
    public function update(array $changes): void
    {
        $switches = $this->switches();
        foreach ($changes as $key => $on) {
            if ($this->exists($key)) {
                $switches[$key] = (bool) $on;
            }
        }
        Setting::put(self::SWITCHES, json_encode($switches));
        $this->switches = $switches;
    }

    /** @return array<string, bool> */
    private function switches(): array
    {
        if ($this->switches === null) {
            $decoded = json_decode((string) Setting::get(self::SWITCHES, '{}'), true);
            $this->switches = is_array($decoded) ? array_map('boolval', $decoded) : [];
        }

        return $this->switches;
    }
}
