<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use App\Models\Bundle;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PricingApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // These tests check the profit floor, which is a switch (off by default).
        \App\Models\Setting::put('features', json_encode(['profit_protection' => true]));
        Setting::put('pricing.cost_percentage', 65);
        Setting::put('pricing.min_margin_percentage', 15);
    }

    private function checkout(array $payload, ?User $user = null)
    {
        $payload += ['shipping_address' => [
            'firstName' => 'Test', 'lastName' => 'Buyer', 'email' => 'buyer@example.com',
            'phone' => '070123123', 'address' => 'Street 1', 'city' => 'Skopje', 'zip' => '1000',
        ]];

        return $user
            ? $this->withToken($user->createToken('t')->plainTextToken)->postJson('/api/v1/checkout', $payload)
            : $this->postJson('/api/v1/checkout', $payload);
    }

    private function coupon(array $attributes): Coupon
    {
        return Coupon::factory()->create($attributes + ['quantity' => 5, 'expiration_date' => now()->addMonth()]);
    }

    #[Test]
    public function a_coupon_cannot_take_a_retail_order_below_cost_plus_margin()
    {
        $product = Product::factory()->create(['price' => 1000, 'stylist_price' => 600, 'quantity' => 10]);
        $coupon = $this->coupon(['code' => 'HALF', 'type' => 'percentage', 'value' => 50]);

        // Cost 650, floor 650 / 0.85 = 764.71, so at most 235.29 off, rounded down to whole MKD.
        $response = $this->checkout(['items' => [['product_id' => $product->id, 'qty' => 1]], 'coupon_code' => 'HALF']);

        $response->assertStatus(201);
        $this->assertSame(235.0, (float) $response->json('data.discount'));
        $this->assertSame(4, $coupon->fresh()->quantity);
    }

    #[Test]
    public function a_known_purchase_cost_allows_a_bigger_discount()
    {
        $product = Product::factory()->create(['price' => 1000, 'stylist_price' => 600, 'cost_price' => 400, 'quantity' => 10]);
        $this->coupon(['code' => 'HALF', 'type' => 'percentage', 'value' => 50]);

        $response = $this->checkout(['items' => [['product_id' => $product->id, 'qty' => 1]], 'coupon_code' => 'HALF']);

        $response->assertStatus(201);
        $this->assertEqualsWithDelta(500, (float) $response->json('data.discount'), 0.01);
    }

    #[Test]
    public function a_coupon_cut_to_nothing_is_not_used_up()
    {
        $stylist = User::factory()->create(['role' => User::ROLE_STYLIST]);
        $product = Product::factory()->create(['price' => 1000, 'stylist_price' => 600, 'quantity' => 10]);
        $coupon = $this->coupon(['code' => 'TEN', 'type' => 'percentage', 'value' => 10]);

        // Salon price 600 is already under the estimated floor, so no coupon discount.
        $response = $this->checkout(['items' => [['product_id' => $product->id, 'qty' => 1]], 'coupon_code' => 'TEN'], $stylist);

        $response->assertStatus(201);
        $this->assertSame(0.0, (float) $response->json('data.discount'));
        $this->assertSame(5, $coupon->fresh()->quantity);
    }

    #[Test]
    public function a_sale_above_the_salon_price_is_not_charged_to_salons()
    {
        $stylist = User::factory()->create(['role' => User::ROLE_STYLIST]);
        $product = Product::factory()->create(['price' => 1000, 'stylist_price' => 600, 'quantity' => 10]);
        Sale::create(['product_id' => $product->id, 'sale_price' => 800, 'start_date' => now()->subDay(), 'end_date' => now()->addDay()]);

        $response = $this->checkout(['items' => [['product_id' => $product->id, 'qty' => 1]]], $stylist);

        $response->assertStatus(201);
        $this->assertSame(750.0, (float) $response->json('data.total')); // 600 + 150 shipping
    }

    #[Test]
    public function repeated_lines_of_one_product_cannot_oversell()
    {
        $product = Product::factory()->create(['price' => 100, 'stylist_price' => 60, 'quantity' => 3]);

        $this->checkout(['items' => [
            ['product_id' => $product->id, 'qty' => 2],
            ['product_id' => $product->id, 'qty' => 2],
        ]])->assertStatus(422);

        $this->assertSame(3, $product->fresh()->quantity);
    }

    #[Test]
    public function the_cart_quote_matches_checkout_and_hides_costs_from_customers()
    {
        $product = Product::factory()->create(['price' => 1000, 'stylist_price' => 600, 'quantity' => 10]);
        $this->coupon(['code' => 'HALF', 'type' => 'percentage', 'value' => 50]);

        $quote = $this->postJson('/api/v1/cart/quote', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'coupon_code' => 'HALF',
        ])->assertOk()->json('data');

        $this->assertSame(235.0, (float) $quote['discount']);
        $this->assertTrue($quote['discountLimited']);
        $this->assertSame(1000.0 - 235 + 150, (float) $quote['total']);
        $this->assertSame(2000.0, (float) $quote['amountToFreeShipping']);
        $this->assertArrayNotHasKey('cost', $quote);
        $this->assertArrayNotHasKey('profit', $quote);
        $this->assertArrayNotHasKey('unitCost', $quote['lines'][0]);
    }

    #[Test]
    public function the_cart_quote_reports_a_bad_coupon_and_an_unmet_offer_without_failing()
    {
        $product = Product::factory()->create(['price' => 100, 'stylist_price' => 60, 'quantity' => 10]);
        $other = Product::factory()->create(['price' => 100, 'stylist_price' => 60, 'quantity' => 10]);
        $bundle = Bundle::create(['name' => 'Pair', 'audience' => 'all', 'promotion_type' => 'percentage', 'discount_percentage' => 10, 'is_active' => true]);
        $bundle->products()->attach([$product->id => ['quantity' => 1], $other->id => ['quantity' => 1]]);

        $quote = $this->postJson('/api/v1/cart/quote', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'bundle_ids' => [$bundle->id],
            'coupon_code' => 'NOPE',
        ])->assertOk()->json('data');

        $this->assertSame('Coupon not found.', $quote['couponError']);
        $this->assertSame((string) $bundle->id, $quote['skippedBundles'][0]['bundleId']);
        $this->assertSame(0.0, (float) $quote['discount']);
    }

    #[Test]
    public function admins_see_the_profit_of_an_offer_before_saving_it()
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $a = Product::factory()->create(['price' => 1000, 'stylist_price' => 600, 'quantity' => 10]);
        $b = Product::factory()->create(['price' => 500, 'stylist_price' => 300, 'quantity' => 10]);

        $result = $this->withToken($admin->createToken('t')->plainTextToken)->postJson('/api/v1/admin/bundles/simulate', [
            'promotion_type' => 'percentage',
            'discount_percentage' => 30,
            'products' => [['product_id' => $a->id, 'quantity' => 1], ['product_id' => $b->id, 'quantity' => 1]],
        ])->assertOk()->json('data');

        $retail = $result['retail'];
        $this->assertSame(1500.0, (float) $retail['regularPrice']);
        $this->assertSame(1050.0, (float) $retail['offerPrice']);
        $this->assertSame(975.0, (float) $retail['cost']);
        $this->assertTrue($retail['limitedByProfitFloor']);
        $this->assertSame(352.0, (float) $retail['appliedSaving']); // 1500 - 975 / 0.85, whole MKD
        $this->assertSame(0.0, (float) $result['salon']['appliedSaving']);
    }

    #[Test]
    public function only_admins_can_use_the_offer_calculator_and_settings()
    {
        $this->postJson('/api/v1/admin/bundles/simulate', [])->assertStatus(401);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->withToken($admin->createToken('t')->plainTextToken)
            ->putJson('/api/v1/admin/pricing-settings', ['costPercentage' => 60, 'minMarginPercentage' => 20])
            ->assertOk()
            ->assertJsonPath('data.costPercentage', 60)
            ->assertJsonPath('data.minMarginPercentage', 20);
    }

    private function quoteFor(array $items, ?string $coupon = null, ?User $user = null): array
    {
        $this->app['auth']->forgetGuards();
        $request = $user ? $this->withToken($user->createToken('t')->plainTextToken) : $this;

        return $request->postJson('/api/v1/cart/quote', ['items' => $items] + ($coupon ? ['coupon_code' => $coupon] : []))
            ->assertOk()->json('data');
    }

    /** Q1: all discounts together never go above the cap (default 40% of the goods). */
    #[Test]
    public function all_discounts_together_stop_at_the_discount_cap()
    {
        Setting::put('features', '{}');                       // profit protection off, as in the shop
        Setting::put('pricing.max_discount_percentage', 40);
        $product = Product::factory()->create(['price' => 1000, 'stylist_price' => 600, 'quantity' => 10]);
        $this->coupon(['code' => 'HALFOFF', 'type' => 'percentage', 'value' => 50]);

        $quote = $this->quoteFor([['product_id' => $product->id, 'qty' => 2]], 'HALFOFF');
        // 2000 of goods, 50% coupon = 1000, but at most 40% = 800.
        $this->assertEquals(800, $quote['discount']);
        $this->assertTrue($quote['discountCapped']);
        $this->assertFalse($quote['discountLimited']);
        $this->assertEquals(1350, $quote['total']);   // 2000 - 800 = 1200, plus 150 shipping (2000 is under the 3000 free-shipping limit)

        // Checkout charges the same.
        $response = $this->checkout(['items' => [['product_id' => $product->id, 'qty' => 2]], 'coupon_code' => 'HALFOFF']);
        $response->assertStatus(201);
        $this->assertSame(800.0, (float) $response->json('data.discount'));
    }

    /** Q1: the default cap is 40% when nobody has set it. */
    #[Test]
    public function the_discount_cap_is_40_percent_by_default()
    {
        config(['tessa.max_discount_percentage' => 40.0]);
        Setting::query()->whereKey('pricing.max_discount_percentage')->delete();

        $this->assertSame(40.0, app(\App\Services\PricingService::class)->settings()['maxDiscountPercentage']);
    }

    /** Q6: the free-shipping amount is a setting, counted before (default) or after discounts. */
    #[Test]
    public function free_shipping_follows_the_admin_settings()
    {
        Setting::put('features', '{}');
        Setting::put('pricing.max_discount_percentage', 100);
        $product = Product::factory()->create(['price' => 1000, 'stylist_price' => 600, 'quantity' => 10]);
        $this->coupon(['code' => 'HALFSHIP', 'type' => 'percentage', 'value' => 50]);
        $items = [['product_id' => $product->id, 'qty' => 3]];   // 3000 of goods, 1500 after the coupon

        // Default: 3000, before discounts → free.
        Setting::query()->whereKey(['shipping.free_from', 'shipping.free_after_discounts'])->delete();
        $quote = $this->quoteFor($items, 'HALFSHIP');
        $this->assertEquals(0, $quote['shipping']);
        $this->assertEquals(3000, $quote['freeShippingFrom']);

        // After discounts: 1500 < 3000 → 150, and 1500 more for free shipping.
        Setting::put('shipping.free_after_discounts', '1');
        $quote = $this->quoteFor($items, 'HALFSHIP');
        $this->assertEquals(150, $quote['shipping']);
        $this->assertEquals(1500, $quote['amountToFreeShipping']);

        // A new amount from Admin.
        Setting::put('shipping.free_after_discounts', '0');
        Setting::put('shipping.free_from', 4000);
        $quote = $this->quoteFor($items, 'HALFSHIP');
        $this->assertEquals(150, $quote['shipping']);
        $this->assertEquals(1000, $quote['amountToFreeShipping']);

        // The shop reads the same numbers before a cart is priced.
        $this->getJson('/api/v1/shop-settings')->assertOk()
            ->assertJsonPath('data.freeShippingFrom', 4000)->assertJsonPath('data.shippingCost', 150);
    }

    /** Q1 + Q6: admins change the three settings; customers cannot. */
    #[Test]
    public function admins_set_free_shipping_and_the_discount_cap()
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->withToken($admin->createToken('t')->plainTextToken)->putJson('/api/v1/admin/pricing-settings', [
            'freeShippingFrom' => 2500, 'freeShippingAfterDiscounts' => true, 'maxDiscountPercentage' => 35,
        ])->assertOk()
            ->assertJsonPath('data.freeShippingFrom', 2500)
            ->assertJsonPath('data.freeShippingAfterDiscounts', true)
            ->assertJsonPath('data.maxDiscountPercentage', 35);

        $this->withToken($admin->createToken('t')->plainTextToken)
            ->putJson('/api/v1/admin/pricing-settings', ['maxDiscountPercentage' => 150])->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $customer = User::factory()->create();
        $this->withToken($customer->createToken('t')->plainTextToken)
            ->putJson('/api/v1/admin/pricing-settings', ['freeShippingFrom' => 1])->assertForbidden();
    }

}
