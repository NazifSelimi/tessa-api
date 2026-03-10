<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DistributorCode>
 */
class DistributorCodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stylist_id' => \App\Models\User::factory()->create(['role' => 'stylist'])->id,
            'code' => 'STYLIST-' . strtoupper(fake()->firstName()) . '-' . now()->year,
            'discount_percentage' => fake()->numberBetween(5, 20),
            'usage_count' => fake()->numberBetween(0, 100),
            'total_revenue' => fake()->randomFloat(2, 0, 10000),
            'is_active' => fake()->boolean(90),
        ];
    }
}
