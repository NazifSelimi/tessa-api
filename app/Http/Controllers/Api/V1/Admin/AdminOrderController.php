<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Services\AdminOrderService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminOrderController extends Controller
{
    public function __construct(
        protected AdminOrderService $adminOrderService
    ) {}

    public function index(Request $request)
    {
        $filters = $request->only(['status', 'date_from', 'date_to', 'search']);
        $perPage = $request->per_page ?? 20;

        $orders = $this->adminOrderService->listFiltered($filters, $perPage);

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

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => ['required', 'in:pending,confirmed,shipped,cancelled'],
        ]);

        $order = $this->adminOrderService->updateStatus($id, $request->status);

        return ApiResponse::ok(
            new OrderResource($order),
            200,
            [],
            'Order status updated successfully'
        );
    }
}
