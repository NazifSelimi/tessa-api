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

/** "Pick any" offers that count a product list or several colour lines, with gifts per step. */
class PickAnyDealRulesTest extends TestCase
{
    use DatabaseTransactions;

    private User $stylist;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('features', json_encode(['colour_deals' => true]));
        $this->stylist = User::factory()->create(['role' => User::ROLE_STYLIST]);
    }

    private function product(array $attributes = []): Product
    {
        return Product::factory()->create($attributes + ['price' => 1000, 'stylist_price' => 500, 'quantity' => 100]);
    }

    private function deal(array $attributes, array $lines): Bundle
    {
        $deal = Bundle::create($attributes + ['name' => 'Deal ' . Str::random(5), 'audience' => 'stylist', 'promotion_type' => Bundle::MIX_MATCH, 'is_active' => true]);
        $deal->products()->sync($lines);

        return $deal;
    }

    private function quote(array $quantities): array
    {
        $items = collect($quantities)->map(fn ($qty, $id) => ['product_id' => $id, 'qty' => $qty])->values()->all();

        return $this->withToken($this->stylist->createToken('t')->plainTextToken)
            ->postJson('/api/v1/cart/quote', ['items' => $items])->assertOk()->json('data');
    }

    #[Test]
    public function a_product_list_deal_gives_the_cheapest_item_free_from_seven()
    {
        $small = $this->product(['stylist_price' => 430]);
        $large = $this->product(['stylist_price' => 794]);
        $this->deal(['tiers' => [['min' => 7, 'free' => 1], ['min' => 14, 'free' => 2]]], [
            $small->id => ['quantity' => 1, 'is_bonus' => false, 'bonus_quantity' => 0],
            $large->id => ['quantity' => 1, 'is_bonus' => false, 'bonus_quantity' => 0],
        ]);

        $quote = $this->quote([$small->id => 1, $large->id => 5]);
        $this->assertEquals(0, $quote['bundleDiscount']);
        $this->assertSame(1, $quote['colourDeals'][0]['next']['need']);
        $this->assertFalse($quote['colourDeals'][0]['isColour']);

        $this->assertEquals(430, $this->quote([$small->id => 1, $large->id => 6])['bundleDiscount']);
    }

    #[Test]
    public function a_step_can_give_several_units_of_the_gift()
    {
        $shampoo = $this->product();
        $mini = $this->product(['stylist_price' => 370]);
        $this->deal(['tiers' => [['min' => 3, 'gift' => true, 'gift_qty' => 3]]], [
            $shampoo->id => ['quantity' => 1, 'is_bonus' => false, 'bonus_quantity' => 0],
            $mini->id => ['quantity' => 1, 'is_bonus' => true, 'bonus_quantity' => 1],
        ]);

        $this->assertEquals(3 * 370, $this->quote([$shampoo->id => 3, $mini->id => 3])['bundleDiscount']);
        $quote = $this->quote([$shampoo->id => 3, $mini->id => 1]);
        $this->assertEquals(370, $quote['bundleDiscount']);
        $this->assertTrue($quote['colourDeals'][0]['giftMissing']);
    }

    #[Test]
    public function several_colour_lines_count_together_and_each_step_has_its_own_gift()
    {
        $families = collect(range(1, 2))->map(function () {
            $any = $this->product();

            return ProductFamily::create(['name' => 'Line ' . Str::random(4), 'slug' => 'line-' . Str::random(8), 'is_active' => true,
                'brand_id' => $any->brand_id, 'category_id' => $any->category_id]);
        });
        $a = $this->product(['product_family_id' => $families[0]->id, 'stylist_price' => 300]);
        $b = $this->product(['product_family_id' => $families[1]->id, 'stylist_price' => 230]);
        $brush = $this->product(['stylist_price' => 1090]);
        $kit = $this->product(['stylist_price' => 3790]);
        $this->deal([
            'product_family_id' => $families[0]->id, 'product_family_ids' => [$families[1]->id],
            'tiers' => [['min' => 30, 'gift_ids' => [$brush->id]], ['min' => 50, 'gift_ids' => [$kit->id]]],
        ], [
            $brush->id => ['quantity' => 1, 'is_bonus' => true, 'bonus_quantity' => 1],
            $kit->id => ['quantity' => 1, 'is_bonus' => true, 'bonus_quantity' => 1],
        ]);

        $this->assertEquals(1090, $this->quote([$a->id => 20, $b->id => 10, $brush->id => 1])['bundleDiscount']);
        $this->assertEquals(0, $this->quote([$a->id => 20, $b->id => 9, $brush->id => 1])['bundleDiscount']);
        $this->assertEquals(3790, $this->quote([$a->id => 25, $b->id => 25, $kit->id => 1, $brush->id => 1])['bundleDiscount']);
    }

    #[Test]
    public function admin_gift_lines_saved_with_free_one_stay_gifts_and_step_gifts_survive_a_save()
    {
        $any = $this->product();
        $family = ProductFamily::create(['name' => 'Line X', 'slug' => 'line-x-' . Str::random(6), 'is_active' => true, 'brand_id' => $any->brand_id, 'category_id' => $any->category_id]);
        $shade = $this->product(['product_family_id' => $family->id, 'stylist_price' => 300]);
        $oxidant = $this->product(['stylist_price' => 250]);
        $token = User::factory()->create(['role' => User::ROLE_ADMIN])->createToken('t')->plainTextToken;

        $id = $this->withToken($token)->postJson('/api/v1/admin/bundles', [
            'name' => 'Admin deal', 'audience' => 'stylist', 'promotion_type' => 'mix_match', 'is_active' => true,
            'product_family_id' => $family->id,
            'tiers' => [['min' => 10, 'free' => 0, 'gift' => false, 'gift_ids' => [$oxidant->id]]],
            'products' => [['product_id' => $oxidant->id, 'quantity' => 1, 'bonus_quantity' => 1]],
        ])->assertCreated()->json('data.id');

        // The admin form sends min / free / gift only.
        $this->withToken($token)->putJson("/api/v1/admin/bundles/{$id}", [
            'tiers' => [['min' => 10, 'free' => 0, 'gift' => false]],
        ])->assertOk();

        $this->app['auth']->forgetGuards();
        $quote = $this->quote([$shade->id => 10, $oxidant->id => 1]);
        $this->assertEquals(250, $quote['bundleDiscount']);
        $this->assertSame(10, $quote['colourDeals'][0]['count']);
    }

    /** #24: changing a step's min must not lose that step's gifts. */
    #[Test]
    public function step_gifts_survive_when_the_admin_changes_the_step_min()
    {
        $any = $this->product();
        $family = ProductFamily::create(['name' => 'Line Y', 'slug' => 'line-y-' . Str::random(6), 'is_active' => true, 'brand_id' => $any->brand_id, 'category_id' => $any->category_id]);
        $shade = $this->product(['product_family_id' => $family->id, 'stylist_price' => 300]);
        $oxidant = $this->product(['stylist_price' => 250]);
        $token = User::factory()->create(['role' => User::ROLE_ADMIN])->createToken('t')->plainTextToken;

        $id = $this->withToken($token)->postJson('/api/v1/admin/bundles', [
            'name' => 'Admin deal min change', 'audience' => 'stylist', 'promotion_type' => 'mix_match', 'is_active' => true,
            'product_family_id' => $family->id,
            'tiers' => [['min' => 10, 'free' => 0, 'gift' => false, 'gift_ids' => [$oxidant->id]]],
            'products' => [['product_id' => $oxidant->id, 'quantity' => 1, 'bonus_quantity' => 1]],
        ])->assertCreated()->json('data.id');

        // The admin form sends min / free / gift only; the min goes from 10 to 12.
        $this->withToken($token)->putJson("/api/v1/admin/bundles/{$id}", [
            'tiers' => [['min' => 12, 'free' => 0, 'gift' => false]],
        ])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->assertEquals(0, $this->quote([$shade->id => 11, $oxidant->id => 1])['bundleDiscount']);
        $this->assertEquals(250, $this->quote([$shade->id => 12, $oxidant->id => 1])['bundleDiscount']);
    }

}
