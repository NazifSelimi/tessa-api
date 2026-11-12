<?php

namespace App\Http\Controllers\Api\V1\Stylist;

use App\Http\Controllers\Controller;
use App\Services\ReferralService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/**
 * The stylist's own referral code, what it has earned and their store credit.
 */
class StylistReferralController extends Controller
{
    public function __construct(
        private readonly ReferralService $referrals
    ) {}

    public function show(Request $request)
    {
        // Admins pass the stylist route group, but only stylists have a referral code.
        abort_unless($request->user()->isStylist(), 403);

        return ApiResponse::ok($this->referrals->summary($request->user()));
    }

    public function update(Request $request)
    {
        abort_unless($request->user()->isStylist(), 403);

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:20'],
        ]);

        $this->referrals->renameCode($request->user(), $validated['code']);

        return ApiResponse::ok($this->referrals->summary($request->user()));
    }
}
