<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Actions\CheckoutAction;
use App\Http\Resources\OrderResource;
use App\Support\ApiResponse;
use App\Http\Requests\Api\V1\StoreOrderRequest;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutAction $checkoutAction
    ) {}

    public function checkout(StoreOrderRequest $request)
    {
        // This route is public so guests can check out, which means the default
        // (session/web) guard never resolves the bearer token. Resolve the user
        // explicitly through the sanctum guard so authenticated customers —
        // crucially stylists — are recognised and get their correct pricing
        // instead of being billed as guests at retail prices.
        $user = $request->user('sanctum') ?? $request->user();

        $order = $this->checkoutAction->execute(
            $user,
            $request->validated()
        );

        return ApiResponse::ok(new OrderResource($order), 201);
    }
}
