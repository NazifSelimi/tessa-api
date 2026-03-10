<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CouponResource;
use App\Services\CouponService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminCouponController extends Controller
{
    public function __construct(
        protected CouponService $couponService
    ) {}

    /**
     * List all coupons with pagination and filters.
     */
    public function index(Request $request)
    {
        $filters = $request->only(['status', 'search']);
        $perPage = $request->per_page ?? 20;

        $coupons = $this->couponService->listFiltered($filters, $perPage);

        return ApiResponse::ok(
            CouponResource::collection($coupons)->resolve(),
            200,
            [
                'current_page' => $coupons->currentPage(),
                'per_page' => $coupons->perPage(),
                'total' => $coupons->total(),
                'last_page' => $coupons->lastPage(),
            ]
        );
    }

    /**
     * Get single coupon details.
     */
    public function show($id)
    {
        $coupon = $this->couponService->find($id);

        return ApiResponse::ok(new CouponResource($coupon));
    }

    /**
     * Create new coupon.
     */
    public function store(Request $request)
    {
        $data = $this->couponService->normalizeFields($request->all());

        $validated = validator($data, [
            'code' => ['required', 'string', 'max:50', 'unique:coupons,code', 'regex:/^[A-Z0-9-]+$/'],
            'type' => ['required', Rule::in(['percentage', 'fixed'])],
            'value' => ['required', 'numeric', 'gt:0'],
            'quantity' => ['required', 'integer', 'min:0'],
            'expiration_date' => ['required', 'date', 'after:now'],
        ])->validate();

        $coupon = $this->couponService->create($validated);

        return ApiResponse::ok(
            new CouponResource($coupon),
            201,
            [],
            'Coupon created successfully'
        );
    }

    /**
     * Update existing coupon.
     */
    public function update(Request $request, $id)
    {
        $data = $this->couponService->normalizeFields($request->all());

        $validated = validator($data, [
            'code' => ['sometimes', 'string', 'max:50', 'regex:/^[A-Z0-9-]+$/', Rule::unique('coupons')->ignore($id)],
            'type' => ['sometimes', Rule::in(['percentage', 'fixed'])],
            'value' => ['sometimes', 'numeric', 'gt:0'],
            'quantity' => ['sometimes', 'integer', 'min:0'],
            'expiration_date' => ['sometimes', 'date'],
        ])->validate();

        $coupon = $this->couponService->update($id, $validated);

        return ApiResponse::ok(
            new CouponResource($coupon),
            200,
            [],
            'Coupon updated successfully'
        );
    }

    /**
     * Delete coupon.
     */
    public function destroy($id)
    {
        $result = $this->couponService->delete($id);

        if (!$result['deleted']) {
            return ApiResponse::error($result['error'], 400);
        }

        return ApiResponse::ok(null, 200, [], 'Coupon deleted successfully');
    }
}
