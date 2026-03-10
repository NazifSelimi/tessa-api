<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_creates_an_order_for_authenticated_user()
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $brand = Brand::factory()->create();
        $category = Category::factory()->create();
        $product1 = Product::factory()->create([
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'price' => 100.00,
            'quantity' => 50,
        ]);
        $product2 = Product::factory()->create([
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'price' => 50.00,
            'quantity' => 30,
        ]);

        $orderData = [
            'items' => [
                ['product_id' => $product1->id, 'qty' => 2],
                ['product_id' => $product2->id, 'qty' => 1],
            ],
            'shipping_address' => [
                'firstName' => 'John',
                'lastName' => 'Doe',
                'email' => 'john@example.com',
                'phone' => '+1234567890',
                'address' => '123 Main St',
                'city' => 'New York',
                'state' => 'NY',
                'zip' => '10001',
            ],
            'payment_method' => 'cod',
            'custom_message' => 'Please deliver after 6pm',
        ];

        $response = $this->withToken($token)->postJson('/api/v1/orders', $orderData);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'data' => [
                'id',
                'userId',
                'items',
                'subtotal',
                'total',
                'status',
                'shippingAddress',
            ]
        ]);

        // Check database
        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'total' => 250.00, // (100 * 2) + (50 * 1)
            'status' => Order::STATUS_PENDING,
        ]);

        // Check order items
        $this->assertDatabaseHas('items', [
            'product_id' => $product1->id,
            'quantity' => 2,
            'price' => 100.00,
        ]);

        // Check stock was decremented
        $this->assertDatabaseHas('products', [
            'id' => $product1->id,
            'quantity' => 48, // 50 - 2
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $product2->id,
            'quantity' => 29, // 30 - 1
        ]);
    }

    /** @test */
    public function it_validates_order_creation_data()
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        // Missing required fields
        $response = $this->withToken($token)->postJson('/api/v1/orders', [
            'items' => [],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items', 'shipping_address']);
    }

    /** @test */
    public function it_rejects_order_with_insufficient_stock()
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $brand = Brand::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'quantity' => 5, // Only 5 in stock
        ]);

        $orderData = [
            'items' => [
                ['product_id' => $product->id, 'qty' => 10], // Requesting 10
            ],
            'shipping_address' => [
                'firstName' => 'John',
                'lastName' => 'Doe',
                'email' => 'john@example.com',
                'phone' => '+1234567890',
                'address' => '123 Main St',
                'city' => 'New York',
                'state' => 'NY',
                'zip' => '10001',
            ],
            'payment_method' => 'cod',
        ];

        $response = $this->withToken($token)->postJson('/api/v1/orders', $orderData);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items']);
    }

    /** @test */
    public function it_lists_user_orders()
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        Order::factory()->count(3)->create(['user_id' => $user->id]);

        $response = $this->withToken($token)->getJson('/api/v1/orders');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'userId', 'items', 'total', 'status']
            ],
            'meta' => ['current_page', 'per_page', 'total', 'last_page']
        ]);
        $this->assertCount(3, $response->json('data'));
    }

    /** @test */
    public function it_shows_single_order_details()
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $order = Order::factory()->create(['user_id' => $user->id]);

        $response = $this->withToken($token)->getJson("/api/v1/orders/{$order->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $order->id);
        $response->assertJsonStructure([
            'success',
            'data' => ['id', 'userId', 'items', 'total', 'status', 'shippingAddress']
        ]);
    }

    /** @test */
    public function it_cancels_pending_order()
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => Order::STATUS_PENDING,
        ]);

        $response = $this->withToken($token)->postJson("/api/v1/orders/{$order->id}/cancel");

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => Order::STATUS_CANCELLED,
        ]);
    }

    /** @test */
    public function it_cannot_cancel_non_pending_order()
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => Order::STATUS_SHIPPED,
        ]);

        $response = $this->withToken($token)->postJson("/api/v1/orders/{$order->id}/cancel");

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['order']);
    }

    /** @test */
    public function it_applies_stylist_pricing_for_stylist_users()
    {
        $user = User::factory()->create([
            'is_stylist' => true,
            'role' => User::ROLE_STYLIST,
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $brand = Brand::factory()->create();
        $category = Category::factory()->create();
        $product = Product::factory()->create([
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'price' => 100.00,
            'stylist_price' => 80.00,
            'quantity' => 50,
        ]);

        $orderData = [
            'items' => [
                ['product_id' => $product->id, 'qty' => 1],
            ],
            'shipping_address' => [
                'firstName' => 'Jane',
                'lastName' => 'Stylist',
                'email' => 'jane@example.com',
                'phone' => '+1234567890',
                'address' => '456 Salon Ave',
                'city' => 'Los Angeles',
                'state' => 'CA',
                'zip' => '90001',
            ],
            'payment_method' => 'online',
        ];

        $response = $this->withToken($token)->postJson('/api/v1/orders', $orderData);

        $response->assertStatus(201);

        // Should use stylist price (80.00) instead of regular price (100.00)
        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'total' => 80.00,
        ]);

        $this->assertDatabaseHas('items', [
            'product_id' => $product->id,
            'price' => 80.00,
        ]);
    }
}
