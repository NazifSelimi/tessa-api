<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A colour line's product page lists its other shades (with swatch photos) so
 * customers can switch shade; only shades the viewer may buy are listed.
 */
class ProductShadesTest extends TestCase
{
    use DatabaseTransactions;

    private ProductFamily $family;

    protected function setUp(): void
    {
        parent::setUp();
        $brand = Brand::factory()->create();
        $category = Category::factory()->create();
        $this->family = ProductFamily::create([
            'brand_id' => $brand->id, 'category_id' => $category->id, 'slug' => 'colour-cream-test', 'name' => 'Colour Cream',
        ]);
    }

    private function shade(string $code, int $sort, array $attributes = [], bool $photo = true): Product
    {
        $factory = $photo ? Product::factory() : Product::factory()->withoutPhoto();

        return $factory->create($attributes + [
            'brand_id' => $this->family->brand_id,
            'category_id' => $this->family->category_id,
            'product_family_id' => $this->family->id,
            'shade_code' => $code,
            'shade_name' => "Shade {$code}",
            'shade_sort_order' => $sort,
            'swatch_image' => "swatches/{$code}.jpg",
            'stylist_only' => false,
            'price' => 500,
            'stylist_price' => 400,
            'quantity' => 5,
        ]);
    }

    #[Test]
    public function product_page_lists_the_buyable_shades_of_its_colour_line_in_order(): void
    {
        $light = $this->shade('9.0', 90);
        $dark = $this->shade('1.0', 10, ['quantity' => 0]);
        $hiddenInReview = $this->shade('5.0', 50, photo: false);
        $stylistOnly = $this->shade('6.0', 60, ['stylist_only' => true]);
        $otherLine = Product::factory()->create(['price' => 500, 'stylist_only' => false, 'shade_code' => '7.0']);

        $response = $this->getJson("/api/v1/products/{$light->id}")->assertOk()
            ->assertJsonPath('data.shadeCode', '9.0')
            ->assertJsonPath('data.shadeFamily.name', 'Colour Cream');

        $this->assertStringEndsWith('storage/swatches/9.0.jpg', $response->json('data.swatch'));
        $this->assertSame([(string) $dark->id, (string) $light->id], array_column($response->json('data.shades'), 'id'));
        $this->assertFalse($response->json('data.shades.0.inStock'));
        $this->assertStringEndsWith('storage/swatches/1.0.jpg', $response->json('data.shades.0.swatch'));

        $stylist = User::factory()->create(['role' => User::ROLE_STYLIST])->createToken('test')->plainTextToken;
        $this->withToken($stylist)->getJson("/api/v1/products/{$light->id}")
            ->assertJsonPath('data.shades.1.id', (string) $stylistOnly->id)
            ->assertJsonCount(3, 'data.shades');

        $this->assertNotContains((string) $hiddenInReview->id, array_column($response->json('data.shades'), 'id'));
        $this->flushHeaders();
        $this->assertArrayNotHasKey('shades', $this->getJson("/api/v1/products/{$otherLine->id}")->json('data'));
    }
}
