<?php

namespace App\Support\Customers;

/**
 * Stable keys for one customer: "user-12" for an account, "email-ana@x.mk" or
 * "phone-70123456" (last 8 digits) for a guest buyer. Used in URLs and to
 * store the admin's notes and tags.
 */
final class CustomerKey
{
    public static function forUser(int $id): string
    {
        return 'user-' . $id;
    }

    public static function forEmail(string $email): string
    {
        return 'email-' . $email;
    }

    public static function forPhone(string $phone): string
    {
        return 'phone-' . $phone;
    }

    public static function email(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        return $email !== '' && str_contains($email, '@') ? $email : null;
    }

    /** Last 8 digits: the same Macedonian number with or without +389 / leading 0. */
    public static function phone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return strlen($digits) >= 8 ? substr($digits, -8) : null;
    }

    /** User id inside a "user-12" key, or null for a guest key. */
    public static function userId(string $key): ?int
    {
        return preg_match('/^user-(\d+)$/', $key, $m) ? (int) $m[1] : null;
    }

    public static function isValid(string $key): bool
    {
        return (bool) preg_match('/^(user-\d+|email-[^\s\/]+@[^\s\/]+|phone-\d{8}|order-\d+)$/', $key);
    }
}
