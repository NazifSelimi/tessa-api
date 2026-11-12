<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

final class ApiUserResolver
{
    public static function fromRequest(Request $request): ?User
    {
        // Resources call this once per product; resolve the token once per request.
        if ($request->attributes->has('api_user')) {
            return $request->attributes->get('api_user');
        }

        $user = self::resolve($request);
        $request->attributes->set('api_user', $user);

        return $user;
    }

    private static function resolve(Request $request): ?User
    {
        $token = $request->bearerToken();

        if (!$token) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);
        $expiration = config('sanctum.expiration');
        if (!$accessToken
            || ($accessToken->expires_at && $accessToken->expires_at->isPast())
            || ($expiration && !$accessToken->created_at->gt(now()->subMinutes($expiration)))) {
            return null;
        }
        $tokenable = $accessToken?->tokenable;

        return $tokenable instanceof User ? $tokenable : null;
    }

    public static function canAccessStylistOnlyProducts(?User $user): bool
    {
        return $user?->isStylist() || $user?->isAdmin();
    }
}
