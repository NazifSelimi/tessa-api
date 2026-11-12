<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\HairConcern;
use App\Models\HairType;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Salon items imported with a stylist price but no retail price must never be
 * shown to customers or be orderable for free.
 */
class UnpricedProductTest extends TestCase
{
    use DatabaseTransactions;

    private function product(array $attributes): Product
    {
        return Product::factory()->create($attributes + [
            'brand_id' => Brand::factory()->create()->id,
            'category_id' => Category::factory()->create()->id,
            'stylist_only' => false,
            'quantity' => 10,
        ]);
    }

    private function checkout(Product $product, ?string $token = null)
    {
        $request = $token ? $this->withToken($token) : $this;

        return $request->postJson('/api/v1/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'shipping_address' => [
                'firstName' => 'Test',
                'lastName' => 'Customer',
                'email' => 'unpriced@example.com',
                'phone' => '070123123',
                'address' => 'Street 1',
                'city' => 'Skopje',
                'zip' => '1000',
            ],
            'payment_method' => 'cod',
        ]);
    }

    #[Test]
    public function guest_cannot_order_a_product_without_a_retail_price(): void
    {
        $product = $this->product(['price' => 0, 'stylist_price' => 690]);

        $this->checkout($product)->assertStatus(422)->assertJsonValidationErrors('items');

        $this->assertSame(10, $product->fresh()->quantity);
    }

    #[Test]
    public function stylist_cannot_order_a_product_without_a_stylist_price(): void
    {
        $product = $this->product(['price' => 500, 'stylist_price' => 0]);
        $stylist = User::factory()->create(['role' => User::ROLE_STYLIST]);

        $this->checkout($product, $stylist->createToken('test')->plainTextToken)->assertStatus(422);
    }

    #[Test]
    public function stylist_can_still_order_a_salon_item_at_the_stylist_price(): void
    {
        $product = $this->product(['price' => 0, 'stylist_price' => 690]);
        $stylist = User::factory()->create(['role' => User::ROLE_STYLIST]);

        $response = $this->checkout($product, $stylist->createToken('test')->plainTextToken);

        $response->assertCreated();
        $this->assertSame(690.0, (float) $response->json('data.subtotal'));
    }

    #[Test]
    public function customers_do_not_see_unpriced_products(): void
    {
        $category = Category::factory()->create();
        $unpriced = $this->product(['price' => 0, 'stylist_price' => 690, 'category_id' => $category->id]);
        $priced = $this->product(['price' => 500, 'category_id' => $category->id]);

        $ids = collect($this->getJson("/api/v1/products?category_id={$category->id}")->assertOk()->json('data'))->pluck('id');

        $this->assertContains((string) $priced->id, $ids);
        $this->assertNotContains((string) $unpriced->id, $ids);
        $this->getJson("/api/v1/products/{$unpriced->id}")->assertNotFound();
    }

    #[Test]
    public function stylists_see_salon_items_that_have_a_stylist_price(): void
    {
        $category = Category::factory()->create();
        $unpriced = $this->product(['price' => 0, 'stylist_price' => 690, 'category_id' => $category->id]);
        $token = User::factory()->create(['role' => User::ROLE_STYLIST])->createToken('test')->plainTextToken;

        $ids = collect($this->withToken($token)->getJson("/api/v1/products?category_id={$category->id}")->assertOk()->json('data'))->pluck('id');

        $this->assertContains((string) $unpriced->id, $ids);
    }

    #[Test]
    public function quiz_does_not_recommend_unpriced_products(): void
    {
        $hairType = HairType::factory()->create();
        $concern = HairConcern::factory()->create();
        $unpriced = $this->product(['price' => 0, 'stylist_price' => 690]);
        $priced = $this->product(['price' => 500]);
        $unpriced->hairTypes()->attach($hairType);
        $priced->hairTypes()->attach($hairType);

        $ids = collect($this->postJson('/api/v1/recommendations', [
            'hair_type_id' => $hairType->id,
            'concerns' => [$concern->id],
        ])->assertOk()->json('data.products'))->pluck('id');

        $this->assertContains((string) $priced->id, $ids);
        $this->assertNotContains((string) $unpriced->id, $ids);
    }
}
