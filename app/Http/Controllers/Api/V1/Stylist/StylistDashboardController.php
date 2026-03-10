<?php

namespace App\Http\Controllers\Api\V1\Stylist;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Services\StylistDashboardService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class StylistDashboardController extends Controller
{
    public function __construct(
        protected StylistDashboardService $stylistDashboardService
    ) {}

    /**
     * Get stylist dashboard statistics.
     */
    public function index(Request $request)
    {
        $data = $this->stylistDashboardService->getDashboardData($request->user());

        // Transform recentOrders through resource
        $data['recentOrders'] = OrderResource::collection($data['recentOrders'])->resolve();

        return ApiResponse::ok($data);
    }
}
