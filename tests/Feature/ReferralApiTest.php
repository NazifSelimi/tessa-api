<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StoreCreditTransaction;
use App\Models\User;
use App\Services\ReferralService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReferralApiTest extends TestCase
{
    use DatabaseTransactions;

    private User $stylist;
    private User $admin;
    /** Stylist codes need a signed-in client. */
    private User $client;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('pricing.cost_percentage', 65);
        Setting::put('pricing.min_margin_percentage', 15);
        Setting::put('referral.discount_percentage', 10);
        Setting::put('referral.commission_percentage', 5);
        Setting::put('referral.first_order_only', 1);
        Setting::put('referral.max_commission', 150);

        $this->stylist = User::factory()->create(['role' => User::ROLE_STYLIST, 'first_name' => 'Ана']);
        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->client = User::factory()->create(['role' => User::ROLE_USER, 'phone' => '075 555 111']);
        $this->product = Product::factory()->create(['price' => 2000, 'stylist_price' => 1200, 'quantity' => 50]);
    }

    private function code(): Coupon
    {
        return app(ReferralService::class)->codeFor($this->stylist);
    }

    private function checkout(array $payload, ?User $user = null, array $address = [])
    {
        $payload += [
            'items' => [['product_id' => $this->product->id, 'qty' => 1]],
            'shipping_address' => $address + [
                'firstName' => 'Mila', 'lastName' => 'Petrova', 'email' => 'mila@example.com',
                'phone' => '070 123 456', 'address' => 'Street 1', 'city' => 'Skopje', 'zip' => '1000',
            ],
        ];

        // Each call stands alone: never reuse a user the guard cached from an earlier request.
        $this->app['auth']->forgetGuards();

        return $user
            ? $this->withToken($user->createToken('t')->plainTextToken)->postJson('/api/v1/checkout', $payload)
            : $this->withoutToken()->postJson('/api/v1/checkout', $payload);
    }

    private function setStatus(string $orderId, string $status): void
    {
        $this->withToken($this->admin->createToken('t')->plainTextToken)
            ->putJson("/api/v1/admin/orders/{$orderId}/status", ['status' => $status])
            ->assertOk();
    }

    private function balance(User $user): float
    {
        return app(ReferralService::class)->balance($user);
    }

    #[Test]
    public function guests_must_sign_in_or_register_to_use_a_stylist_code()
    {
        $this->code();

        $this->checkout(['coupon_code' => 'ANA10'])
            ->assertStatus(422)
            ->assertJsonPath('errors.coupon_code.0', ReferralService::GUEST_ERROR);
        $this->assertSame(0, Order::count());

        $this->withoutToken()->postJson('/api/v1/coupons/validate', ['code' => 'ANA10', 'subtotal' => 2000])
            ->assertStatus(422)
            ->assertJsonPath('message', ReferralService::GUEST_ERROR)
            ->assertJsonPath('errors.requires_login', true);

        $this->withoutToken()->postJson('/api/v1/cart/quote', [
            'items' => [['product_id' => $this->product->id, 'qty' => 1]],
            'coupon_code' => 'ANA10',
        ])->assertOk()
            ->assertJsonPath('data.couponRequiresLogin', true)
            ->assertJsonPath('data.couponDiscount', 0);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->client->createToken('t')->plainTextToken)
            ->postJson('/api/v1/coupons/validate', ['code' => 'ANA10', 'subtotal' => 2000])
            ->assertOk()->assertJsonPath('data.valid', true);

        // Ordinary shop coupons still work without an account.
        Coupon::create(['code' => 'SHOP5', 'type' => 'percentage', 'value' => 5, 'quantity' => 10, 'is_active' => true]);
        $this->checkout(['coupon_code' => 'SHOP5'])->assertStatus(201);
    }

    #[Test]
    public function a_stylist_gets_a_code_from_their_first_name()
    {
        $code = $this->code();

        $this->assertSame('ANA10', $code->code);
        $this->assertSame(10, $code->value);
        $this->assertTrue($code->first_order_only);
        $this->assertSame($code->id, $this->code()->id);
    }

    #[Test]
    public function a_client_gets_the_discount_and_the_stylist_earns_credit_once_the_order_is_confirmed()
    {
        $this->code();

        $response = $this->checkout(['coupon_code' => 'ANA10'], $this->client);

        $response->assertStatus(201);
        $this->assertEqualsWithDelta(200, (float) $response->json('data.discount'), 0.01);
        $order = Order::findOrFail($response->json('data.id'));
        $this->assertSame($this->stylist->id, $order->referral_stylist_id);
        // 5% of the 1800 the client pays for the goods.
        $this->assertEqualsWithDelta(90, (float) $order->referral_commission, 0.01);
        $this->assertSame(0.0, $this->balance($this->stylist));

        $this->setStatus($order->id, 'confirmed');
        $this->withToken($this->admin->createToken('t')->plainTextToken)
            ->getJson('/api/v1/admin/orders')
            ->assertJsonPath('data.0.couponCode', 'ANA10')
            ->assertJsonPath('data.0.referral.stylistName', trim($this->stylist->first_name . ' ' . $this->stylist->last_name))
            ->assertJsonPath('data.0.referral.commission', 90);
        $this->setStatus($order->id, 'shipped');
        $this->assertEqualsWithDelta(90, $this->balance($this->stylist), 0.01);

        $this->setStatus($order->id, 'cancelled');
        $this->assertSame(0.0, $this->balance($this->stylist));
        $this->assertSame(2, StoreCreditTransaction::where('order_id', $order->id)->count());
    }

    #[Test]
    public function a_first_order_code_is_refused_for_a_returning_client_by_email_or_phone()
    {
        $this->code();
        $this->checkout([])->assertStatus(201);

        $this->checkout(['coupon_code' => 'ANA10'], $this->client)
            ->assertStatus(422)->assertJsonValidationErrors('coupon_code');

        $this->checkout(['coupon_code' => 'ANA10'], $this->client, ['email' => 'other@example.com', 'phone' => '+389 70 123 456'])
            ->assertStatus(422);

        $this->checkout(['coupon_code' => 'ANA10'], $this->client, ['email' => 'new@example.com', 'phone' => '071 999 888'])
            ->assertStatus(201);
    }

    #[Test]
    public function a_code_can_be_used_on_every_order_when_it_is_not_first_order_only()
    {
        $this->code()->update(['first_order_only' => false]);

        $this->checkout(['coupon_code' => 'ANA10'], $this->client)->assertStatus(201);
        $this->checkout(['coupon_code' => 'ANA10'], $this->client)->assertStatus(201);

        $this->assertSame(2, Order::where('referral_stylist_id', $this->stylist->id)->count());
    }

    #[Test]
    public function salon_accounts_cannot_use_a_referral_code()
    {
        $this->code();
        $other = User::factory()->create(['role' => User::ROLE_STYLIST]);

        $this->checkout(['coupon_code' => 'ANA10'], $this->stylist)->assertStatus(422);
        $this->checkout(['coupon_code' => 'ANA10'], $other)->assertStatus(422);
    }

    #[Test]
    public function a_switched_off_code_is_refused()
    {
        $this->code()->update(['is_active' => false]);

        $this->checkout(['coupon_code' => 'ANA10'], $this->client)->assertStatus(422);
    }

    #[Test]
    public function a_stylist_pays_with_store_credit_and_gets_it_back_if_the_order_is_cancelled()
    {
        app(ReferralService::class)->adjust($this->stylist, 500, 'Welcome credit', $this->admin);

        $response = $this->checkout(['use_store_credit' => true], $this->stylist);

        $response->assertStatus(201);
        // Salon price 1200 + shipping 150, minus 500 credit.
        $this->assertEqualsWithDelta(500, (float) $response->json('data.storeCreditUsed'), 0.01);
        $this->assertEqualsWithDelta(850, (float) $response->json('data.total'), 0.01);
        $this->assertSame(0.0, $this->balance($this->stylist));

        $this->withToken($this->stylist->createToken('t')->plainTextToken)
            ->postJson("/api/v1/orders/{$response->json('data.id')}/cancel")
            ->assertOk();
        $this->assertEqualsWithDelta(500, $this->balance($this->stylist), 0.01);
    }

    #[Test]
    public function store_credit_never_pays_more_than_the_order()
    {
        app(ReferralService::class)->adjust($this->stylist, 5000, 'Payout', $this->admin);

        $response = $this->checkout(['use_store_credit' => true], $this->stylist);

        $this->assertSame(0.0, (float) $response->json('data.total'));
        $this->assertEqualsWithDelta(3650, $this->balance($this->stylist), 0.01);
    }

    #[Test]
    public function a_negative_balance_never_raises_what_the_stylist_pays()
    {
        $this->code();
        $orderId = $this->checkout(['coupon_code' => 'ANA10'], $this->client)->json('data.id');
        $this->setStatus($orderId, 'confirmed');
        $this->app['auth']->forgetGuards();
        $this->checkout(['use_store_credit' => true], $this->stylist)->assertStatus(201);
        $this->app['auth']->forgetGuards();
        $this->setStatus($orderId, 'cancelled');
        $this->assertEqualsWithDelta(-90, $this->balance($this->stylist), 0.01);

        $this->app['auth']->forgetGuards();
        $response = $this->checkout(['use_store_credit' => true], $this->stylist);

        $this->assertSame(0.0, (float) $response->json('data.storeCreditUsed'));
        $this->assertEqualsWithDelta(1350, (float) $response->json('data.total'), 0.01);
    }

    #[Test]
    public function the_cart_preview_shows_credit_and_explains_a_refused_code()
    {
        $this->code();
        app(ReferralService::class)->adjust($this->stylist, 300, 'Credit', $this->admin);

        $response = $this->withToken($this->stylist->createToken('t')->plainTextToken)->postJson('/api/v1/cart/quote', [
            'items' => [['product_id' => $this->product->id, 'qty' => 1]],
            'coupon_code' => 'ANA10',
            'use_store_credit' => true,
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('data.couponError'));
        $this->assertSame(0.0, (float) $response->json('data.couponDiscount'));
        $this->assertEqualsWithDelta(300, (float) $response->json('data.storeCreditUsed'), 0.01);
        $this->assertEqualsWithDelta(1050, (float) $response->json('data.totalToPay'), 0.01);
    }

    #[Test]
    public function the_stylist_page_shows_results_and_lets_them_rename_the_code()
    {
        $this->code();
        $orderId = $this->checkout(['coupon_code' => 'ANA10'], $this->client)->json('data.id');
        $this->setStatus($orderId, 'confirmed');
        // Sanctum keeps the first user it resolved (the admin) for the rest of the test.
        $this->app['auth']->forgetGuards();
        $token = $this->stylist->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/stylist/referral')
            ->assertOk()
            ->assertJsonPath('data.code.code', 'ANA10')
            ->assertJsonPath('data.stats.clients', 1)
            ->assertJsonPath('data.orders.0.client', 'Mila P.');

        Coupon::factory()->create(['code' => 'TAKEN']);
        $this->withToken($token)->putJson('/api/v1/stylist/referral', ['code' => 'taken'])->assertStatus(422);
        $this->withToken($token)->putJson('/api/v1/stylist/referral', ['code' => 'ana-salon'])
            ->assertOk()->assertJsonPath('data.code.code', 'ANA-SALON');
    }

    #[Test]
    public function admins_change_the_terms_for_new_codes_or_for_every_code()
    {
        $code = $this->code();
        $token = $this->admin->createToken('t')->plainTextToken;

        $this->withToken($token)->putJson('/api/v1/admin/referrals/settings', ['discountPercentage' => 15, 'commissionPercentage' => 7])
            ->assertOk()->assertJsonPath('data.discountPercentage', 15);
        $this->assertSame(10, $code->fresh()->value);

        $this->withToken($token)->putJson('/api/v1/admin/referrals/settings', [
            'discountPercentage' => 12, 'commissionPercentage' => 6, 'firstOrderOnly' => false, 'applyToExisting' => true,
        ])->assertOk();
        $code->refresh();
        $this->assertSame(12, $code->value);
        $this->assertEqualsWithDelta(6, (float) $code->commission_percentage, 0.001);
        $this->assertFalse($code->first_order_only);
    }

    #[Test]
    public function the_admin_caps_what_a_stylist_earns_per_order()
    {
        $this->code();
        $token = $this->admin->createToken('t')->plainTextToken;

        $this->withToken($token)->putJson('/api/v1/admin/referrals/settings', ['maxCommission' => 50])
            ->assertOk()->assertJsonPath('data.maxCommission', 50);
        $this->withToken($token)->getJson('/api/v1/admin/referrals')
            ->assertJsonPath('data.settings.maxCommission', 50)
            ->assertJsonPath('data.codes.0.maxCommission', 50);

        // 5% of 1800 is 90, capped at 50.
        $this->withoutToken()->app['auth']->forgetGuards();
        $capped = $this->checkout(['coupon_code' => 'ANA10'], $this->client)->assertStatus(201);
        $this->assertEqualsWithDelta(50, (float) Order::findOrFail($capped->json('data.id'))->referral_commission, 0.01);

        // 0 removes the cap.
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->putJson('/api/v1/admin/referrals/settings', ['maxCommission' => 0])->assertOk();
        $this->code()->update(['first_order_only' => false]);
        $this->withoutToken()->app['auth']->forgetGuards();
        $open = $this->checkout(['coupon_code' => 'ANA10'], $this->client)->assertStatus(201);
        $this->assertEqualsWithDelta(90, (float) Order::findOrFail($open->json('data.id'))->referral_commission, 0.01);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->putJson('/api/v1/admin/referrals/settings', ['maxCommission' => -1])->assertStatus(422);
    }

    #[Test]
    public function the_stylist_credit_counts_inside_the_profit_floor()
    {
        $this->code();
        // Cost 1600 on a 2000 product: the floor is 1882.35, so only 117.65 may go.
        Setting::put('pricing.cost_percentage', 80);

        $response = $this->checkout(['coupon_code' => 'ANA10'], $this->client)->assertStatus(201);
        $order = Order::findOrFail($response->json('data.id'));

        // The discount gives way first (18 instead of 200), the credit stays 5% of 1982.
        $this->assertEqualsWithDelta(18, (float) $order->discount, 0.01);
        $this->assertEqualsWithDelta(99, (float) $order->referral_commission, 0.01);
        $this->assertLessThanOrEqual(117.65, (float) $order->discount + (float) $order->referral_commission);
    }

    #[Test]
    public function no_credit_is_earned_on_a_product_with_no_room_above_the_floor()
    {
        $this->code();
        Setting::put('pricing.cost_percentage', 90);

        $response = $this->checkout(['coupon_code' => 'ANA10'], $this->client)->assertStatus(201);
        $order = Order::findOrFail($response->json('data.id'));

        $this->assertEqualsWithDelta(0, (float) $order->discount, 0.01);
        $this->assertEqualsWithDelta(0, (float) $order->referral_commission, 0.01);
        $this->assertSame($this->stylist->id, $order->referral_stylist_id);
    }

    #[Test]
    public function the_admin_page_gives_every_stylist_a_code()
    {
        $other = User::factory()->create(['role' => User::ROLE_STYLIST, 'first_name' => 'Elena']);

        $codes = $this->withToken($this->admin->createToken('t')->plainTextToken)
            ->getJson('/api/v1/admin/referrals')->assertOk()->json('data.codes');

        $stylistIds = array_column(array_column($codes, 'stylist'), 'id');
        $this->assertContains((string) $this->stylist->id, $stylistIds);
        $this->assertContains((string) $other->id, $stylistIds);
        $this->assertSame(0, app(ReferralService::class)->ensureCodesForAllStylists());
    }

    #[Test]
    public function customers_cannot_open_the_stylist_or_admin_referral_pages()
    {
        $customer = User::factory()->create();
        $token = $customer->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/stylist/referral')->assertStatus(403);
        $this->withToken($token)->getJson('/api/v1/admin/referrals')->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->admin->createToken('t')->plainTextToken)->getJson('/api/v1/stylist/referral')->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/admin/referrals')->assertStatus(403);
        $this->withToken($token)->postJson("/api/v1/admin/users/{$customer->id}/store-credit", ['amount' => 100, 'note' => 'x'])
            ->assertStatus(403);
    }
}
