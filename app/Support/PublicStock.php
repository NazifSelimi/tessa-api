<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Exact stock is for admins. Everyone else sees at most CAP: enough for
 * "only 3 left" and the quantity picker, but competitors cannot read the
 * real stock level.
 */
final class PublicStock
{
    public const CAP = 50;

    public static function shown(Request $request, int $quantity): int
    {
        if (ApiUserResolver::fromRequest($request)?->isAdmin()) {
            return $quantity;
        }

        return max(0, min($quantity, self::CAP));
    }
}
