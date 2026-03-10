<?php

namespace App\Services;

use App\Models\DistributorCode;
use App\Models\User;
use Illuminate\Support\Str;

class StylistCodeService
{
    /**
     * Get all invitation codes created by a given user.
     */
    public function listByUser(User $user)
    {
        return DistributorCode::where('created_by', $user->id)
            ->latest()
            ->get();
    }

    /**
     * Generate a new unique invitation code for a stylist.
     */
    public function generate(User $user): DistributorCode
    {
        $baseCode = 'STYLIST-' . strtoupper($user->first_name ?? 'USER') . '-' . strtoupper(Str::random(5));

        while (DistributorCode::where('code', $baseCode)->exists()) {
            $baseCode = 'STYLIST-' . strtoupper($user->first_name ?? 'USER') . '-' . strtoupper(Str::random(5));
        }

        return DistributorCode::create([
            'code' => $baseCode,
            'used' => false,
            'expires_at' => now()->addMonths(6),
            'created_by' => $user->id,
        ]);
    }

    /**
     * Get stats for a specific code owned by a user.
     */
    public function getStats(string $code, User $user): array
    {
        $distributorCode = DistributorCode::where('code', $code)
            ->where('created_by', $user->id)
            ->firstOrFail();

        return [
            'code' => $distributorCode->code,
            'used' => (bool) $distributorCode->used,
            'usedBy' => $distributorCode->used_by,
            'expiresAt' => $distributorCode->expires_at?->toISOString(),
            'isExpired' => $distributorCode->expires_at && $distributorCode->expires_at->isPast(),
            'createdAt' => $distributorCode->created_at?->toISOString(),
        ];
    }

    /**
     * Update a distributor code owned by a user.
     */
    public function update(string $code, User $user, array $validated): DistributorCode
    {
        $distributorCode = DistributorCode::where('code', $code)
            ->where('created_by', $user->id)
            ->firstOrFail();

        $distributorCode->update($validated);

        return $distributorCode;
    }
}
