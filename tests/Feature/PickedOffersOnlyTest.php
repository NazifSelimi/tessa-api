<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The cart uses only the offers the customer picked (bundle_ids); nothing is
 * added by itself. Two picks that need the same units: the last one wins.
 */
class PickedOffersOnlyTest extends TestCase
{
    use DatabaseTransactions;

    private Product $shampoo;
    private Product $mask;
    private Bundle $duo;      // shampoo + mask −20%
    private Bundle $pair;     // 2 × shampoo −10%

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('features', json_encode(['bundles' => true, 'colour_deals' => true]));
        Setting::put('pricing.max_discount_percentage', 40);
        Setting::put('shipping.free_from', 3000);
        $this->app->forgetInstance(\App\Services\FeatureService::class);
        \Illuminate\Support\Facades\Cache::flush();
        Bundle::query()->update(['is_active' => false]);

        $this->shampoo = Product::factory()->create(['name' => 'Shampoo', 'price' => 1000, 'stylist_price' => 600, 'cost_price' => 100, 'quantity' => 50]);
        $this->mask = Product::factory()->create(['name' => 'Mask', 'price' => 500, 'stylist_price' => 300, 'cost_price' => 50, 'quantity' => 50]);
        $this->duo = $this->offer('Duo', 20, [$this->shampoo->id => 1, $this->mask->id => 1]);
        $this->pair = $this->offer('Pair', 10, [$this->shampoo->id => 2]);
    }

    private function offer(string $name, float $percent, array $lines): Bundle
    {
        $bundle = Bundle::create(['name' => $name, 'audience' => 'all', 'promotion_type' => 'percentage', 'discount_percentage' => $percent, 'is_active' => true]);
        $bundle->products()->sync(collect($lines)->map(fn ($qty) => ['quantity' => $qty])->all());

        return $bundle;
    }

    private function items(int $shampoo, int $mask = 0): array
    {
        return array_values(array_filter([
            $shampoo ? ['product_id' => $this->shampoo->id, 'qty' => $shampoo] : null,
            $mask ? ['product_id' => $this->mask->id, 'qty' => $mask] : null,
        ]));
    }

    private function quote(array $items, array $bundleIds = [], ?User $user = null): array
    {
        $this->app['auth']->forgetGuards();
        $request = $user ? $this->withToken($user->createToken('t')->plainTextToken) : $this;

        return $request->postJson('/api/v1/cart/quote', ['items' => $items, 'bundle_ids' => $bundleIds])->assertOk()->json('data');
    }

    #[Test]
    public function no_pick_means_no_discount_but_the_matching_offer_is_suggested()
    {
        $quote = $this->quote($this->items(1, 1));

        $this->assertEquals(0, $quote['bundleDiscount']);
        $this->assertSame([], $quote['appliedBundleIds']);
        $this->assertSame([], $quote['offersUsed']);
        // "Use offer Duo and save 300?"
        $this->assertSame((string) $this->duo->id, $quote['suggestedOffers'][0]['bundleId']);
        $this->assertEquals(300, $quote['suggestedOffers'][0]['saving']);
    }

    #[Test]
    public function a_pick_any_deal_gives_nothing_until_it_is_picked()
    {
        $family = ProductFamily::create(['name' => 'Colours', 'slug' => 'picked-' . Str::random(6), 'is_active' => true,
            'brand_id' => $this->shampoo->brand_id, 'category_id' => $this->shampoo->category_id]);
        $shade = Product::factory()->create(['product_family_id' => $family->id, 'price' => 540, 'stylist_price' => 300, 'cost_price' => 50, 'quantity' => 100]);
        $deal = Bundle::create(['name' => '10 → 1 free', 'audience' => 'stylist', 'promotion_type' => Bundle::MIX_MATCH, 'is_active' => true,
            'product_family_id' => $family->id, 'tiers' => [['min' => 10, 'free' => 1, 'gift' => false]]]);
        $salon = User::factory()->create(['role' => User::ROLE_STYLIST]);
        $tubes = [['product_id' => $shade->id, 'qty' => 10]];

        $quote = $this->quote($tubes, [], $salon);
        $this->assertEquals(0, $quote['bundleDiscount']);
        $this->assertSame([], $quote['colourDeals']);
        $this->assertSame((string) $deal->id, $quote['suggestedOffers'][0]['bundleId']);

        $quote = $this->quote($tubes, [$deal->id], $salon);
        $this->assertEquals(300, $quote['bundleDiscount']);
        $this->assertSame([], $quote['suggestedOffers']);
    }

    #[Test]
    public function a_picked_offer_gives_its_discount_and_says_which_offer_is_used()
    {
        $quote = $this->quote($this->items(1, 1), [$this->duo->id]);

        $this->assertEquals(300, $quote['bundleDiscount']);
        $this->assertSame([['bundleId' => (string) $this->duo->id, 'name' => 'Duo', 'saving' => 300]], $quote['offersUsed']);
        $this->assertEquals(1200, $quote['total'] - $quote['shipping']);
    }

    #[Test]
    public function a_picked_offer_without_all_its_products_says_what_to_add()
    {
        $quote = $this->quote($this->items(1), [$this->duo->id]);

        $this->assertEquals(0, $quote['bundleDiscount']);
        $skipped = $quote['skippedBundles'][0];
        $this->assertSame('Add Mask to use Duo.', $skipped['reason']);
        $this->assertSame([['productId' => (string) $this->mask->id, 'name' => 'Mask', 'qty' => 1]], $skipped['missing']);
    }

    #[Test]
    public function picking_a_second_offer_that_shares_products_replaces_the_first()
    {
        // 2 shampoos + 1 mask: Duo needs 1 shampoo + mask, Pair needs 2 shampoos. Not enough for both.
        $quote = $this->quote($this->items(2, 1), [$this->duo->id, $this->pair->id]);

        $this->assertSame([(string) $this->pair->id], $quote['appliedBundleIds']);
        $this->assertEquals(200, $quote['bundleDiscount']);
        $this->assertSame([[
            'bundleId' => (string) $this->duo->id, 'name' => 'Duo',
            'byBundleId' => (string) $this->pair->id, 'byName' => 'Pair',
        ]], $quote['replacedBundles']);
        $this->assertSame('Offer Duo was replaced by offer Pair (they share products).', $quote['skippedBundles'][0]['reason']);

        // Picked the other way round, Duo wins.
        $quote = $this->quote($this->items(2, 1), [$this->pair->id, $this->duo->id]);
        $this->assertSame([(string) $this->duo->id], $quote['appliedBundleIds']);

        // Enough units for both: both count.
        $quote = $this->quote($this->items(3, 1), [$this->duo->id, $this->pair->id]);
        $this->assertEquals(300 + 200, $quote['bundleDiscount']);
        $this->assertSame([], $quote['replacedBundles']);
    }

    #[Test]
    public function checkout_refuses_a_replaced_offer_instead_of_pricing_it_differently()
    {
        $buyer = User::factory()->create();
        $this->app['auth']->forgetGuards();
        $this->withToken($buyer->createToken('t')->plainTextToken)->postJson('/api/v1/orders', $this->orderBody($this->items(2, 1), [$this->duo->id, $this->pair->id]))
            ->assertStatus(422)->assertJsonValidationErrors('bundle_ids');
    }

    #[Test]
    public function the_cart_checkout_and_the_saved_order_show_the_same_total()
    {
        $buyer = User::factory()->create();
        $items = $this->items(3, 1);
        // The customer picked Pair, then Duo; the cart (and checkout, same quote) shows the total.
        $cart = $this->quote($items, [$this->pair->id, $this->duo->id], $buyer);
        $this->assertEquals(500, $cart['bundleDiscount']);

        // Checkout sends the offers the priced cart used.
        $this->app['auth']->forgetGuards();
        $id = $this->withToken($buyer->createToken('t')->plainTextToken)
            ->postJson('/api/v1/orders', $this->orderBody($items, $cart['appliedBundleIds']))
            ->assertStatus(201)->json('data.id');

        $order = Order::findOrFail($id);
        $this->assertEquals($cart['total'], (float) $order->total);
        $this->assertEquals($cart['discount'], (float) $order->discount);
        $this->assertEquals($cart['shipping'], (float) $order->shipping);
        $this->app['auth']->forgetGuards();
        $this->withToken($buyer->createToken('t2')->plainTextToken)->getJson("/api/v1/orders/{$id}")
            ->assertOk()->assertJsonPath('data.total', (float) $cart['total']);
    }

    #[Test]
    public function an_admin_in_salon_view_also_gets_only_picked_offers()
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->app['auth']->forgetGuards();
        $quote = $this->withToken($admin->createToken('t')->plainTextToken)->postJson('/api/v1/cart/quote', [
            'items' => $this->items(1, 1), 'view_as' => 'stylist',
        ])->assertOk()->json('data');

        $this->assertEquals(0, $quote['bundleDiscount']);
        $this->assertEquals(900, $quote['subtotal']);
        $this->assertSame((string) $this->duo->id, $quote['suggestedOffers'][0]['bundleId']);
        $this->assertEquals(180, $quote['suggestedOffers'][0]['saving']);
    }

    private function orderBody(array $items, array $bundleIds): array
    {
        return [
            'items' => $items,
            'bundle_ids' => $bundleIds,
            'shipping_address' => [
                'firstName' => 'Ana', 'lastName' => 'Test', 'email' => 'ana@example.com', 'phone' => '070111222',
                'address' => '1 Street', 'city' => 'Skopje', 'state' => 'MK', 'zip' => '1000',
            ],
            'payment_method' => 'cod',
        ];
    }
}
