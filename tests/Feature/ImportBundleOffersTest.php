<?php

namespace Tests\Feature;

use App\Models\Bundle;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportBundleOffersTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function it_creates_offers_once_and_drafts_offers_with_products_without_photos()
    {
        $withPhoto = Product::factory()->create(['visible_to_stylists' => true]);
        $withPhoto->images()->create(['name' => 'a.jpg', 'variant' => 'detail', 'sort_order' => 0]);
        $noPhoto = Product::factory()->create(['visible_to_stylists' => true]);

        $file = 'storage/framework/testing/offers-test.json';
        file_put_contents(base_path($file), json_encode([
            'audience' => 'stylist', 'promotion_type' => 'percentage', 'discount_percentage' => 15, 'ends_at' => '2030-01-01 00:00:00',
            'offers' => [
                ['name' => 'Test offer live', 'description' => 'x', 'products' => [['product_id' => $withPhoto->id, 'quantity' => 2]]],
                ['name' => 'Test offer draft', 'products' => [['product_id' => $withPhoto->id, 'quantity' => 1], ['product_id' => $noPhoto->id, 'quantity' => 1]]],
            ],
        ]));

        $this->artisan('offers:import', ['file' => $file, '--dry-run' => true])->assertSuccessful();
        $this->assertFalse(Bundle::where('name', 'Test offer live')->exists());

        $this->artisan('offers:import', ['file' => $file])->assertSuccessful();
        $live = Bundle::where('name', 'Test offer live')->firstOrFail();
        $this->assertTrue($live->is_active);
        $this->assertSame('stylist', $live->audience);
        $this->assertSame(2, (int) $live->products()->first()->pivot->quantity);
        $this->assertFalse(Bundle::where('name', 'Test offer draft')->firstOrFail()->is_active);

        $this->artisan('offers:import', ['file' => $file])->assertSuccessful();
        $this->assertSame(1, Bundle::where('name', 'Test offer live')->count());

        @unlink(base_path($file));
    }
}
