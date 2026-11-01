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
            'name' => "{$code} - Shade {$code} 100ml",
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

    #[Test]
    public function the_shop_lists_a_colour_line_once_but_search_and_admins_still_see_each_shade(): void
    {
        $first = $this->shade('1.0', 10);
        $second = $this->shade('3.0', 30);
        $this->shade('4.0', 40, ['stylist_only' => true]);
        $single = Product::factory()->create(['category_id' => $this->family->category_id, 'price' => 500, 'stylist_only' => false]);
        $list = fn (string $query = '') => collect($this->getJson("/api/v1/products?category_id={$this->family->category_id}{$query}")->assertOk()->json('data'))->keyBy('id');

        $cards = $list();
        $this->assertEqualsCanonicalizing([(string) $first->id, (string) $single->id], $cards->pluck('id')->all());
        $this->assertSame(2, $cards[$first->id]['shadeCount']);
        $this->assertSame('Colour Cream', $cards[$first->id]['shadeFamily']['name']);
        $this->assertCount(2, $cards[$first->id]['shadePreview']);
        $this->assertArrayNotHasKey('shadeCount', $cards[$single->id]);

        $this->assertSame([(string) $second->id], $list('&search=3.0')->pluck('id')->all());
        $this->assertCount(3, $list('&shades=all'));

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN])->createToken('test')->plainTextToken;
        $this->assertCount(4, $this->withToken($admin)->getJson("/api/v1/products?category_id={$this->family->category_id}")->json('data'));
    }

    #[Test]
    public function the_product_page_includes_the_line_catalogue_with_shades_linked_to_products(): void
    {
        $this->family->update(['catalogue' => [
            'title' => 'Colour chart',
            'pages' => [
                ['image' => 'catalogues/test/p1.webp', 'width' => 1600, 'height' => 1600, 'spots' => []],
                ['image' => 'catalogues/test/p2.webp', 'width' => 1600, 'height' => 3200, 'spots' => [
                    ['code' => '7.0', 'x' => 0.1, 'y' => 0.2, 'w' => 0.12, 'h' => 0.03],
                    ['code' => '6.0', 'x' => 0.1, 'y' => 0.3, 'w' => 0.12, 'h' => 0.03],
                    ['code' => '9.9', 'x' => 0.1, 'y' => 0.4, 'w' => 0.12, 'h' => 0.03],
                ]],
            ],
        ]]);
        $blonde = $this->shade('7.0', 70, ['quantity' => 0]);
        $this->shade('6.0', 60, ['stylist_only' => true]);

        $catalogue = $this->getJson("/api/v1/products/{$blonde->id}")->assertOk()->json('data.shadeFamily.catalogue');

        $this->assertSame('Colour chart', $catalogue['title']);
        $this->assertCount(2, $catalogue['pages']);
        $this->assertStringEndsWith('storage/catalogues/test/p2.webp', $catalogue['pages'][1]['image']);
        $spots = collect($catalogue['pages'][1]['spots'])->keyBy('code');
        $this->assertSame((string) $blonde->id, $spots['7.0']['productId']);
        $this->assertFalse($spots['7.0']['inStock']);
        $this->assertNull($spots['6.0']['productId']); // stylist-only: not for this guest
        $this->assertNull($spots['9.9']['productId']); // not sold by Tessa

        $card = collect($this->getJson("/api/v1/products?category_id={$this->family->category_id}")->json('data'))->firstWhere('id', (string) $blonde->id);
        $this->assertArrayNotHasKey('catalogue', $card['shadeFamily']);
    }
}
