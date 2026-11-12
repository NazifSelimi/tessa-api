<?php

namespace App\Support;

class ProductCatalogGuidance
{
    private const PROFESSIONAL_CATEGORIES = [
        'hair color',
        'activator',
        'hydrogen peroxide',
        'bleach and de color',
        'tester',
    ];

    private const TONE_KEYWORDS = [
        'no yellow',
        'no orange',
        'no red',
        'silver',
        'toner',
    ];

    private const REPAIR_KEYWORDS = [
        'repair',
        'restructuring',
        'reconstruct',
        'botugen',
        'botolife',
        'fiber fix',
        'bond',
        'filler',
    ];

    private const PROTECT_KEYWORDS = [
        'protect',
        'protective',
        'thermal',
        'thermo',
        'heat',
        'shield',
    ];

    private const CURL_KEYWORDS = [
        'curl',
        'curly',
    ];

    private const SMOOTH_KEYWORDS = [
        'smooth',
        'anti frizz',
        'anti-frizz',
        'keraterm',
        'gloss',
        'glossing',
        'crystals',
        'silk',
    ];

    public static function forProduct(?string $category, ?string $name): array
    {
        $category = strtolower(trim((string) $category));
        $name = strtolower(trim((string) $name));
        $isOroTherapy = str_contains($name, 'oro therapy') || str_contains($name, 'orotherapy') || str_contains($name, 'oro puro');
        $isNoYellow = str_contains($name, 'no yellow') || str_contains($name, 'violet');

        $professionalOnly = in_array($category, self::PROFESSIONAL_CATEGORIES, true);
        $compatibleWith = [];

        if (in_array($category, ['hair color', 'bleach and de color'], true)) {
            $compatibleWith = $isOroTherapy
                ? ['Oro Therapy Gold Activator']
                : ($isNoYellow ? ['No Yellow Violet Peroxide'] : ['Fanola Oxy']);
        }

        if ($category === 'activator') {
            $compatibleWith = $isOroTherapy
                ? ['Oro Therapy Color Keratin']
                : ($isNoYellow ? ['No Yellow Color system'] : ['Fanola Color system']);
        }

        if ($category === 'hydrogen peroxide') {
            // Hydrogen/peroxide is compatible with standard colour systems,
            // but Oro Therapy requires its dedicated Gold Activator instead.
            $compatibleWith = $isOroTherapy
                ? []
                : ['Fanola Color', 'No Yellow Color', 'Color Zoom', 'Fanola Bleach'];
        }

        return [
            'audience' => $professionalOnly ? 'professional' : 'consumer',
            'professionalOnly' => $professionalOnly,
            'consumerRoutineRole' => $professionalOnly
                ? null
                : self::consumerRoutineRoleFor($category, $name),
            'compatibleWith' => $compatibleWith,
            'professionalGuidance' => $professionalOnly ? [
                'compatibleSystems' => $compatibleWith,
                'verificationStatus' => 'compatible_system_only',
                'notes' => array_values(array_filter([
                    'Use only the verified compatible system for this technical product.',
                    $isOroTherapy
                        ? 'Oro Therapy keeps its dedicated Gold system and should not be substituted with standard peroxide.'
                        : null,
                    'Developer ratios, timing, and substitutions are intentionally omitted until manufacturer validation.',
                ])),
            ] : null,
        ];
    }

    private static function consumerRoutineRoleFor(string $category, string $name): ?string
    {
        if (self::containsAny($name, self::TONE_KEYWORDS)) {
            return 'tone';
        }

        if (self::containsAny($name, self::PROTECT_KEYWORDS)) {
            return 'protect';
        }

        if (self::containsAny($name, self::CURL_KEYWORDS)) {
            return 'define';
        }

        if (self::containsAny($name, self::SMOOTH_KEYWORDS)) {
            return 'smooth';
        }

        if (self::containsAny($name, self::REPAIR_KEYWORDS)) {
            return 'repair';
        }

        return match ($category) {
            'shampoo' => 'cleanse',
            'mask', 'conditioner' => 'nourish',
            'filler', 'lotion' => 'repair',
            default => null,
        };
    }

    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
