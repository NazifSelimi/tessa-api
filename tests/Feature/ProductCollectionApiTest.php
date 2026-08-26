<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ProductCollectionApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_collection_filter_keeps_stylist_only_products_hidden_from_consumers(): void
    {
        $brand = Brand::factory()->create(['name' => 'Fanola']);
        $category = Category::factory()->create(['name' => 'Shampoo']);
        $collection = $this->collection('test-blonde-' . uniqid());
        $consumerProduct = Product::factory()->create([
            'name' => 'No Yellow Shampoo',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'stylist_only' => false,
        ]);
        $stylistProduct = Product::factory()->create([
            'name' => 'No Yellow Technical Shampoo',
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'stylist_only' => true,
        ]);
        $collection->products()->attach([$consumerProduct->id, $stylistProduct->id], [
            'mapping_status' => 'confirmed',
            'source' => 'test',
        ]);

        $response = $this->getJson('/api/v1/products?collection=' . $collection->slug);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', (string) $consumerProduct->id);
        $response->assertJsonPath('data.0.collections.0.slug', $collection->slug);
    }

    public function test_stylists_can_view_stylist_only_collection_results(): void
    {
        $brand = Brand::factory()->create();
        $category = Category::factory()->create(['name' => 'Hair Color']);
        $collection = $this->collection('test-colour-' . uniqid());
        $product = Product::factory()->create([
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'stylist_only' => true,
        ]);
        $collection->products()->attach($product->id, [
            'mapping_status' => 'confirmed',
            'source' => 'test',
        ]);
        $stylist = User::factory()->create(['role' => User::ROLE_STYLIST]);

        $response = $this->actingAs($stylist, 'sanctum')
            ->getJson('/api/v1/products?collection=' . $collection->slug);

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', (string) $product->id);
    }

    public function test_collection_directory_reports_only_consumer_visible_product_counts(): void
    {
        $brand = Brand::factory()->create();
        $category = Category::factory()->create();
        $collection = $this->collection('test-repair-' . uniqid());
        $visible = Product::factory()->create(['brand_id' => $brand->id, 'category_id' => $category->id]);
        $hidden = Product::factory()->create([
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'stylist_only' => true,
        ]);
        $collection->products()->attach([$visible->id, $hidden->id], [
            'mapping_status' => 'confirmed',
            'source' => 'test',
        ]);

        $response = $this->getJson('/api/v1/product-collections');
        $directoryItem = collect($response->json('data'))->firstWhere('slug', $collection->slug);

        $response->assertOk();
        $this->assertSame(1, $directoryItem['productCount']);
    }

    private function collection(string $slug): ProductCollection
    {
        return ProductCollection::create([
            'slug' => $slug,
            'name' => 'Test collection',
            'title' => 'Test collection',
            'sort_priority' => 999,
            'is_active' => true,
            'default_routine_roles' => ['cleanse'],
            'supported_category_names' => ['Shampoo'],
        ]);
    }
}
