<?php

namespace App\Support;

/**
 * Laravel's Albanian locale is "sq" (lang/sq), but product translations are stored as "shq"
 * (the shop's own code). Use this whenever reading locale-keyed shop content.
 */
final class ContentLocale
{
    public static function current(): string
    {
        $locale = app()->getLocale();

        return $locale === 'sq' ? 'shq' : $locale;
    }
}
