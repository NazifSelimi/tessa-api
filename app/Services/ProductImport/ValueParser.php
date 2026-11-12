<?php

namespace App\Services\ProductImport;

use App\Models\Product;

/**
 * Turns spreadsheet cells into product values. Prices may be written the
 * Macedonian way ("1.380,00 ден"), the English way ("1,380.00") or as plain
 * numbers; an empty cell always means "leave as it is".
 */
class ValueParser
{
    public static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            $value = (string) (int) $value; // article code 86357 read as 86357.0
        }
        $text = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', (string) $value) ?? '');

        return $text === '' ? null : $text;
    }

    /**
     * @return float|null|false null for an empty cell, false when it is not a number
     */
    public static function number(mixed $value): float|null|false
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $text = trim((string) $value);
        if ($text === '' || $text === '-') {
            return null;
        }

        // Drop currency words and spaces used as thousand separators.
        $text = preg_replace('/(ден|денари|mkd|den|eur|€|lek|all)\.?/iu', '', $text) ?? $text;
        $text = preg_replace("/[\\s\x{00A0}']+/u", '', $text) ?? $text;

        if (!preg_match('/^-?[\d.,]+$/', $text) || !preg_match('/\d/', $text)) {
            return false;
        }

        $lastComma = strrpos($text, ',');
        $lastDot = strrpos($text, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // The separator that comes last is the decimal one.
            $decimal = $lastComma > $lastDot ? ',' : '.';
            $thousands = $decimal === ',' ? '.' : ',';
            $text = str_replace($thousands, '', $text);
            $text = str_replace($decimal, '.', $text);
        } elseif ($lastComma !== false || $lastDot !== false) {
            $separator = $lastComma !== false ? ',' : '.';
            $quoted = preg_quote($separator, '/');
            // 1.380 or 12,500,000 are thousands; 1,5 or 12.75 are decimals.
            if (preg_match('/^-?\d{1,3}(' . $quoted . '\d{3})+$/', $text)) {
                $text = str_replace($separator, '', $text);
            } else {
                if (substr_count($text, $separator) > 1) {
                    return false;
                }
                $text = str_replace($separator, '.', $text);
            }
        }

        return is_numeric($text) ? (float) $text : false;
    }

    /**
     * Audiences from a cell such as "guests, customers, stylists", "hidden",
     * "everyone" or "stylists". Null when empty, false when not understood.
     *
     * @return list<string>|null|false
     */
    public static function visibility(mixed $value): array|null|false
    {
        $text = ColumnMapper::normalise($value);
        if ($text === '') {
            return null;
        }

        $all = Product::AUDIENCES;
        if (in_array($text, ['everyone', 'all', 'yes', 'visible', 'сите', 'видливо', 'да', 'të gjithë', 'te gjithe', 'po', '1', 'true'], true)) {
            return $all;
        }
        if (in_array($text, ['hidden', 'nobody', 'none', 'no', 'скриено', 'никој', 'не', 'fshehur', 'askush', 'jo', '0', 'false'], true)) {
            return [];
        }

        $words = [
            'guests' => 'guests', 'guest' => 'guests', 'гости' => 'guests', 'vizitorë' => 'guests', 'vizitore' => 'guests',
            'customers' => 'customers', 'customer' => 'customers', 'клиенти' => 'customers', 'купувачи' => 'customers', 'klientë' => 'customers', 'kliente' => 'customers',
            'stylists' => 'stylists', 'stylist' => 'stylists', 'salons' => 'stylists', 'business' => 'stylists', 'салони' => 'stylists', 'стилисти' => 'stylists', 'sallone' => 'stylists', 'stilistë' => 'stylists',
        ];

        $audiences = [];
        foreach (preg_split('/[,;\/+|]+|\s+and\s+|\s+и\s+|\s+dhe\s+/u', $text) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (!isset($words[$part])) {
                return false;
            }
            $audiences[$words[$part]] = true;
        }

        return array_values(array_filter($all, fn (string $audience) => isset($audiences[$audience])));
    }

    /** "guests, customers, stylists" / "hidden": how the export writes it. */
    public static function visibilityLabel(array $audiences): string
    {
        return $audiences === [] ? 'hidden' : implode(', ', $audiences);
    }

    /** For matching: lower case, single spaces, article code 0123 stays 0123. */
    public static function key(mixed $value): string
    {
        return ColumnMapper::normalise(self::text($value) ?? '');
    }
}
