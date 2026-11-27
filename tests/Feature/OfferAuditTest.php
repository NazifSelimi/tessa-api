<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\Sale;
use App\Models\Setting;
use App\Services\OfferAuditor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The offers:audit check as a test: the cart price of each kind of offer must
 * be the price its offer card shows, and a wrong offer price is caught.
 */
class OfferAuditTest extends TestCase
{
    use DatabaseTransactions;

    private Product $a;
    private Product $b;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('pricing.cost_percentage', 65);
        Setting::put('pricing.min_margin_percentage', 15);
        Setting::put('pricing.max_discount_percentage', 40);
        Setting::put('features', json_encode(['bundles' => true, 'colour_deals' => true]));
        // Only the offers made here.
        Bundle::query()->update(['is_active' => false]);

        $this->a = Product::factory()->create(['name' => 'Shampoo A', 'price' => 1000, 'stylist_price' => 600, 'cost_price' => 200, 'quantity' => 100]);
        $this->b = Product::factory()->create(['name' => 'Mask B', 'price' => 500, 'stylist_price' => 300, 'cost_price' => 100, 'quantity' => 100]);
    }

    private function offer(array $attributes, array $lines): Bundle
    {
        $bundle = Bundle::create($attributes + ['is_active' => true, 'is_dynamic' => false, 'audience' => 'all']);
        $bundle->products()->sync($lines);

        return $bundle->fresh();
    }

    private function rows(Bundle ...$bundles): array
    {
        return app(OfferAuditor::class)->audit(new \Illuminate\Database\Eloquent\Collection($bundles));
    }

    private function row(array $report, Bundle $bundle, string $audience): array
    {
        return collect($report['rows'])->first(fn ($r) => $r['bundleId'] === (string) $bundle->id && $r['audience'] === $audience);
    }

    #[Test]
    public function every_kind_of_offer_costs_in_the_cart_what_its_card_says()
    {
        $fixed = $this->offer(['name' => 'Fixed', 'promotion_type' => 'fixed_price', 'bundle_price' => 1200, 'audience' => 'customer'],
            [$this->a->id => ['quantity' => 1], $this->b->id => ['quantity' => 1]]);
        $percent = $this->offer(['name' => 'Ten off', 'promotion_type' => 'percentage', 'discount_percentage' => 10],
            [$this->a->id => ['quantity' => 2]]);
        $bonus = $this->offer(['name' => '3 for 2', 'promotion_type' => 'bonus_items'],
            [$this->b->id => ['quantity' => 3, 'is_bonus' => true, 'bonus_quantity' => 1]]);

        $family = ProductFamily::create(['name' => 'Test colour', 'slug' => 'audit-colour-' . Str::random(6), 'is_active' => true,
            'brand_id' => $this->a->brand_id, 'category_id' => $this->a->category_id]);
        Product::factory()->count(2)->create(['product_family_id' => $family->id, 'price' => 540, 'stylist_price' => 300, 'cost_price' => 100, 'quantity' => 50]);
        $oxidant = Product::factory()->create(['price' => 460, 'stylist_price' => 250, 'cost_price' => 90, 'quantity' => 50]);
        $deal = $this->offer(['name' => 'Pick colours', 'promotion_type' => Bundle::MIX_MATCH, 'audience' => 'stylist',
            'product_family_id' => $family->id, 'tiers' => [['min' => 5, 'free' => 0, 'gift' => true], ['min' => 10, 'free' => 1, 'gift' => true]]],
            [$oxidant->id => ['quantity' => 1, 'is_bonus' => true, 'bonus_quantity' => 1]]);

        $report = $this->rows($fixed, $percent, $bonus, $deal);

        $wrong = collect($report['rows'])->where('verdict', OfferAuditor::WRONG)
            ->map(fn ($r) => "{$r['offer']} / {$r['audience']} {$r['case']}: " . implode('; ', $r['why']));
        $this->assertSame([], $wrong->values()->all(), 'Offer prices differ from their cards');

        $this->assertEquals(1200, $this->row($report, $fixed, 'customer')['real']);
        $this->assertSame(OfferAuditor::NA, $this->row($report, $fixed, 'stylist')['verdict']);
        $this->assertEquals(1800, $this->row($report, $percent, 'customer')['real']);
        $this->assertEquals(1080, $this->row($report, $percent, 'stylist')['real']);
        $this->assertEquals(1000, $this->row($report, $bonus, 'customer')['real']);
        $this->assertEquals(600, $this->row($report, $bonus, 'stylist')['real']);
        // Stylist, 10 shades + oxidant: one shade (300) and the oxidant (250) free.
        $step = collect($report['rows'])->first(fn ($r) => $r['bundleId'] === (string) $deal->id && $r['audience'] === 'stylist' && str_starts_with($r['case'], '10'));
        $this->assertEquals(10 * 300 + 250 - 550, $step['real']);
        // Without choosing the offer, the same products cost the full price.
        $this->assertEquals(1500, $this->row($report, $fixed, 'customer')['withoutOffer']);

        // Shampoo A is in two offers.
        $this->assertContains((string) $this->a->id, array_column($report['overlaps'], 'productId'));
    }

    #[Test]
    public function a_fixed_price_above_the_salon_prices_is_wrong_for_salons()
    {
        $fixed = $this->offer(['name' => 'Fixed for all', 'promotion_type' => 'fixed_price', 'bundle_price' => 1200],
            [$this->a->id => ['quantity' => 1], $this->b->id => ['quantity' => 1]]);

        $report = $this->rows($fixed);

        $this->assertSame(OfferAuditor::OK, $this->row($report, $fixed, 'customer')['verdict']);
        $salon = $this->row($report, $fixed, 'stylist');
        $this->assertSame(OfferAuditor::WRONG, $salon['verdict']);
        $this->assertEquals(900, $salon['real']);
        $this->assertStringContainsString('above the salon prices', implode(' ', $salon['why']));
        $this->assertTrue(OfferAuditor::hasWrong($report));
    }

    #[Test]
    public function a_discount_above_the_cap_or_a_sale_the_card_does_not_know_is_wrong()
    {
        $big = $this->offer(['name' => 'Half off', 'promotion_type' => 'percentage', 'discount_percentage' => 50],
            [$this->a->id => ['quantity' => 1]]);
        Sale::create(['product_id' => $this->b->id, 'sale_price' => 400, 'start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
        $onSale = $this->offer(['name' => 'B on sale', 'promotion_type' => 'percentage', 'discount_percentage' => 10],
            [$this->b->id => ['quantity' => 1]]);

        $report = $this->rows($big, $onSale);

        $capped = $this->row($report, $big, 'customer');
        $this->assertSame(OfferAuditor::WRONG, $capped['verdict']);
        $this->assertEquals(500, $capped['expected']);
        $this->assertEquals(600, $capped['real']);
        $this->assertStringContainsString('discount cap', implode(' ', $capped['why']));

        $sale = $this->row($report, $onSale, 'customer');
        $this->assertSame(OfferAuditor::WRONG, $sale['verdict']);
        $this->assertStringContainsString('on sale', implode(' ', $sale['why']));
    }

    #[Test]
    public function the_command_fails_when_an_offer_price_is_wrong()
    {
        $this->offer(['name' => 'Fixed for all', 'promotion_type' => 'fixed_price', 'bundle_price' => 1200],
            [$this->a->id => ['quantity' => 1], $this->b->id => ['quantity' => 1]]);

        $this->artisan('offers:audit', ['--fail-on-wrong' => true])->assertExitCode(1);
    }
}
