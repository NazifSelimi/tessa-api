<?php

namespace App\Http\Middleware;

use App\Services\FeatureService;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks routes of a feature an admin switched off on /admin/features.
 * Usage: ->middleware('feature:hair_quiz') or 'feature:bundles,empty' to answer
 * with an empty list instead of an error (for lists the shop always loads).
 */
class EnsureFeatureEnabled
{
    public function __construct(private readonly FeatureService $features) {}

    public function handle(Request $request, Closure $next, string $feature, ?string $whenOff = null): Response
    {
        if ($this->features->enabled($feature)) {
            return $next($request);
        }

        if ($whenOff === 'empty') {
            return ApiResponse::ok([]);
        }

        return ApiResponse::error('This feature is switched off.', 404, ['feature' => $feature, 'code' => 'feature_off']);
    }
}
