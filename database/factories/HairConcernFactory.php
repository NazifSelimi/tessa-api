<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class HairConcernFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement([
                'Dryness', 'Frizz', 'Breakage', 'Thinning',
                'Oiliness', 'Dandruff', 'Color Protection',
            ]),
            'description' => fake()->sentence(),
        ];
    }
}
