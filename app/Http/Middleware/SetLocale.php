<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    private const SUPPORTED_LOCALES = ['en', 'mk', 'sq'];

    private const ALIASES = ['shq' => 'sq', 'al' => 'sq'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->query('locale') ?? $request->header('Accept-Language');
        // "mk-MK,mk;q=0.9" -> "mk"; the shop calls Albanian "shq", Laravel's lang folder is "sq".
        $locale = strtolower(substr((string) $locale, 0, strcspn((string) $locale, ',;-_')));
        $locale = self::ALIASES[$locale] ?? $locale;

        if (in_array($locale, self::SUPPORTED_LOCALES, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
