<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'brand_id' => Brand::factory(),
            'category_id' => Category::factory(),
            'price' => fake()->randomFloat(2, 10, 1000),
            'stylist_price' => fake()->randomFloat(2, 8, 800),
            'quantity' => fake()->numberBetween(0, 100),
        ];
    }

    /**
     * A finished product has a photo; without one it stays in admin review.
     */
    public function configure(): static
    {
        return $this->afterCreating(fn (Product $product) => $product->images()->create([
            'name' => 'test/' . fake()->uuid() . '.webp',
            'variant' => 'legacy',
        ]));
    }

    public function withoutPhoto(): static
    {
        return $this->afterCreating(fn (Product $product) => $product->images()->delete());
    }
}
