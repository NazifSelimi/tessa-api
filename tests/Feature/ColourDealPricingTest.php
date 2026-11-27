<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ColourDealPricingTest extends TestCase
{
    use DatabaseTransactions;

    private ProductFamily $family;
    /** @var Product[] */
    private array $shades = [];
    private Product $oxidant;
    private Bundle $deal;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('pricing.cost_percentage', 65);
        Setting::put('pricing.min_margin_percentage', 15);
        Setting::put('features', json_encode(['colour_deals' => true]));

        $this->oxidant = Product::factory()->create(['price' => 460, 'stylist_price' => 250, 'cost_price' => 90, 'quantity' => 100]);
        $this->family = ProductFamily::create([
            'name' => 'Test colour', 'slug' => 'test-colour-' . Str::random(6), 'is_active' => true,
            'brand_id' => $this->oxidant->brand_id, 'category_id' => $this->oxidant->category_id,
        ]);
        foreach (range(1, 3) as $i) {
            // Real cost well under the salon price, so the profit floor leaves room.
            $this->shades[] = Product::factory()->create([
                'product_family_id' => $this->family->id, 'price' => 540, 'stylist_price' => 300, 'cost_price' => 150, 'quantity' => 100,
            ]);
        }

        $this->deal = Bundle::create([
            'name' => 'Pick colours', 'audience' => 'stylist', 'promotion_type' => Bundle::MIX_MATCH, 'is_active' => true,
            'product_family_id' => $this->family->id,
            'tiers' => [['min' => 10, 'free' => 0, 'gift' => true], ['min' => 20, 'free' => 1, 'gift' => true], ['min' => 30, 'free' => 3, 'gift' => true]],
        ]);
        $this->deal->products()->sync([$this->oxidant->id => ['quantity' => 1, 'is_bonus' => true, 'bonus_quantity' => 1]]);
    }

    private function quote(array $quantities, bool $withOxidant, ?User $user)
    {
        $items = [];
        foreach ($quantities as $index => $qty) {
            $items[] = ['product_id' => $this->shades[$index]->id, 'qty' => $qty];
        }
        if ($withOxidant) {
            $items[] = ['product_id' => $this->oxidant->id, 'qty' => 1];
        }
        $request = $user ? $this->withToken($user->createToken('t')->plainTextToken) : $this;

        // Only picked deals count: the shop picks the deal when its picker adds the shades.
        return $request->postJson('/api/v1/cart/quote', ['items' => $items, 'bundle_ids' => [$this->deal->id]])->assertOk()->json('data');
    }

    #[Test]
    public function stylists_mix_any_shades_and_get_the_reward_of_the_step_reached()
    {
        $stylist = User::factory()->create(['role' => User::ROLE_STYLIST]);

        // 8 shades: no step yet, the cart says 2 more for the first one.
        $quote = $this->quote([3, 3, 2], true, $stylist);
        $this->assertSame(0.0, (float) $quote['bundleDiscount']);
        $this->assertSame(2, $quote['colourDeals'][0]['next']['need']);

        // 12 mixed shades + oxidant: the oxidant is free.
        $quote = $this->quote([5, 4, 3], true, $stylist);
        $this->assertEquals(250, $quote['bundleDiscount']);
        $this->assertContains((string) $this->deal->id, $quote['appliedBundleIds']);

        // 12 shades without the oxidant: the cart says the gift is waiting.
        $quote = $this->quote([5, 4, 3], false, $stylist);
        $this->assertEquals(0, $quote['bundleDiscount']);
        $this->assertTrue($quote['colourDeals'][0]['giftMissing']);

        // 31 shades + oxidant: 3 free colours (300 each) + oxidant.
        $quote = $this->quote([11, 10, 10], true, $stylist);
        $this->assertEquals(3 * 300 + 250, $quote['bundleDiscount']);
    }

    #[Test]
    public function profit_protection_is_a_switch_that_is_off_by_default()
    {
        foreach ($this->shades as $shade) {
            $shade->update(['cost_price' => null]); // no real cost: the 65% estimate is above the salon price
        }
        $this->oxidant->update(['cost_price' => null]);
        $stylist = User::factory()->create(['role' => User::ROLE_STYLIST]);

        $this->assertEquals(3 * 300 + 250, $this->quote([11, 10, 10], true, $stylist)['bundleDiscount']);

        Setting::put('features', json_encode(['colour_deals' => true, 'profit_protection' => true]));
        $this->app->forgetInstance(\App\Services\FeatureService::class);
        \Illuminate\Support\Facades\Cache::flush();
        $this->app['auth']->forgetGuards();
        $this->assertEquals(0, $this->quote([11, 10, 10], true, $stylist)['bundleDiscount']);
    }

    #[Test]
    public function colour_deals_are_their_own_switch()
    {
        Setting::put('features', json_encode(['colour_deals' => false, 'bundles' => true]));
        $this->app->forgetInstance(\App\Services\FeatureService::class);
        \Illuminate\Support\Facades\Cache::flush();
        $stylist = User::factory()->create(['role' => User::ROLE_STYLIST]);
        $this->assertEquals(0, $this->quote([11, 10, 10], true, $stylist)['bundleDiscount']);
        $this->app['auth']->forgetGuards();
        $names = collect($this->withToken($stylist->createToken('t2')->plainTextToken)->getJson('/api/v1/bundles')->json('data'))->pluck('name');
        $this->assertNotContains('Pick colours', $names);
    }

    #[Test]
    public function shoppers_do_not_get_a_salon_colour_deal()
    {
        $this->app['auth']->forgetGuards();
        $quote = $this->quote([11, 10, 10], true, null);
        $this->assertEquals(0, $quote['bundleDiscount']);
        $this->assertSame([], $quote['colourDeals']);
    }

    #[Test]
    public function admins_save_a_pick_any_colours_offer_with_steps()
    {
        $token = User::factory()->create(['role' => User::ROLE_ADMIN])->createToken('t')->plainTextToken;
        // Same colour line and gift as the running deal: saved, with a warning for Admin.
        $body = [
            'name' => 'Admin colour deal', 'audience' => 'stylist', 'promotion_type' => 'mix_match', 'is_active' => true,
            'product_family_id' => $this->family->id,
            'tiers' => [['min' => 10, 'free' => 0, 'gift' => true]],
            'products' => [['product_id' => $this->oxidant->id, 'quantity' => 1]],
        ];
        $this->withToken($token)->postJson('/api/v1/admin/bundles', $body)->assertCreated()
            ->assertJsonPath('meta.warnings.0', fn ($warning) => str_contains($warning, 'Pick colours'));
        $this->deal->update(['is_active' => false]);

        $response = $this->withToken($token)->postJson('/api/v1/admin/bundles', [
            'name' => 'Admin colour deal', 'audience' => 'stylist', 'promotion_type' => 'mix_match', 'is_active' => true,
            'product_family_id' => $this->family->id,
            'tiers' => [['min' => 30, 'free' => 3, 'gift' => true], ['min' => 10, 'free' => 0, 'gift' => true]],
            'products' => [['product_id' => $this->oxidant->id, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertSame([10, 30], array_column($response->json('data.tiers'), 'min'));
        $this->assertSame((string) $this->family->id, $response->json('data.familyId'));

        $this->withToken($token)->postJson('/api/v1/admin/bundles', [
            'name' => 'No line', 'audience' => 'stylist', 'promotion_type' => 'mix_match',
        ])->assertStatus(422);
    }
}
