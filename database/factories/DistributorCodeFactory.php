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
            'code' => 'STYLIST-' . strtoupper(fake()->firstName()) . '-' . strtoupper(\Illuminate\Support\Str::random(5)),
            'used' => false,
            'expires_at' => now()->addMonths(6),
            'created_by' => \App\Models\User::factory()->create(['role' => \App\Models\User::ROLE_STYLIST, 'is_stylist' => true])->id,
        ];
    }
}
