<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CouponService;
use App\Support\ApiResponse;
use App\Support\ApiUserResolver;
use Illuminate\Http\Request;

class CouponController extends Controller
{
    public function __construct(
        protected CouponService $couponService
    ) {}

    public function validate(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'subtotal' => ['required', 'numeric', 'min:0'],
        ]);

        $result = $this->couponService->validateCoupon(
            $request->code,
            (float) $request->subtotal,
            // The route is public, so read the signed-in user from the token ourselves.
            $request->bearerToken() ? ApiUserResolver::fromRequest($request) : null
        );

        if (!$result['valid']) {
            return ApiResponse::error(
                $result['error'],
                $result['errorCode'],
                !empty($result['requiresLogin']) ? ['requires_login' => true] : null
            );
        }

        return ApiResponse::ok([
            'valid' => true,
            'coupon' => $result['coupon'],
        ]);
    }
}
