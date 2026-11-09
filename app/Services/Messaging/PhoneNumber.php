<?php

namespace App\Services\Messaging;

/**
 * Phone numbers as the international digits WhatsApp and Viber expect.
 * Macedonian numbers are written many ways (078 286 332, +389 78..., 00389...,
 * 78286332); all become 38978286332. Same rules as the admin UI.
 */
final class PhoneNumber
{
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (str_starts_with($digits, '0')) {
            $digits = '389'.substr($digits, 1);
        } elseif (preg_match('/^[2-7]\d{7}$/', $digits)) {
            $digits = '389'.$digits;
        }
        $length = strlen($digits);

        return $length >= 10 && $length <= 15 ? $digits : null;
    }
}
