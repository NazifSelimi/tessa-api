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
}
