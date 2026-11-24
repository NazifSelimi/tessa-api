<?php

// Proof tests from fix-plan-2026-11-24.md: each one failed on staging d9e6547.
// Batch 1: #3, #4 (owner rule Q2: one tube counts for only one deal), #14.
// The #7 and #8 tests are added with Batch 3.

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
}
