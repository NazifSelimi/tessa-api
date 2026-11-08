<?php

namespace App\Listeners;

use App\Models\Order;
use App\Models\User;
use App\Services\BusinessProfile;

/** Which language a customer's email is written in. */
final class EmailLocale
{
    public const SUPPORTED = ['mk', 'sq', 'en'];

    public static function forUser(?User $user): string
    {
        $preferred = strtolower((string) ($user?->preferred_locale ?? ''));
        $preferred = $preferred === 'shq' || $preferred === 'al' ? 'sq' : $preferred;

        return in_array($preferred, self::SUPPORTED, true) ? $preferred : self::fallback();
    }

    public static function forOrder(Order $order): string
    {
        return self::forUser($order->user);
    }

    public static function fallback(): string
    {
        $default = app(BusinessProfile::class)->get('default_locale');

        return in_array($default, self::SUPPORTED, true) ? $default : 'mk';
    }
}
