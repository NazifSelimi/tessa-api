<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Coupon>
 */
class CouponFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $type = fake()->randomElement(['percentage', 'fixed']);
        
        return [
            'code' => strtoupper(fake()->unique()->lexify('????-####')),
            'type' => $type,
            'value' => $type === 'percentage' ? fake()->numberBetween(5, 50) : fake()->numberBetween(5, 100),
            'min_purchase' => fake()->optional(0.7)->randomFloat(2, 20, 200),
            'max_discount' => $type === 'percentage' ? fake()->optional(0.5)->randomFloat(2, 50, 500) : null,
            'usage_limit' => fake()->optional(0.8)->numberBetween(10, 1000),
            'used_count' => 0,
            'start_date' => now(),
            'end_date' => now()->addDays(fake()->numberBetween(30, 90)),
            'is_active' => fake()->boolean(80),
            'description' => fake()->optional(0.8)->sentence(),
        ];
    }
}
