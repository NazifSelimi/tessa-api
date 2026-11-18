<?php

namespace App\Http\Middleware;

use App\Support\AccessTokens;
use App\Support\ApiUserResolver;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * One rule for every API call that carries a sign-in token:
 * - an expired or unknown token gets 401, also on public pages, so the shop
 *   signs the person out visibly instead of quietly showing guest prices;
 * - a valid token's expiry moves forward (phones stay signed in).
 *
 * Sign-in/sign-up routes are exempt: a phone with an old token must still be
 * able to sign in again.
 */
class CheckBearerToken
{
    private const EXEMPT = [
        'api/v1/auth/login',
        'api/v1/auth/register',
        'api/v1/auth/forgot-password',
        'api/v1/auth/reset-password',
        'api/v1/auth/oauth/*',
        'api/v1/stylist-invitations/*',
        'api/mcp',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        if (!$plain || $request->is(...self::EXEMPT)) {
            return $next($request);
        }

        $user = ApiUserResolver::fromRequest($request);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => __('Your session has expired. Please sign in again.'),
                'code' => 'session_expired',
            ], 401);
        }

        $token = PersonalAccessToken::findToken($plain);
        // AI assistant tokens are for the MCP endpoint only, never a full admin key.
        if ($token && str_starts_with((string) $token->name, AccessTokens::AI_PREFIX)) {
            return response()->json(['success' => false, 'message' => 'This token only works for the AI assistant.'], 403);
        }
        if ($token) {
            AccessTokens::extend($token, $user);
        }

        return $next($request);
    }
}
