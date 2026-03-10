<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\Brand;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_lists_products_with_pagination()
    {
        $brand = Brand::factory()->create(['name' => 'TestBrand']);
        $category = Category::factory()->create(['name' => 'Electronics']);
        
        Product::factory()->count(25)->create([
            'brand_id' => $brand->id,
            'category_id' => $category->id,
        ]);

        $response = $this->getJson('/api/v1/products?page=1&perPage=20');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'name', 'price', 'stylistPrice', 'brand', 'category', 'inStock']
            ],
            'meta' => ['current_page', 'per_page', 'total', 'last_page']
        ]);
        $response->assertJsonPath('meta.per_page', 20);
        $response->assertJsonPath('meta.total', 25);
        $this->assertCount(20, $response->json('data'));
    }

    /** @test */
    public function it_filters_products_by_category()
    {
        $category1 = Category::factory()->create();
        $category2 = Category::factory()->create();
        $brand = Brand::factory()->create();

        Product::factory()->count(5)->create([
            'category_id' => $category1->id,
            'brand_id' => $brand->id,
        ]);
        Product::factory()->count(3)->create([
            'category_id' => $category2->id,
            'brand_id' => $brand->id,
        ]);

        $response = $this->getJson("/api/v1/products?category_id={$category1->id}");

        $response->assertStatus(200);
        $this->assertCount(5, $response->json('data'));
    }

    /** @test */
    public function it_searches_products_by_name()
    {
        $brand = Brand::factory()->create();
        $category = Category::factory()->create();
        
        Product::factory()->create([
            'name' => 'Premium Laptop',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
        ]);
        Product::factory()->create([
            'name' => 'Gaming Mouse',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
        ]);

        $response = $this->getJson('/api/v1/products/search?q=laptop');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.name', 'Premium Laptop');
        $this->assertCount(1, $response->json('data'));
    }

    /** @test */
    public function it_returns_single_product_details()
    {
        $brand = Brand::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'brand_id' => $brand->id,
            'category_id' => $category->id,
        ]);

        $response = $this->getJson("/api/v1/products/{$product->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $product->id);
        $response->assertJsonPath('data.name', $product->name);
        $response->assertJsonStructure([
            'success',
            'data' => ['id', 'name', 'price', 'stylistPrice', 'brand', 'category', 'quantity', 'inStock']
        ]);
    }

    /** @test */
    public function it_returns_featured_products()
    {
        $brand = Brand::factory()->create();
        $category = Category::factory()->create();
        Product::factory()->count(10)->create([
            'brand_id' => $brand->id,
            'category_id' => $category->id,
        ]);

        $response = $this->getJson('/api/v1/products/featured?limit=8');

        $response->assertStatus(200);
        $this->assertCount(8, $response->json('data'));
    }
}
