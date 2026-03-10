<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Actions\CheckoutAction;
use App\Support\ApiResponse;
use App\Http\Requests\Api\V1\StoreOrderRequest;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutAction $checkoutAction
    ) {}

    public function checkout(StoreOrderRequest $request)
    {
        $order = $this->checkoutAction->execute(
            $request->user(),
            $request->validated()
        );

        return ApiResponse::ok(new \App\Http\Resources\OrderResource($order));
    }
}
