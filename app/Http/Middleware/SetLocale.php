<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    private const SUPPORTED_LOCALES = ['en', 'mk', 'shq'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->query('locale')
            ?? $request->header('Accept-Language');

        if ($locale && in_array($locale, self::SUPPORTED_LOCALES, true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
