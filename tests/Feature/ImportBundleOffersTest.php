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
        $noPhoto->images()->delete(); // the factory may add a photo

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

    /** NEW-4: the import keeps free units within the units of the offer, like the admin form. */
    #[Test]
    public function imported_free_units_never_exceed_the_offer_quantity()
    {
        $product = Product::factory()->create(['visible_to_stylists' => true]);
        $product->images()->create(['name' => 'a.jpg', 'variant' => 'detail', 'sort_order' => 0]);

        $file = 'storage/framework/testing/offers-bonus-test.json';
        file_put_contents(base_path($file), json_encode([
            'audience' => 'stylist', 'promotion_type' => 'bonus_items', 'ends_at' => '2030-01-01 00:00:00',
            'offers' => [
                ['name' => 'Test free 3 of 1', 'products' => [['product_id' => $product->id, 'quantity' => 1, 'bonus_quantity' => 3]]],
            ],
        ]));

        $this->artisan('offers:import', ['file' => $file])->assertSuccessful();
        $this->assertSame(1, (int) Bundle::where('name', 'Test free 3 of 1')->firstOrFail()->products()->first()->pivot->bonus_quantity);

        @unlink(base_path($file));
    }

}
