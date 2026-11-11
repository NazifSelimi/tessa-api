<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Which switchable features work for this shop. A feature works when the
 * shop's subscription plan includes it (basic ⊂ pro ⊂ premium) AND its switch
 * is on. Switches, per-feature plan levels and the shop's plan are Settings;
 * anything not stored falls back to config/features.php.
 */
class FeatureService
{
    public const PLANS = ['basic', 'pro', 'premium'];

    private const SWITCHES = 'features';
    private const PLAN_LEVELS = 'features.plans';
    private const SHOP_PLAN = 'business.plan';

    /** @var array<string, bool>|null */
    private ?array $switches = null;

    /** @var array<string, string>|null */
    private ?array $planLevels = null;

    private ?string $shopPlan = null;

    /** @return array<string, array{label: string, description: string, area: string, default: bool, plan?: string}> */
    public function registry(): array
    {
        return config('features', []);
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->registry());
    }

    /** Works right now: in the plan and switched on. Unknown keys are never switched off by accident. */
    public function enabled(string $key): bool
    {
        if (! $this->exists($key)) {
            return true;
        }

        return $this->switchedOn($key) && $this->included($key);
    }

    public function switchedOn(string $key): bool
    {
        return $this->switches()[$key] ?? (bool) ($this->registry()[$key]['default'] ?? true);
    }

    /** The lowest plan that includes the feature. */
    public function planOf(string $key): string
    {
        $plan = $this->planLevels()[$key] ?? ($this->registry()[$key]['plan'] ?? 'basic');

        return in_array($plan, self::PLANS, true) ? $plan : 'basic';
    }

    public function shopPlan(): string
    {
        if ($this->shopPlan === null) {
            $plan = (string) Setting::get(self::SHOP_PLAN, 'premium');
            $this->shopPlan = in_array($plan, self::PLANS, true) ? $plan : 'premium';
        }

        return $this->shopPlan;
    }

    public function included(string $key): bool
    {
        return array_search($this->planOf($key), self::PLANS, true) <= array_search($this->shopPlan(), self::PLANS, true);
    }

    /** Public state for the shop and admin UI: {key: {enabled, plan, included}}. */
    public function states(): array
    {
        $states = [];
        foreach (array_keys($this->registry()) as $key) {
            $states[$key] = ['enabled' => $this->switchedOn($key), 'plan' => $this->planOf($key), 'included' => $this->included($key)];
        }

        return $states;
    }

    /** Full list for the admin Features page. */
    public function adminList(): array
    {
        return [
            'plan' => $this->shopPlan(),
            'plans' => self::PLANS,
            'features' => collect($this->registry())->map(fn (array $feature, string $key) => [
                'key' => $key,
                'label' => $feature['label'],
                'description' => $feature['description'],
                'area' => $feature['area'],
                'enabled' => $this->switchedOn($key),
                'default' => (bool) ($feature['default'] ?? true),
                'plan' => $this->planOf($key),
                'defaultPlan' => $feature['plan'] ?? 'basic',
                'included' => $this->included($key),
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

    /** @param array<string, string> $levels */
    public function updatePlanLevels(array $levels): void
    {
        $stored = $this->planLevels();
        foreach ($levels as $key => $plan) {
            if ($this->exists($key) && in_array($plan, self::PLANS, true)) {
                $stored[$key] = $plan;
            }
        }
        Setting::put(self::PLAN_LEVELS, json_encode($stored));
        $this->planLevels = $stored;
    }

    public function setShopPlan(string $plan): void
    {
        if (in_array($plan, self::PLANS, true)) {
            Setting::put(self::SHOP_PLAN, $plan);
            $this->shopPlan = $plan;
        }
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

    /** @return array<string, string> */
    private function planLevels(): array
    {
        if ($this->planLevels === null) {
            $decoded = json_decode((string) Setting::get(self::PLAN_LEVELS, '{}'), true);
            $this->planLevels = is_array($decoded) ? $decoded : [];
        }

        return $this->planLevels;
    }
}
