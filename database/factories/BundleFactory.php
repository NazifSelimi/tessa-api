<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class BundleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true) . ' Bundle',
            'description' => fake()->sentence(),
            'is_dynamic' => false,
            'discount_percentage' => fake()->randomElement([null, 5.00, 10.00, 15.00]),
        ];
    }
}
