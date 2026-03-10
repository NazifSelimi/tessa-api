<?php

namespace App\Actions;

use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;

class CheckoutAction
{
	public function __construct(
		private readonly OrderService $orderService
	) {}

	public function execute(User $user, array $payload): Order
	{
		return $this->orderService->createOrder($user, $payload);
	}
}
