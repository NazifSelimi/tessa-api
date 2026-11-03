<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\User;
use App\Services\ReferralService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminReferralController extends Controller
{
    public function __construct(
        private readonly ReferralService $referrals
    ) {}

    public function index()
    {
        return ApiResponse::ok([
            'settings' => $this->referrals->settings(),
            'codes' => $this->referrals->adminList(),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'discountPercentage' => ['sometimes', 'numeric', 'min:0', 'max:50'],
            'commissionPercentage' => ['sometimes', 'numeric', 'min:0', 'max:30'],
            'firstOrderOnly' => ['sometimes', 'boolean'],
            'maxCommission' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'applyToExisting' => ['sometimes', 'boolean'],
        ]);

        return ApiResponse::ok($this->referrals->updateSettings(
            $validated,
            (bool) ($validated['applyToExisting'] ?? false)
        ));
    }

    /** Create a stylist's code ahead of time, e.g. to print their salon card. */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'stylistId' => ['required', 'integer', Rule::exists('users', 'id')->where('role', User::ROLE_STYLIST)],
        ]);

        $coupon = $this->referrals->codeFor(User::findOrFail($validated['stylistId']));

        return ApiResponse::ok($this->referrals->codeData($coupon), 201);
    }

    public function update(Request $request, Coupon $coupon)
    {
        abort_unless($coupon->isReferral(), 404);

        $validated = $request->validate([
            'code' => ['sometimes', 'string', 'max:20'],
            'discountPercentage' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'commissionPercentage' => ['sometimes', 'numeric', 'min:0', 'max:30'],
            'firstOrderOnly' => ['sometimes', 'boolean'],
            'isActive' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('code', $validated)) {
            $coupon = $this->referrals->renameCode($coupon->stylist, $validated['code']);
        }

        $coupon->update(array_filter([
            'value' => $validated['discountPercentage'] ?? null,
            'commission_percentage' => $validated['commissionPercentage'] ?? null,
            'first_order_only' => $validated['firstOrderOnly'] ?? null,
            'is_active' => $validated['isActive'] ?? null,
        ], fn ($value) => $value !== null));

        return ApiResponse::ok($this->referrals->codeData($coupon->fresh()));
    }

    /** Pay out or correct a user's store credit (negative to take credit away). */
    public function adjustCredit(Request $request, User $user)
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'not_in:0', 'between:-1000000,1000000'],
            'note' => ['required', 'string', 'max:255'],
        ]);

        $row = $this->referrals->adjust($user, (float) $validated['amount'], $validated['note'], $request->user());

        return ApiResponse::ok([
            'transaction' => $this->referrals->transactionData($row),
            'balance' => $this->referrals->balance($user),
        ], 201);
    }
}
