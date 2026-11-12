<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Actions\CheckoutAction;
use App\Http\Resources\OrderResource;
use App\Support\ApiResponse;
use App\Http\Requests\Api\V1\StoreOrderRequest;
use App\Support\ApiUserResolver;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutAction $checkoutAction
    ) {}

    public function checkout(StoreOrderRequest $request)
    {
        $user = $request->bearerToken() ? ApiUserResolver::fromRequest($request) : $request->user();
        if ($request->bearerToken() && !$user) {
            return ApiResponse::error(__('Your session has expired. Please sign in again.'), 401);
        }
        $order = $this->checkoutAction->execute(
            $user,
            $request->validated()
        );

        return ApiResponse::ok(new OrderResource($order), 201);
    }
}
