<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

final class ApiUserResolver
{
    public static function fromRequest(Request $request): ?User
    {
        $token = $request->bearerToken();

        if (!$token) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);
        $tokenable = $accessToken?->tokenable;

        return $tokenable instanceof User ? $tokenable : null;
    }

    public static function canAccessStylistOnlyProducts(?User $user): bool
    {
        return $user?->isStylist() || $user?->isAdmin();
    }
}
