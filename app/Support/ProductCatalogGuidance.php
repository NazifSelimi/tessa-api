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

    public static function forProduct(?string $category, ?string $name): array
    {
        $category = strtolower(trim((string) $category));
        $name = strtolower(trim((string) $name));
        $isOroTherapy = str_contains($name, 'oro therapy') || str_contains($name, 'orotherapy') || str_contains($name, 'oro puro');
        $isNoYellow = str_contains($name, 'no yellow') || str_contains($name, 'violet');

        $guidance = [
            'audience' => in_array($category, self::PROFESSIONAL_CATEGORIES, true) ? 'professional' : 'consumer',
            'professionalOnly' => in_array($category, self::PROFESSIONAL_CATEGORIES, true),
            'compatibleWith' => [],
        ];

        if (in_array($category, ['hair color', 'bleach and de color'], true)) {
            $guidance['compatibleWith'] = $isOroTherapy
                ? ['Oro Therapy Gold Activator']
                : ($isNoYellow ? ['No Yellow Violet Peroxide'] : ['Fanola Oxy']);
        }

        if ($category === 'activator') {
            $guidance['compatibleWith'] = $isOroTherapy
                ? ['Oro Therapy Color Keratin']
                : ($isNoYellow ? ['No Yellow Color system'] : ['Fanola Color system']);
        }

        if ($category === 'hydrogen peroxide') {
            // Hydrogen/peroxide is compatible with standard colour systems,
            // but Oro Therapy requires its dedicated Gold Activator instead.
            $guidance['compatibleWith'] = $isOroTherapy
                ? []
                : ['Fanola Color', 'No Yellow Color', 'Color Zoom', 'Fanola Bleach'];
        }

        return $guidance;
    }
}
