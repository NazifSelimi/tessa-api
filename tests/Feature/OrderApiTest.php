<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
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
            'total' => 400.00, // (100 * 2) + (50 * 1) + 150 shipping under 3000 MKD
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
    public function it_shows_single_order_details()
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $order = Order::factory()->create(['user_id' => $user->id]);

        $response = $this->withToken($token)->getJson("/api/v1/orders/{$order->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', (string) $order->id);
        $response->assertJsonStructure([
            'success',
            'data' => ['id', 'userId', 'items', 'total', 'status', 'shippingAddress']
        ]);
    }

    #[Test]
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

    #[Test]
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

    #[Test]
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

        // Should use stylist price (80.00) instead of regular price (100.00), plus 150 shipping
        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'total' => 230.00,
        ]);

        $this->assertDatabaseHas('items', [
            'product_id' => $product->id,
            'price' => 80.00,
        ]);
    }

    #[Test]
    public function admin_cancel_returns_stock_and_undo_takes_it_again()
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $buyer = User::factory()->create(['role' => User::ROLE_USER]);
        $product = Product::factory()->create(['price' => 100, 'quantity' => 50]);
        $buyerToken = $buyer->createToken('t')->plainTextToken;

        $orderId = $this->withToken($buyerToken)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'qty' => 3]],
            'shipping_address' => [
                'firstName' => 'Ana', 'lastName' => 'Test', 'email' => 'ana@example.com', 'phone' => '070111222',
                'address' => '1 Street', 'city' => 'Skopje', 'state' => 'MK', 'zip' => '1000',
            ],
            'payment_method' => 'online',
        ])->assertStatus(201)->json('data.id');
        $this->assertSame(47, $product->fresh()->quantity);

        $this->app['auth']->forgetGuards();
        $adminToken = $admin->createToken('t')->plainTextToken;
        $this->withToken($adminToken)->putJson("/api/v1/admin/orders/{$orderId}/status", ['status' => 'cancelled'])->assertOk();
        $this->assertSame(50, $product->fresh()->quantity);

        // Cancelling twice does not add the stock twice.
        $this->withToken($adminToken)->putJson("/api/v1/admin/orders/{$orderId}/status", ['status' => 'cancelled'])->assertOk();
        $this->assertSame(50, $product->fresh()->quantity);

        $this->withToken($adminToken)->putJson("/api/v1/admin/orders/{$orderId}/status", ['status' => 'confirmed'])->assertOk();
        $this->assertSame(47, $product->fresh()->quantity);
    }

    private function orderFor(Product $product, int $qty): array
    {
        return [
            'items' => [['product_id' => $product->id, 'qty' => $qty]],
            'shipping_address' => [
                'firstName' => 'Ana', 'lastName' => 'Test', 'email' => 'ana@example.com', 'phone' => '070111222',
                'address' => '1 Street', 'city' => 'Skopje', 'state' => 'MK', 'zip' => '1000',
            ],
            'payment_method' => 'cod',
        ];
    }

    /** #20: "Only X left" never shows more than 50 to customers or salons; admins see the real number. */
    #[Test]
    public function the_stock_message_shows_at_most_50_to_non_admins()
    {
        $product = Product::factory()->create(['price' => 100, 'quantity' => 120, 'name' => 'Cap test mask']);

        foreach ([User::ROLE_USER => 50, User::ROLE_STYLIST => 50, User::ROLE_ADMIN => 120] as $role => $shown) {
            $this->app['auth']->forgetGuards();
            $token = User::factory()->create(['role' => $role])->createToken('t')->plainTextToken;
            $this->withToken($token)->postJson('/api/v1/orders', $this->orderFor($product, 200))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['items' => "Only {$shown} left of Cap test mask."]);
        }
        $this->assertSame(120, $product->fresh()->quantity);
    }

    /** #26: one account can place at most 10 orders a minute. */
    #[Test]
    public function placing_orders_is_limited_to_10_a_minute()
    {
        $token = User::factory()->create()->createToken('t')->plainTextToken;

        // Requests count even when they are refused, so empty ones are enough.
        for ($i = 0; $i < 10; $i++) {
            $this->withToken($token)->postJson('/api/v1/orders', [])->assertStatus(422);
        }
        $this->withToken($token)->postJson('/api/v1/orders', [])->assertStatus(429);
    }

    /** #13 (owner rule Q5): an order to another name and address leaves the profile as it was. */
    #[Test]
    public function an_order_never_changes_the_customer_profile()
    {
        $user = User::factory()->create([
            'first_name' => 'Ana', 'last_name' => 'Own', 'phone' => '070000001', 'address' => 'Home 1', 'city' => 'Skopje', 'postcode' => '1000',
        ]);
        $product = Product::factory()->create(['price' => 100, 'quantity' => 10]);
        $order = $this->orderFor($product, 1);
        $order['shipping_address'] = [
            'firstName' => 'Gift', 'lastName' => 'Friend', 'email' => 'friend@example.com', 'phone' => '070999999',
            'address' => 'Friend street 9', 'city' => 'Tetovo', 'state' => 'MK', 'zip' => '1200',
        ];

        $id = $this->withToken($user->createToken('t')->plainTextToken)->postJson('/api/v1/orders', $order)
            ->assertStatus(201)->json('data.id');

        $fresh = $user->fresh();
        $this->assertSame(['Ana', 'Own', '070000001', 'Home 1', 'Skopje', '1000'],
            [$fresh->first_name, $fresh->last_name, $fresh->phone, $fresh->address, $fresh->city, (string) $fresh->postcode]);
        // The delivery details are on the order.
        $this->assertDatabaseHas('order_infos', ['order_id' => $id, 'first_name' => 'Gift', 'city' => 'Tetovo']);
    }

}
