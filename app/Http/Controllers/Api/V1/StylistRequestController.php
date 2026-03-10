<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RequestStylistRequest;
use App\Services\StylistRequestService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class StylistRequestController extends Controller
{
    public function __construct(
        protected StylistRequestService $stylistRequestService
    ) {}

    public function store(RequestStylistRequest $request)
    {
        $result = $this->stylistRequestService->submitRequest(
            $request->user(),
            $request->validated()
        );

        if (!$result['created']) {
            return ApiResponse::error($result['error'], $result['code']);
        }

        return ApiResponse::ok(
            $result['data'],
            201,
            [],
            'Stylist request submitted successfully'
        );
    }

    public function status(Request $request)
    {
        $data = $this->stylistRequestService->getRequestStatus($request->user());

        return ApiResponse::ok($data);
    }
}
