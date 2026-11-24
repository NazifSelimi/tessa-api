<?php

// Proof tests from fix-plan-2026-11-24.md: each one failed on staging d9e6547.
// Batch 1: #3, #4 (owner rule Q2: one tube counts for only one deal), #14.
// Batch 3: #7 (owner rule Q12), #8 (Q3), NEW-2, NEW-3, #9.

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MoneyStockProofTest extends TestCase
{
    use DatabaseTransactions;

    private function features(array $on): void
    {
        Setting::put('features', json_encode($on));
        $this->app->forgetInstance(\App\Services\FeatureService::class);
        \Illuminate\Support\Facades\Cache::flush();
    }

    private function shade(): Product
    {
        $base = Product::factory()->create();
        $family = ProductFamily::create([
            'name' => 'Proof colour', 'slug' => 'proof-colour-' . Str::random(6), 'is_active' => true,
            'brand_id' => $base->brand_id, 'category_id' => $base->category_id,
        ]);

        return Product::factory()->create([
            'product_family_id' => $family->id, 'price' => 540, 'stylist_price' => 300, 'cost_price' => 150, 'quantity' => 100,
        ]);
    }

    private function pickAny(Product $shade, string $audience): Bundle
    {
        return Bundle::create([
            'name' => "10 → 1 free ({$audience})", 'audience' => $audience, 'promotion_type' => Bundle::MIX_MATCH, 'is_active' => true,
            'product_family_id' => $shade->product_family_id,
            'tiers' => [['min' => 10, 'free' => 1, 'gift' => false]],
        ]);
    }

    private function quote(User $user, array $items, array $bundleIds = []): array
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken('t')->plainTextToken)
            ->postJson('/api/v1/cart/quote', ['items' => $items, 'bundle_ids' => $bundleIds])
            ->assertOk()->json('data');
    }

    /** Bug #3: two pick-any deals on the same colour line make the same tube free twice. */
    #[Test]
    public function two_pick_any_deals_do_not_make_the_same_tube_free_twice()
    {
        $this->features(['colour_deals' => true]);
        $shade = $this->shade();
        $this->pickAny($shade, 'stylist');
        $this->pickAny($shade, 'all');
        $salon = User::factory()->create(['role' => User::ROLE_STYLIST]);

        // 10 tubes × 300 = 3000. One tube free = 300 off. Today: 600 off (2 tubes free).
        $quote = $this->quote($salon, [['product_id' => $shade->id, 'qty' => 10]]);
        $this->assertEquals(300, $quote['bundleDiscount']);
    }

    /** Bug #4: units already used by a normal offer still count toward a pick-any deal. */
    #[Test]
    public function units_in_a_normal_offer_do_not_also_count_for_a_pick_any_deal()
    {
        $this->features(['colour_deals' => true, 'bundles' => true]);
        $shade = $this->shade();
        $this->pickAny($shade, 'all');
        $offer = Bundle::create([
            'name' => '10 tubes −20%', 'audience' => 'all', 'promotion_type' => 'percentage',
            'discount_percentage' => 20, 'is_active' => true,
        ]);
        $offer->products()->sync([$shade->id => ['quantity' => 10, 'is_bonus' => false, 'bonus_quantity' => 0]]);
        $salon = User::factory()->create(['role' => User::ROLE_STYLIST]);

        // 10 tubes × 300 = 3000. The offer gives 600 off and uses all 10 tubes.
        // Today the same 10 tubes also reach the pick-any step: +300 → 900 off.
        $quote = $this->quote($salon, [['product_id' => $shade->id, 'qty' => 10]], [$offer->id]);
        $this->assertEquals(600, $quote['bundleDiscount']);
    }

    /** Q2: when two pick-any deals count the same tubes, only the bigger saving applies. */
    #[Test]
    public function the_bigger_of_two_overlapping_pick_any_deals_applies()
    {
        $this->features(['colour_deals' => true]);
        $shade = $this->shade();
        $this->pickAny($shade, 'all');                       // 10 → 1 free (300)
        $bigger = $this->pickAny($shade, 'stylist');
        $bigger->update(['tiers' => [['min' => 10, 'free' => 2, 'gift' => false]]]); // 10 → 2 free (600)
        $salon = User::factory()->create(['role' => User::ROLE_STYLIST]);

        $quote = $this->quote($salon, [['product_id' => $shade->id, 'qty' => 10]]);
        $this->assertEquals(600, $quote['bundleDiscount']);
        $this->assertSame([(string) $bigger->id], $quote['appliedBundleIds']);
    }

    /** Q2: tubes the normal offer did not use still count toward a pick-any step. */
    #[Test]
    public function units_left_over_after_a_normal_offer_still_count_for_a_pick_any_deal()
    {
        $this->features(['colour_deals' => true, 'bundles' => true]);
        $shade = $this->shade();
        $this->pickAny($shade, 'all');
        $offer = Bundle::create([
            'name' => '10 tubes −20%', 'audience' => 'all', 'promotion_type' => 'percentage',
            'discount_percentage' => 20, 'is_active' => true,
        ]);
        $offer->products()->sync([$shade->id => ['quantity' => 10, 'is_bonus' => false, 'bonus_quantity' => 0]]);
        $salon = User::factory()->create(['role' => User::ROLE_STYLIST]);

        // 20 tubes: 10 in the offer (600 off), the other 10 reach the pick-any step (300 off).
        $quote = $this->quote($salon, [['product_id' => $shade->id, 'qty' => 20]], [$offer->id]);
        $this->assertEquals(900, $quote['bundleDiscount']);
        $this->assertSame(10, $quote['colourDeals'][0]['count']);
    }

    private function order(User $buyer, Product $product, int $qty): string
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($buyer->createToken('t')->plainTextToken)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'qty' => $qty]],
            'shipping_address' => [
                'firstName' => 'Ana', 'lastName' => 'Test', 'email' => 'ana@example.com', 'phone' => '070111222',
                'address' => '1 Street', 'city' => 'Skopje', 'state' => 'MK', 'zip' => '1000',
            ],
            'payment_method' => 'cod',
        ])->assertStatus(201)->json('data.id');
    }

    private function admin()
    {
        $this->app['auth']->forgetGuards();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        return $this->withToken($admin->createToken('t')->plainTextToken);
    }

    /** Bug #7: undoing a cancel takes stock again without checking there is enough. */
    #[Test]
    public function undoing_a_cancel_refuses_when_stock_is_short_unless_forced()
    {
        $product = Product::factory()->create(['price' => 100, 'quantity' => 3]);
        $first = $this->order(User::factory()->create(), $product, 3);         // stock 3 → 0
        $this->admin()->putJson("/api/v1/admin/orders/{$first}/status", ['status' => 'cancelled'])->assertOk(); // → 3
        $this->order(User::factory()->create(), $product, 3);                  // someone else buys them → 0

        // Before the fix: 200 OK and stock becomes −3 (or a 500 if the column is unsigned).
        // Owner rule Q12: refuse first, with a clear message.
        $this->admin()->putJson("/api/v1/admin/orders/{$first}/status", ['status' => 'confirmed'], ['Accept-Language' => 'en'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['stock' => "Only 0 left of {$product->name}, need 3."]);
        $this->assertSame(0, $product->fresh()->quantity);

        // "Proceed anyway" (force=true): done, stock may go below 0, and the admin is logged.
        \Illuminate\Support\Facades\Log::spy();
        $this->admin()->putJson("/api/v1/admin/orders/{$first}/status", ['status' => 'confirmed', 'force' => true])->assertOk();
        $this->assertSame(-3, $product->fresh()->quantity);
        \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(fn ($message) => str_contains($message, 'below zero'))->once();
    }

    /** Bug #8: trashing a shipped order puts its goods back on the shelf. */
    #[Test]
    public function trashing_a_shipped_order_does_not_add_stock()
    {
        $product = Product::factory()->create(['price' => 100, 'quantity' => 10]);
        $id = $this->order(User::factory()->create(), $product, 4);            // 10 → 6
        $this->admin()->putJson("/api/v1/admin/orders/{$id}/status", ['status' => 'shipped'])->assertOk();
        $this->admin()->deleteJson("/api/v1/admin/orders/{$id}")->assertOk();

        // Today: 10 (the 4 shipped pieces "come back").
        $this->assertSame(6, $product->fresh()->quantity);
    }

    /** NEW-2 (owner rule Q3): cancelling a shipped order never puts stock back, and undoing it takes nothing. */
    #[Test]
    public function cancelling_a_shipped_order_does_not_add_stock()
    {
        $product = Product::factory()->create(['price' => 100, 'quantity' => 10]);
        $id = $this->order(User::factory()->create(), $product, 4);            // 10 → 6
        $this->admin()->putJson("/api/v1/admin/orders/{$id}/status", ['status' => 'shipped'])->assertOk();

        $this->admin()->putJson("/api/v1/admin/orders/{$id}/status", ['status' => 'cancelled'])->assertOk();
        $this->assertSame(6, $product->fresh()->quantity);

        // Its stock was never put back, so undoing the cancel takes nothing again.
        $this->admin()->putJson("/api/v1/admin/orders/{$id}/status", ['status' => 'shipped'])->assertOk();
        $this->assertSame(6, $product->fresh()->quantity);
    }

    /** NEW-3 (owner rule Q12): restoring from the Trash refuses when there is not enough stock, unless forced. */
    #[Test]
    public function restoring_from_the_trash_checks_the_stock()
    {
        $product = Product::factory()->create(['price' => 100, 'quantity' => 3]);
        $id = $this->order(User::factory()->create(), $product, 3);            // 3 → 0
        $this->admin()->deleteJson("/api/v1/admin/orders/{$id}")->assertOk(); // → 3
        $this->order(User::factory()->create(), $product, 2);                  // → 1

        $this->admin()->postJson("/api/v1/admin/orders/{$id}/restore")->assertStatus(422)->assertJsonValidationErrors('stock');
        $this->assertSame(1, $product->fresh()->quantity);
        $this->assertSoftDeleted('orders', ['id' => $id]);

        $this->admin()->postJson("/api/v1/admin/orders/{$id}/restore", ['force' => true])->assertOk();
        $this->assertSame(-2, $product->fresh()->quantity);
    }

    /** Bug #14: a coupon "valid until today" must still work today. */
    #[Test]
    public function a_coupon_works_on_its_last_day()
    {
        // "Today" in the shop's time zone, so the test gives the same answer at any hour.
        $today = now(Coupon::SHOP_TIMEZONE)->toDateString();
        $coupon = Coupon::factory()->make(['quantity' => 5, 'expiration_date' => $today]);

        // Before the fix: false from 00:00 of the last day.
        $this->assertTrue($coupon->isValid());

        $yesterday = now(Coupon::SHOP_TIMEZONE)->subDay()->toDateString();
        $this->assertFalse(Coupon::factory()->make(['quantity' => 5, 'expiration_date' => $yesterday])->isValid());
    }
}
