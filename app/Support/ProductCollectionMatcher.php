<?php

namespace App\Support;

final class ProductCollectionMatcher
{
    public const SOURCE_RELEASE_2 = 'release_2_seed';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_UNCERTAIN = 'uncertain';

    public static function definitions(): array
    {
        return [
            [
                'slug' => 'blonde-and-tone',
                'name' => 'Blonde and Tone',
                'title' => 'Blonde and Tone',
                'description' => 'Tone brassiness, maintain brightness, and support blonde routines with verified Fanola-family products.',
                'sort_priority' => 10,
                'default_routine_roles' => ['cleanse', 'tone', 'nourish', 'protect'],
                'supported_category_names' => ['Shampoo', 'Mask', 'Hair Color', 'Bleach and De Color'],
                'is_active' => true,
            ],
            [
                'slug' => 'repair',
                'name' => 'Repair',
                'title' => 'Repair damaged hair',
                'description' => 'Support damaged or stressed hair with rebuilding routines and verified aftercare.',
                'sort_priority' => 20,
                'default_routine_roles' => ['cleanse', 'repair', 'nourish', 'protect'],
                'supported_category_names' => ['Shampoo', 'Mask', 'Filler', 'Spray', 'Fluid'],
                'is_active' => true,
            ],
            [
                'slug' => 'curls',
                'name' => 'Curls',
                'title' => 'Care for curls',
                'description' => 'Keep curls hydrated, defined, and easy to refresh between washes.',
                'sort_priority' => 30,
                'default_routine_roles' => ['cleanse', 'nourish', 'define', 'protect'],
                'supported_category_names' => ['Shampoo', 'Mask', 'Styling', 'Spray'],
                'is_active' => true,
            ],
            [
                'slug' => 'smooth-and-anti-frizz',
                'name' => 'Smooth and Anti-frizz',
                'title' => 'Smooth and Anti-frizz',
                'description' => 'Reduce frizz, protect against heat, and keep the finish polished.',
                'sort_priority' => 40,
                'default_routine_roles' => ['cleanse', 'nourish', 'smooth', 'protect'],
                'supported_category_names' => ['Fluid', 'Spray', 'Styling'],
                'is_active' => true,
            ],
            [
                'slug' => 'colour',
                'name' => 'Colour',
                'title' => 'Colour services and care',
                'description' => 'Keep colour services tied to verified technical systems and safe maintenance.',
                'sort_priority' => 50,
                'default_routine_roles' => ['tone', 'protect', 'repair'],
                'supported_category_names' => ['Hair Color', 'Hydrogen Peroxide', 'Activator', 'Bleach and De Color'],
                'is_active' => true,
            ],
            [
                'slug' => 'extensions-and-tools',
                'name' => 'Extensions and Tools',
                'title' => 'Extensions and Tools',
                'description' => 'A launch placeholder for verified extension and tool inventory as it is added.',
                'sort_priority' => 60,
                'default_routine_roles' => ['protect'],
                'supported_category_names' => ['Extensions', 'Brushes and Tools'],
                'is_active' => true,
            ],
        ];
    }

    public static function matchBlondeAndTone(
        ?string $brand,
        ?string $category,
        ?string $name
    ): ?array {
        $brand = self::normalize($brand);
        $category = self::normalize($category);
        $name = self::normalize($name);

        if ($name === '' || !self::isFanolaFamily($brand, $name)) {
            return null;
        }

        if (self::isConfirmedBlondeAndTone($category, $name)) {
            return [
                'collection_slug' => 'blonde-and-tone',
                'mapping_status' => self::STATUS_CONFIRMED,
                'source' => self::SOURCE_RELEASE_2,
                'notes' => self::mappingNote(self::STATUS_CONFIRMED, $category, $name),
            ];
        }

        if (self::isUncertainBlondeAndTone($category, $name)) {
            return [
                'collection_slug' => 'blonde-and-tone',
                'mapping_status' => self::STATUS_UNCERTAIN,
                'source' => self::SOURCE_RELEASE_2,
                'notes' => self::mappingNote(self::STATUS_UNCERTAIN, $category, $name),
            ];
        }

        return null;
    }

    private static function isFanolaFamily(string $brand, string $name): bool
    {
        return in_array($brand, ['fanola', 'no yellow color'], true)
            || str_contains($name, 'fanola');
    }

    private static function isConfirmedBlondeAndTone(string $category, string $name): bool
    {
        if (in_array($category, ['shampoo', 'mask', 'conditioner'], true)) {
            return self::containsAny($name, ['no yellow', 'no orange', 'real silver']);
        }

        if ($category === 'other') {
            return self::containsAny($name, ['no yellow incredible foam', 'no orange blue foam']);
        }

        if ($category === 'hair color') {
            return self::containsAny($name, ['toner', 'superlight blonde', 'blonde platinum']);
        }

        if ($category === 'bleach and de color') {
            return self::containsAny($name, ['no yellow', 'lightener', 'bleach', '9 tone']);
        }

        return false;
    }

    private static function isUncertainBlondeAndTone(string $category, string $name): bool
    {
        if ($category === 'hair color' && self::containsAny($name, ['blonde', 'silver', 'violet'])) {
            return true;
        }

        if (self::containsAny($name, ['fiber fix', 'hydrogen violet'])) {
            return true;
        }

        return false;
    }

    private static function mappingNote(string $status, string $category, string $name): string
    {
        if ($status === self::STATUS_CONFIRMED) {
            return match (true) {
                in_array($category, ['shampoo', 'mask', 'conditioner'], true) => 'Mapped from an explicit Fanola-family maintenance or toning line name.',
                $category === 'hair color' => 'Mapped from an explicit blonde or toner technical name.',
                $category === 'bleach and de color' => 'Mapped from an explicit blonding or lightening technical name.',
                default => 'Mapped from an explicit Fanola-family Blonde and Tone name.',
            };
        }

        if (str_contains($name, 'fiber fix')) {
            return 'Flagged for review because Fiber Fix may support blonding services, but the customer-facing collection is not certain.';
        }

        if (str_contains($name, 'hydrogen violet')) {
            return 'Flagged for review because the product looks tone-adjacent, but its verified release placement is unclear.';
        }

        return 'Flagged for review because the name suggests blonde or tone relevance without a clear launch-safe placement.';
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

    private static function normalize(?string $value): string
    {
        return strtolower(trim((string) $value));
    }
}
