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

        if ($category === 'activator') {
            $guidance['compatibleWith'] = $isOroTherapy
                ? ['Oro Therapy Color Keratin']
                : ($isNoYellow
                    ? ['Fanola Color', 'No Yellow Color', 'Fanola Toner', 'No Yellow Color Bleach']
                    : ['Fanola Color', 'No Yellow Color', 'Color Zoom', 'Fanola Bleach']);
        }

        if ($category === 'hydrogen peroxide') {
            $guidance['compatibleWith'] = $isOroTherapy
                ? ['Oro Therapy Color Keratin']
                : ['Fanola Color', 'No Yellow Color', 'Color Zoom', 'Fanola Bleach'];
        }

        if ($category === 'bleach and de color') {
            $guidance['compatibleWith'] = $isOroTherapy
                ? ['Oro Therapy Gold Activator']
                : ($isNoYellow ? ['No Yellow Violet Peroxide'] : ['Fanola Oxy']);
        }

        if ($category === 'hair color') {
            $guidance['compatibleWith'] = $isOroTherapy
                ? ['Oro Therapy Gold Activator']
                : ($isNoYellow ? ['No Yellow Violet Peroxide'] : ['Fanola Oxy']);
        }

        return $guidance;
    }
}
