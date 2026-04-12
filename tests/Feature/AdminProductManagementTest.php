<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminProductManagementTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function admin_can_list_products_with_the_same_filters_as_the_storefront(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_ADMIN,
        ]);

        Sanctum::actingAs($admin);

        $matchingBrand = Brand::factory()->create(['name' => 'Fanola']);
        $otherBrand = Brand::factory()->create(['name' => 'Milk Shake']);
        $matchingCategory = Category::factory()->create([
            'name' => 'Masks',
            'sort_priority' => 5,
        ]);
        $otherCategory = Category::factory()->create([
            'name' => 'Sprays',
            'sort_priority' => 10,
        ]);

        Product::factory()->create([
            'name' => 'No Yellow Restoring Mask',
            'brand_id' => $matchingBrand->id,
            'category_id' => $matchingCategory->id,
            'price' => 899,
            'stylist_price' => 799,
            'quantity' => 8,
            'stylist_only' => true,
        ]);

        Product::factory()->create([
            'name' => 'No Yellow Mini Mask',
            'brand_id' => $matchingBrand->id,
            'category_id' => $matchingCategory->id,
            'price' => 499,
            'stylist_price' => 449,
            'quantity' => 8,
            'stylist_only' => true,
        ]);

        Product::factory()->create([
            'name' => 'No Yellow Restoring Spray',
            'brand_id' => $matchingBrand->id,
            'category_id' => $otherCategory->id,
            'price' => 999,
            'stylist_price' => 899,
            'quantity' => 8,
            'stylist_only' => true,
        ]);

        Product::factory()->create([
            'name' => 'No Yellow Restoring Mask',
            'brand_id' => $otherBrand->id,
            'category_id' => $matchingCategory->id,
            'price' => 999,
            'stylist_price' => 899,
            'quantity' => 8,
            'stylist_only' => true,
        ]);

        Product::factory()->create([
            'name' => 'No Yellow Restoring Mask',
            'brand_id' => $matchingBrand->id,
            'category_id' => $matchingCategory->id,
            'price' => 999,
            'stylist_price' => 899,
            'quantity' => 0,
            'stylist_only' => true,
        ]);

        $response = $this->getJson(
            sprintf(
                '/api/v1/admin/products?category_id=%d&brand_id=%d&search=%s&min_price=700&max_price=950&in_stock=1&sort=price_desc&perPage=20',
                $matchingCategory->id,
                $matchingBrand->id,
                urlencode('Restoring')
            )
        );

        $response
            ->assertOk()
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'No Yellow Restoring Mask')
            ->assertJsonPath('data.0.brand.name', 'Fanola')
            ->assertJsonPath('data.0.category.name', 'Masks')
            ->assertJsonPath('data.0.stylistOnly', true)
            ->assertJsonPath('data.0.inStock', true);
    }
}
