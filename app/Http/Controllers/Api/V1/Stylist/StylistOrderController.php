<?php

namespace App\Http\Controllers\Api\V1\Stylist;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Services\StylistOrderService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class StylistOrderController extends Controller
{
    public function __construct(
        protected StylistOrderService $stylistOrderService
    ) {}

    /**
     * Get the stylist's own orders.
     */
    public function index(Request $request)
    {
        $perPage = $request->per_page ?? 20;
        $orders = $this->stylistOrderService->listByUser($request->user(), $perPage);

        return ApiResponse::ok(
            OrderResource::collection($orders)->resolve(),
            200,
            [
                'current_page' => $orders->currentPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
                'last_page' => $orders->lastPage(),
            ]
        );
    }
}
