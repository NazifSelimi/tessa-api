<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminBundleControlTest extends TestCase
{
    use DatabaseTransactions;

    private function bundle(string $name, string $audience = 'all', array $extra = []): Bundle
    {
        return Bundle::create(array_merge(['name' => $name, 'audience' => $audience, 'promotion_type' => 'percentage', 'discount_percentage' => 10, 'is_active' => true], $extra));
    }

    private function names($response): array
    {
        return collect($response->json('data'))->pluck('name')->all();
    }

    #[Test]
    public function each_audience_sees_only_its_offers()
    {
        Bundle::query()->update(['is_active' => false]);
        $this->bundle('For all');
        $this->bundle('For salons', 'stylist');
        $this->bundle('For shoppers', 'customer');

        $guest = $this->names($this->getJson('/api/v1/bundles'));
        $this->assertEqualsCanonicalizing(['For all', 'For shoppers'], $guest);

        $stylist = User::factory()->create(['role' => User::ROLE_STYLIST]);
        $this->app['auth']->forgetGuards();
        $salon = $this->names($this->withToken($stylist->createToken('t')->plainTextToken)->getJson('/api/v1/bundles'));
        $this->assertEqualsCanonicalizing(['For all', 'For salons'], $salon);

        $this->assertFalse(Bundle::where('name', 'For shoppers')->first()->isAvailableFor($stylist));
        $this->assertTrue(Bundle::where('name', 'For shoppers')->first()->isAvailableFor(null));
    }

    #[Test]
    public function admins_change_many_offers_at_once()
    {
        $a = $this->bundle('Bulk A');
        $b = $this->bundle('Bulk B', 'all', ['promotion_type' => 'fixed_price', 'bundle_price' => 500, 'discount_percentage' => null]);
        $token = User::factory()->create(['role' => User::ROLE_ADMIN])->createToken('t')->plainTextToken;
        $ids = [$a->id, $b->id];
        $post = fn (array $body) => $this->withToken($token)->postJson('/api/v1/admin/bundles/bulk', $body);

        $post(['ids' => $ids, 'action' => 'deactivate'])->assertOk()->assertJsonPath('data.updated', 2);
        $this->assertFalse($a->fresh()->is_active);

        $post(['ids' => $ids, 'action' => 'audience', 'value' => 'customer'])->assertOk();
        $this->assertSame('customer', $b->fresh()->audience);
        $post(['ids' => $ids, 'action' => 'audience', 'value' => 'nobody'])->assertStatus(422);

        $post(['ids' => $ids, 'action' => 'discount', 'value' => 20])->assertOk();
        $this->assertEquals(20, $a->fresh()->discount_percentage);
        $this->assertNull($b->fresh()->discount_percentage); // fixed price offers keep their price

        $post(['ids' => $ids, 'action' => 'ends_at', 'value' => '2027-01-31 23:59:59'])->assertOk();
        $this->assertSame('2027-01-31', $a->fresh()->ends_at->toDateString());

        $post(['ids' => $ids, 'action' => 'delete'])->assertOk();
        $this->assertSame(0, Bundle::whereIn('id', $ids)->count());
    }

    #[Test]
    public function only_admins_use_bulk_changes()
    {
        $a = $this->bundle('Bulk C');
        $this->postJson('/api/v1/admin/bundles/bulk', ['ids' => [$a->id], 'action' => 'delete'])->assertStatus(401);
        $this->assertTrue(Bundle::whereKey($a->id)->exists());
    }
}
