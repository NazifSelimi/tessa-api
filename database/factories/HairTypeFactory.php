<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class HairTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement([
                'Straight', 'Wavy', 'Curly', 'Coily',
                'Fine', 'Thick', 'Normal',
            ]),
            'description' => fake()->sentence(),
        ];
    }
}
