<?php

namespace Tests\Feature;

use App\Models\Image;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The shop card shows the first photo in gallery order: a tessa.mk photo stays
 * the main photo when extra angles are added after it.
 */
class ProductPrimaryImageTest extends TestCase
{
    use DatabaseTransactions;

    private function image(Product $product, string $name, string $variant, int $sort, array $metadata = []): Image
    {
        return $product->images()->create([
            'name' => $name, 'variant' => $variant, 'sort_order' => $sort,
            'background' => 'unknown', 'review_status' => 'ready', 'metadata' => $metadata ?: null,
        ]);
    }

    #[Test]
    public function added_angles_do_not_replace_the_tessa_photo(): void
    {
        $product = Product::factory()->withoutPhoto()->create();
        $legacy = $this->image($product, 'tessa.webp', 'legacy', 100);
        $this->image($product, 'catalog/detail/back.webp', 'detail', 110, ['angle' => true]);

        $this->assertSame($legacy->id, $product->fresh()->primaryImage()->id);
    }

    #[Test]
    public function uses_the_card_made_from_the_main_photo(): void
    {
        $product = Product::factory()->withoutPhoto()->create();
        $legacy = $this->image($product, 'tessa.webp', 'legacy', 100);
        $card = $this->image($product, 'catalog/card/tessa.webp', 'card', 20, ['derived_from' => $legacy->id]);
        $this->image($product, 'catalog/detail/back.webp', 'detail', 110, ['angle' => true]);

        $this->assertSame($card->id, $product->fresh()->primaryImage()->id);
    }

    #[Test]
    public function a_new_main_photo_uses_its_own_card(): void
    {
        $product = Product::factory()->withoutPhoto()->create();
        $front = $this->image($product, 'catalog/detail/front.webp', 'detail', 1);
        $newCard = $this->image($product, 'catalog/card/front.webp', 'card', 3, ['derived_from' => $front->id]);
        $legacy = $this->image($product, 'tessa.webp', 'legacy', 100);
        $this->image($product, 'catalog/card/tessa.webp', 'card', 20, ['derived_from' => $legacy->id]);

        $this->assertSame($newCard->id, $product->fresh()->primaryImage()->id);
    }
}
