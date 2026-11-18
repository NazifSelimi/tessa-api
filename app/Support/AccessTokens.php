<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sign-in tokens for the shop (phones stay signed in).
 *
 * Every token gets its own expires_at: customers and business accounts
 * 90 days, admins 14 days (config/sanctum.php lifetimes). Each day a token
 * is used its expiry moves forward again, so someone who opens the shop at
 * least once per lifetime is never signed out. AI (MCP) tokens keep the
 * fixed expiry they were created with.
 */
final class AccessTokens
{
    public static function issue(User $user, string $name): string
    {
        return $user->createToken($name, ['*'], self::expiryFor($user))->plainTextToken;
    }

    public static function expiryFor(User $user): Carbon
    {
        $days = $user->isAdmin()
            ? (int) config('sanctum.lifetime_days.admin', 14)
            : (int) config('sanctum.lifetime_days.default', 90);

        return now()->addDays(max(1, $days));
    }

    /** Push the expiry forward, at most one database write per token per day. */
    public static function extend(PersonalAccessToken $token, User $user): void
    {
        if (str_starts_with((string) $token->name, 'ai-assistant: ') || !$token->expires_at) {
            return;
        }

        $target = self::expiryFor($user);
        if ($token->expires_at->lt($target->copy()->subDay())) {
            $token->forceFill(['expires_at' => $target])->save();
        }
    }
}
