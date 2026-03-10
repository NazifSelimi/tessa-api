<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'total' => fake()->randomFloat(2, 50, 1000),
            'discount' => 0,
            'status' => Order::STATUS_PENDING,
            'message' => fake()->optional()->sentence(),
        ];
    }
}
