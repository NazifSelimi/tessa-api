<?php

namespace App\Http\Controllers\Api\V1\Stylist;

use App\Http\Controllers\Controller;
use App\Http\Resources\DistributorCodeResource;
use App\Services\StylistCodeService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class StylistCodeController extends Controller
{
    public function __construct(
        protected StylistCodeService $stylistCodeService
    ) {}

    /**
     * Get stylist's invitation codes.
     */
    public function index(Request $request)
    {
        $codes = $this->stylistCodeService->listByUser($request->user());

        return ApiResponse::ok(DistributorCodeResource::collection($codes));
    }

    /**
     * Generate a new invitation code.
     */
    public function generate(Request $request)
    {
        $distributorCode = $this->stylistCodeService->generate($request->user());

        return ApiResponse::ok(
            new DistributorCodeResource($distributorCode),
            201,
            [],
            'Invitation code generated successfully'
        );
    }

    /**
     * Get code details.
     */
    public function stats(Request $request, $code)
    {
        $data = $this->stylistCodeService->getStats($code, $request->user());

        return ApiResponse::ok($data);
    }

    /**
     * Update code (currently: no toggleable fields in DB, but can be extended).
     */
    public function update(Request $request, $code)
    {
        $validated = $request->validate([
            'expires_at' => ['sometimes', 'date', 'after:now'],
        ]);

        $distributorCode = $this->stylistCodeService->update($code, $request->user(), $validated);

        return ApiResponse::ok(
            new DistributorCodeResource($distributorCode),
            200,
            [],
            'Code updated successfully'
        );
    }
}
