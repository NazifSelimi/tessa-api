<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\ReferralService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderTrashTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('features', '{}');
        Setting::put('features.plans', '{}');
        Setting::put('business.plan', 'premium');
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function asAdmin()
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->admin->createToken('t')->plainTextToken);
    }

    #[Test]
    public function an_order_goes_to_the_trash_and_comes_back()
    {
        $order = Order::factory()->create();

        $this->asAdmin()->deleteJson("/api/v1/admin/orders/{$order->id}")->assertOk();
        $this->assertSoftDeleted($order);
        $this->assertNotContains((string) $order->id, collect($this->asAdmin()->getJson('/api/v1/admin/orders?per_page=100')->json('data'))->pluck('id')->all());
        $this->assertContains((string) $order->id, collect($this->asAdmin()->getJson('/api/v1/admin/orders?trashed=only&per_page=100')->json('data'))->pluck('id')->all());

        $this->asAdmin()->postJson("/api/v1/admin/orders/{$order->id}/restore")->assertOk();
        $this->assertNotSoftDeleted($order);
    }

    #[Test]
    public function only_trashed_orders_can_be_deleted_forever()
    {
        $order = Order::factory()->create();
        $this->asAdmin()->deleteJson("/api/v1/admin/orders/{$order->id}/force")->assertNotFound();

        $this->asAdmin()->deleteJson("/api/v1/admin/orders/{$order->id}")->assertOk();
        $this->asAdmin()->deleteJson("/api/v1/admin/orders/{$order->id}/force")->assertOk();
        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
    }

    #[Test]
    public function trashing_takes_back_referral_credit_and_restoring_gives_it_again()
    {
        $stylist = User::factory()->create(['role' => User::ROLE_STYLIST]);
        $order = Order::factory()->create([
            'status' => Order::STATUS_PAID, 'referral_stylist_id' => $stylist->id, 'referral_commission' => 50,
        ]);
        $referrals = app(ReferralService::class);
        $referrals->syncOrder($order);
        $this->assertEquals(50.0, $referrals->balance($stylist));

        $this->asAdmin()->deleteJson("/api/v1/admin/orders/{$order->id}")->assertOk();
        $this->assertEquals(0.0, $referrals->balance($stylist));

        $this->asAdmin()->postJson("/api/v1/admin/orders/{$order->id}/restore")->assertOk();
        $this->assertEquals(50.0, $referrals->balance($stylist));
    }

    #[Test]
    public function deleting_orders_can_be_switched_off()
    {
        Setting::put('features', json_encode(['order_delete' => false]));
        $order = Order::factory()->create();
        $this->asAdmin()->deleteJson("/api/v1/admin/orders/{$order->id}")->assertNotFound();
        $this->assertNotSoftDeleted($order);
    }

    #[Test]
    public function trashing_an_active_order_returns_its_stock_and_restoring_takes_it_again()
    {
        $product = \App\Models\Product::factory()->create(['quantity' => 10]);
        $order = Order::factory()->create(['status' => Order::STATUS_PENDING]);
        $order->items()->create(['product_id' => $product->id, 'quantity' => 4, 'price' => 100]);

        $this->asAdmin()->deleteJson("/api/v1/admin/orders/{$order->id}")->assertOk();
        $this->assertSame(14, $product->fresh()->quantity);

        $this->asAdmin()->postJson("/api/v1/admin/orders/{$order->id}/restore")->assertOk();
        $this->assertSame(10, $product->fresh()->quantity);

        // A cancelled order already gave its stock back: trashing it changes nothing.
        $order->update(['status' => Order::STATUS_CANCELLED]);
        $this->asAdmin()->deleteJson("/api/v1/admin/orders/{$order->id}")->assertOk();
        $this->assertSame(10, $product->fresh()->quantity);
    }
}
