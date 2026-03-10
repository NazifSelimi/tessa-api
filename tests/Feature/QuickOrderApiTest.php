<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class QuickOrderApiTest extends TestCase
{
    use DatabaseTransactions;

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

    /** @test */
    public function it_returns_paginated_minimal_product_payload()
    {
        $data = $this->seedProducts();

        // Filter by the known category so existing DB rows don't interfere
        $response = $this->getJson('/api/v1/products/quick-order?category_id=' . $data['category']->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [['id', 'name', 'price', 'stock', 'thumbnail']],
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            ]);

        $this->assertLessThanOrEqual(20, count($response->json('data')));
        $this->assertGreaterThanOrEqual(25, $response->json('meta.total'));

        // Verify minimal payload – no heavy keys present
        $firstItem = $response->json('data.0');
        $this->assertArrayNotHasKey('description', $firstItem);
        $this->assertArrayNotHasKey('brand', $firstItem);
        $this->assertArrayNotHasKey('category', $firstItem);
        $this->assertArrayNotHasKey('sale', $firstItem);
    }

    /** @test */
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

        $response = $this->getJson('/api/v1/products/quick-order?search=' . urlencode($uniqueName));

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($uniqueName, $response->json('data.0.name'));
    }

    /** @test */
    public function it_filters_by_category_id()
    {
        $brand = Brand::factory()->create();
        $cat1  = Category::factory()->create(['name' => 'Shampoo']);
        $cat2  = Category::factory()->create(['name' => 'Conditioner']);

        Product::factory()->count(3)->create(['brand_id' => $brand->id, 'category_id' => $cat1->id]);
        Product::factory()->count(2)->create(['brand_id' => $brand->id, 'category_id' => $cat2->id]);

        $response = $this->getJson('/api/v1/products/quick-order?category_id=' . $cat2->id);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    /** @test */
    public function it_validates_category_id_exists()
    {
        $response = $this->getJson('/api/v1/products/quick-order?category_id=9999');

        $response->assertStatus(422);
    }
}
