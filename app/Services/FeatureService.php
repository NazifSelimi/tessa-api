<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Which switchable features are on. Stored as one JSON setting; anything not
 * stored falls back to the default in config/features.php.
 */
class FeatureService
{
    private const SETTING = 'features';

    /** @var array<string, bool>|null */
    private ?array $stored = null;

    /** @return array<string, array{label: string, description: string, area: string, default: bool}> */
    public function registry(): array
    {
        return config('features', []);
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->registry());
    }

    public function enabled(string $key): bool
    {
        if (! $this->exists($key)) {
            return true; // unknown keys are never switched off by accident
        }

        return $this->stored()[$key] ?? (bool) $this->registry()[$key]['default'];
    }

    /** @return array<string, bool> */
    public function states(): array
    {
        $states = [];
        foreach (array_keys($this->registry()) as $key) {
            $states[$key] = $this->enabled($key);
        }

        return $states;
    }

    /** Full list for the admin Features page. */
    public function adminList(): array
    {
        return collect($this->registry())->map(fn (array $feature, string $key) => [
            'key' => $key,
            'label' => $feature['label'],
            'description' => $feature['description'],
            'area' => $feature['area'],
            'enabled' => $this->enabled($key),
            'default' => (bool) $feature['default'],
        ])->values()->all();
    }

    /** @param array<string, bool> $changes */
    public function update(array $changes): array
    {
        $stored = $this->stored();
        foreach ($changes as $key => $on) {
            if ($this->exists($key)) {
                $stored[$key] = (bool) $on;
            }
        }
        Setting::put(self::SETTING, json_encode($stored));
        $this->stored = $stored;

        return $this->states();
    }

    /** @return array<string, bool> */
    private function stored(): array
    {
        if ($this->stored === null) {
            $decoded = json_decode((string) Setting::get(self::SETTING, '{}'), true);
            $this->stored = is_array($decoded) ? array_map('boolval', $decoded) : [];
        }

        return $this->stored;
    }
}
