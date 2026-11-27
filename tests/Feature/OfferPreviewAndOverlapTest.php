<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OfferPreviewAndOverlapTest extends TestCase
{
    use DatabaseTransactions;

    private function adminToken(): string
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN])->createToken('t')->plainTextToken;
    }

    /** Salon set: 4 products, salon prices 794+794+849+849 = 3286, set price 2900. */
    private function salonSet(): array
    {
        $products = collect([[1239, 794], [1239, 794], [1499, 849], [1319, 849]])
            ->map(fn ($p) => Product::factory()->create(['price' => $p[0], 'stylist_price' => $p[1], 'cost_price' => 100, 'quantity' => 10]));
        $bundle = Bundle::create(['name' => 'Salon set', 'audience' => 'stylist', 'promotion_type' => 'fixed_price', 'bundle_price' => 2900, 'is_active' => true]);
        $bundle->products()->attach($products->mapWithKeys(fn ($p) => [$p->id => ['quantity' => 1]])->all());

        return [$bundle, $products];
    }

    #[Test]
    public function admin_in_salon_view_gets_the_salon_set_price()
    {
        [$bundle, $products] = $this->salonSet();
        $body = [
            'items' => $products->map(fn ($p) => ['product_id' => $p->id, 'qty' => 1])->all(),
            'bundle_ids' => [$bundle->id],
        ];
        $token = $this->adminToken();

        $salon = $this->withToken($token)->postJson('/api/v1/cart/quote', $body + ['view_as' => 'stylist'])->assertOk()->json('data');
        $this->assertSame(3286.0, (float) $salon['subtotal']);
        $this->assertSame(386.0, (float) $salon['bundleDiscount']);
        $this->assertSame(2900.0, (float) $salon['total'] - (float) $salon['shipping']);

        $stylist = User::factory()->create(['role' => User::ROLE_STYLIST])->createToken('t')->plainTextToken;
        $this->app['auth']->forgetGuards();
        $real = $this->withToken($stylist)->postJson('/api/v1/cart/quote', $body + ['view_as' => 'customer'])->assertOk()->json('data');
        // A salon cannot switch itself to other prices.
        $this->assertSame(386.0, (float) $real['bundleDiscount']);

        $this->app['auth']->forgetGuards();
        $noSet = $this->withToken($stylist)->postJson('/api/v1/cart/quote', ['items' => $body['items']])->assertOk()->json('data');
        $this->assertSame(3286.0, (float) $noSet['subtotal']);
        $this->assertSame(0.0, (float) $noSet['bundleDiscount']);
    }

    #[Test]
    public function a_product_can_be_in_only_one_running_offer()
    {
        [$bundle, $products] = $this->salonSet();
        $token = $this->adminToken();
        $post = fn (array $body) => $this->withToken($token)->postJson('/api/v1/admin/bundles', $body + [
            'promotion_type' => 'percentage', 'discount_percentage' => 10, 'is_active' => true,
        ]);

        $post(['name' => 'Clash', 'audience' => 'all', 'products' => [['product_id' => $products[2]->id, 'quantity' => 1]]])
            ->assertStatus(422)->assertJsonValidationErrors('products');
        $this->assertFalse(Bundle::where('name', 'Clash')->exists());

        // Shoppers only never meets a salons-only offer.
        $post(['name' => 'Shoppers', 'audience' => 'customer', 'products' => [['product_id' => $products[2]->id, 'quantity' => 1]]])
            ->assertCreated();

        // Switched off, then switching it on clashes.
        $off = Bundle::create(['name' => 'Later', 'audience' => 'stylist', 'promotion_type' => 'percentage', 'discount_percentage' => 5, 'is_active' => false]);
        $off->products()->attach([$products[0]->id => ['quantity' => 1]]);
        $this->withToken($token)->postJson('/api/v1/admin/bundles/bulk', ['ids' => [$off->id], 'action' => 'activate'])->assertStatus(422);
        $this->assertFalse($off->fresh()->is_active);

        // Dates that do not meet are fine.
        $bundle->update(['ends_at' => now()->addDays(3)]);
        $this->withToken($token)->putJson("/api/v1/admin/bundles/{$off->id}", ['is_active' => true, 'starts_at' => now()->addDays(4)->toDateTimeString()])->assertOk();
    }
}
