<?php

namespace App\Services\Documents;

use App\Models\User;

/** Language and number formatting for the PDF documents. */
final class DocumentText
{
    public const LOCALES = ['mk', 'sq', 'en'];

    /** A customer's language: their preferred_locale, else the shop default. */
    public static function localeFor(?User $user, string $fallback = 'mk'): string
    {
        return self::normalise((string) ($user?->preferred_locale ?? ''), $fallback);
    }

    public static function normalise(?string $locale, string $fallback = 'mk'): string
    {
        $locale = strtolower(trim((string) $locale));
        $locale = match ($locale) {
            'shq', 'al', 'sq_al' => 'sq',
            'mk_mk' => 'mk',
            default => substr($locale, 0, 2),
        };

        return in_array($locale, self::LOCALES, true)
            ? $locale
            : (in_array($fallback, self::LOCALES, true) ? $fallback : 'mk');
    }

    /** Whole denars, e.g. "1.540 ден." / "1.540 den." / "1,540 MKD". */
    public static function money(float|int|string|null $amount, string $locale): string
    {
        $rounded = (int) round((float) $amount);
        $number = $locale === 'en'
            ? number_format($rounded, 0, '.', ',')
            : number_format($rounded, 0, ',', '.');

        return $number . ' ' . __('documents.currency', [], $locale);
    }

    public static function date(\DateTimeInterface|string|null $date): string
    {
        if ($date === null || $date === '') {
            return now()->format('d.m.Y');
        }

        return ($date instanceof \DateTimeInterface ? $date : new \DateTimeImmutable($date))->format('d.m.Y');
    }
}
