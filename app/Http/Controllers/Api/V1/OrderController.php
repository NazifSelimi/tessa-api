<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\OrderService;
use App\Support\ApiResponse;
use App\Http\Resources\OrderResource;
use App\Http\Requests\Api\V1\StoreOrderRequest;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orderService
    ) {}

    public function index(Request $request)
    {
        $orders = $this->orderService->userOrders($request->user());

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

    public function show($id, Request $request)
    {
        $order = $request->user()
            ->orders()
            ->with(['items.product', 'coupon', 'user'])
            ->findOrFail($id);

        return ApiResponse::ok(
            new OrderResource($order)
        );
    }

    public function store(StoreOrderRequest $request)
    {
        $order = $this->orderService->createOrder(
            $request->user(),
            $request->validated()
        );

        return ApiResponse::ok(
            new OrderResource($order),
            201
        );
    }

    public function cancel($id, Request $request)
    {
        $order = $request->user()
            ->orders()
            ->findOrFail($id);

        $order = $this->orderService->cancelOrder($order);

        return ApiResponse::ok(
            new OrderResource($order)
        );
    }
}
