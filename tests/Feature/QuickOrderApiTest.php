<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class QuickOrderApiTest extends TestCase
{
    use DatabaseTransactions;

    /** Quick order is a salon tool: only stylists and admins may use it. */
    private function asStylist(): static
    {
        return $this->withToken(User::factory()->create(['role' => User::ROLE_STYLIST])->createToken('t')->plainTextToken);
    }

    private function seedProducts(): array
    {
        $brand    = Brand::factory()->create(['name' => 'TestBrand']);
        $category = Category::factory()->create(['name' => 'Shampoo']);

        $products = Product::factory()->count(25)->create([
            'brand_id'    => $brand->id,
            'category_id' => $category->id,
        ]);

        return compact('brand', 'category', 'products');
    }

    #[Test]
    public function it_returns_paginated_minimal_product_payload()
    {
        $data = $this->seedProducts();

        // Filter by the known category so existing DB rows don't interfere
        $response = $this->asStylist()->getJson('/api/v1/products/quick-order?category_id=' . $data['category']->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [['id', 'name', 'price', 'stylistPrice', 'thumbnail']],
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            ]);

        $this->assertLessThanOrEqual(20, count($response->json('data')));
        $this->assertGreaterThanOrEqual(25, $response->json('meta.total'));

        // Verify minimal payload – no heavy keys present
        $firstItem = $response->json('data.0');
        $this->assertArrayNotHasKey('description', $firstItem);
        $this->assertArrayNotHasKey('brand', $firstItem);
        $this->assertArrayNotHasKey('category', $firstItem);
    }

    #[Test]
    public function it_filters_by_search()
    {
        $brand    = Brand::factory()->create();
        $category = Category::factory()->create();

        $uniqueName = 'ZZZUniqueQuickOrderTestProduct_' . uniqid();
        Product::factory()->create([
            'name' => $uniqueName, 'brand_id' => $brand->id, 'category_id' => $category->id,
        ]);
        Product::factory()->create([
            'name' => 'Straight Serum', 'brand_id' => $brand->id, 'category_id' => $category->id,
        ]);

        $response = $this->asStylist()->getJson('/api/v1/products/quick-order?search=' . urlencode($uniqueName));

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($uniqueName, $response->json('data.0.name'));
    }

    #[Test]
    public function it_filters_by_category_id()
    {
        $brand = Brand::factory()->create();
        $cat1  = Category::factory()->create(['name' => 'Shampoo']);
        $cat2  = Category::factory()->create(['name' => 'Conditioner']);

        Product::factory()->count(3)->create(['brand_id' => $brand->id, 'category_id' => $cat1->id]);
        Product::factory()->count(2)->create(['brand_id' => $brand->id, 'category_id' => $cat2->id]);

        $response = $this->asStylist()->getJson('/api/v1/products/quick-order?category_id=' . $cat2->id);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    #[Test]
    public function it_filters_quick_restock_to_color_categories()
    {
        $brand = Brand::factory()->create();
        $colorCategory = Category::factory()->create(['name' => 'Hair Color']);
        $otherCategory = Category::factory()->create(['name' => 'Shampoo']);

        Product::factory()->create(['name' => 'Color shade', 'brand_id' => $brand->id, 'category_id' => $colorCategory->id]);
        Product::factory()->create(['name' => 'Daily shampoo', 'brand_id' => $brand->id, 'category_id' => $otherCategory->id]);

        $response = $this->asStylist()->getJson('/api/v1/products/quick-order?color_restock=1');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Color shade');
    }

    #[Test]
    public function it_validates_category_id_exists()
    {
        $response = $this->asStylist()->getJson('/api/v1/products/quick-order?category_id=9999');

        $response->assertStatus(422);
    }

    #[Test]
    public function customers_cannot_use_quick_order()
    {
        $this->getJson('/api/v1/products/quick-order')->assertStatus(401);
        $customer = User::factory()->create(['role' => User::ROLE_USER]);
        $this->withToken($customer->createToken('t')->plainTextToken)
            ->getJson('/api/v1/products/quick-order')->assertStatus(403);
    }
}
