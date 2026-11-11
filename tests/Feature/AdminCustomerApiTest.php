<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderInfo;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Admin Customers page API. Runs on the staging test copy (needs a database). */
class AdminCustomerApiTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function asAdmin()
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->admin->createToken('t')->plainTextToken);
    }

    private function guestOrder(string $email, float $total, int $status = Order::STATUS_PAID): Order
    {
        $order = Order::factory()->create(['user_id' => null, 'total' => $total, 'status' => $status]);
        OrderInfo::create(['order_id' => $order->id, 'first_name' => 'Гост', 'last_name' => 'Купувач', 'email' => $email, 'phone' => '070 999 111', 'city' => 'Скопје']);

        return $order;
    }

    #[Test]
    public function only_admins_see_customers()
    {
        $customer = User::factory()->create(['role' => User::ROLE_USER]);
        $this->withToken($customer->createToken('t')->plainTextToken)->getJson('/api/v1/admin/customers')->assertForbidden();
    }

    #[Test]
    public function guest_buyers_are_grouped_and_cancelled_or_trashed_orders_do_not_count()
    {
        $email = 'crm-test-' . uniqid() . '@example.com';
        $this->guestOrder($email, 1000);
        $this->guestOrder(strtoupper($email), 500);
        $this->guestOrder($email, 9000, Order::STATUS_CANCELLED);
        $this->guestOrder($email, 7000)->delete();

        $row = collect($this->asAdmin()->getJson('/api/v1/admin/customers?per_page=200&q=' . urlencode($email))->assertOk()->json('data'))
            ->firstWhere('key', 'email-' . $email);

        $this->assertNotNull($row);
        $this->assertSame('guest', $row['type']);
        $this->assertSame(2, $row['ordersCount']);
        $this->assertEquals(1500, $row['totalSpent']);
    }

    #[Test]
    public function notes_and_tags_are_saved_and_the_profile_lists_orders()
    {
        $user = User::factory()->create(['role' => User::ROLE_STYLIST]);
        Order::factory()->create(['user_id' => $user->id, 'total' => 2000, 'status' => Order::STATUS_SHIPPED]);

        $this->asAdmin()->putJson("/api/v1/admin/customers/user-{$user->id}", ['notes' => 'Salon in Tetovo', 'tags' => ['VIP', ' vip ', 'Salon']])
            ->assertOk()->assertJsonPath('data.tags', ['VIP', 'Salon']);

        $this->asAdmin()->getJson("/api/v1/admin/customers/user-{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.type', 'business')
            ->assertJsonPath('data.notes', 'Salon in Tetovo')
            ->assertJsonCount(1, 'data.orders');

        $this->asAdmin()->getJson('/api/v1/admin/customers?tag=vip&per_page=200')->assertOk()
            ->assertJsonFragment(['key' => "user-{$user->id}"]);
    }

    #[Test]
    public function csv_export_starts_with_a_bom()
    {
        $response = $this->asAdmin()->get('/api/v1/admin/customers?format=csv')->assertOk();

        $this->assertStringStartsWith("\xEF\xBB\xBFName,Email", $response->getContent());
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function unknown_customers_are_not_found()
    {
        $this->asAdmin()->getJson('/api/v1/admin/customers/user-999999999')->assertNotFound();
        $this->asAdmin()->getJson('/api/v1/admin/customers/nonsense')->assertNotFound();
    }
}
