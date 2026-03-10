<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CouponService;
use App\Support\ApiResponse;
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
            $request->user()
        );

        if (!$result['valid']) {
            return ApiResponse::error($result['error'], $result['errorCode']);
        }

        return ApiResponse::ok([
            'valid' => true,
            'coupon' => $result['coupon'],
        ]);
    }
}
