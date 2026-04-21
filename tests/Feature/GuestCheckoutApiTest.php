<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GuestCheckoutApiTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function guest_can_place_an_order_without_authentication(): void
    {
        $brand = Brand::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'price' => 1000,
            'stylist_price' => 900,
            'quantity' => 5,
        ]);

        $response = $this->postJson('/api/v1/checkout', [
            'items' => [
                [
                    'product_id' => $product->id,
                    'qty' => 2,
                ],
            ],
            'shipping_address' => [
                'firstName' => 'Guest',
                'lastName' => 'Customer',
                'email' => 'guest@example.com',
                'phone' => '070123123',
                'address' => 'Street 1',
                'city' => 'Skopje',
                'zip' => '1000',
            ],
            'payment_method' => 'cod',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.userId', null)
            ->assertJsonPath('data.shippingAddress.fullName', 'Guest Customer')
            ->assertJsonPath('data.shippingAddress.phone', '070123123')
            ->assertJsonPath('data.shipping', 150.0)
            ->assertJsonPath('data.total', 2150.0)
            ->assertJsonPath('data.paymentMethod', 'cod');

        $orderId = (int) $response->json('data.id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'user_id' => null,
            'shipping' => 150.00,
            'total' => 2150.00,
            'payment_method' => 'cod',
        ]);

        $this->assertDatabaseHas('order_infos', [
            'order_id' => $orderId,
            'email' => 'guest@example.com',
            'postal_code' => '1000',
        ]);
    }
}
