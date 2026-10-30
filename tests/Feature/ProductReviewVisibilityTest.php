<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Products without a real photo, brand or category are in review: admins see
 * and are told why, everyone else never receives them.
 */
class ProductReviewVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->category = Category::factory()->create();
    }

    private function token(int $role): string
    {
        return User::factory()->create(['role' => $role])->createToken('test')->plainTextToken;
    }

    /** withToken() persists between requests, so each call starts clean. */
    private function as(?string $token): static
    {
        $this->flushHeaders();

        return $token ? $this->withToken($token) : $this;
    }

    private function listedIds(?string $token = null, string $query = ''): array
    {
        return collect($this->as($token)->getJson("/api/v1/products?category_id={$this->category->id}{$query}")->assertOk()->json('data'))
            ->pluck('id')->all();
    }

    private function product(array $attributes = [], bool $photo = true): Product
    {
        $factory = Product::factory();
        if (!$photo) {
            $factory = $factory->withoutPhoto();
        }

        return $factory->create($attributes + [
            'category_id' => $this->category->id,
            'stylist_only' => false,
            'price' => 500,
            'stylist_price' => 400,
            'quantity' => 5,
        ]);
    }

    #[Test]
    public function products_without_a_real_photo_are_admin_only(): void
    {
        $ready = $this->product();
        $noPhoto = $this->product(photo: false);
        $placeholder = $this->product(photo: false);
        $placeholder->images()->create(['name' => 'catalog-placeholders/pdf-product.svg', 'variant' => 'card']);

        foreach ([null, $this->token(User::ROLE_STYLIST)] as $token) {
            $ids = $this->listedIds($token);
            $this->assertContains((string) $ready->id, $ids);
            $this->assertNotContains((string) $noPhoto->id, $ids);
            $this->assertNotContains((string) $placeholder->id, $ids);
        }

        $this->as(null)->getJson("/api/v1/products/{$placeholder->id}")->assertNotFound();

        $admin = $this->token(User::ROLE_ADMIN);
        $this->assertContains((string) $placeholder->id, $this->listedIds($admin));
        $this->as($admin)->getJson("/api/v1/products/{$placeholder->id}")
            ->assertOk()
            ->assertJsonPath('data.inReview', true)
            ->assertJsonPath('data.reviewReasons', ['no_photo']);
        $this->as($admin)->getJson("/api/v1/products/{$ready->id}")
            ->assertJsonPath('data.inReview', false);
    }

    #[Test]
    public function importer_brand_and_review_category_keep_a_product_in_review(): void
    {
        $supplierBrand = $this->product(['brand_id' => Brand::factory()->create(['name' => 'Supplier catalogue'])->id]);
        $reviewCategory = $this->product(['category_id' => Category::factory()->create(['name' => 'Catalogue review'])->id]);

        $this->as(null)->getJson("/api/v1/products/{$supplierBrand->id}")->assertNotFound();
        $this->getJson("/api/v1/products/{$reviewCategory->id}")->assertNotFound();

        $admin = $this->token(User::ROLE_ADMIN);
        $this->as($admin)->getJson("/api/v1/products/{$supplierBrand->id}")->assertJsonPath('data.reviewReasons', ['no_brand']);
        $this->as($admin)->getJson("/api/v1/products/{$reviewCategory->id}")->assertJsonPath('data.reviewReasons', ['no_category']);
    }

    #[Test]
    public function admins_can_list_only_products_in_review(): void
    {
        $ready = $this->product();
        $inReview = $this->product(photo: false);
        $admin = $this->token(User::ROLE_ADMIN);

        $this->assertSame([(string) $inReview->id], $this->listedIds($admin, '&review=in_review'));
        $this->assertSame([(string) $ready->id], $this->listedIds($admin, '&review=ready'));
        $this->assertSame([], $this->listedIds(null, '&review=in_review'));
    }

    #[Test]
    public function original_photo_wins_over_an_automatic_catalogue_audit_image(): void
    {
        $product = $this->product(photo: false);
        $product->images()->create(['name' => '1721139785_original.webp', 'variant' => 'legacy']);
        $product->images()->create([
            'name' => 'catalog/detail/audit.webp',
            'variant' => 'detail',
            'metadata' => ['catalog_audit_source' => 'name-match'],
        ]);

        $customer = $this->getJson("/api/v1/products/{$product->id}")->assertOk();
        $this->assertStringEndsWith('1721139785_original.webp', $customer->json('data.image'));
        $this->assertCount(1, $customer->json('data.media'));

        $admin = $this->as($this->token(User::ROLE_ADMIN))->getJson("/api/v1/products/{$product->id}");
        $this->assertCount(2, $admin->json('data.media'));
    }

    #[Test]
    public function a_photo_an_admin_uploaded_still_wins_over_the_original(): void
    {
        $product = $this->product(photo: false);
        $product->images()->create(['name' => 'old.webp', 'variant' => 'legacy']);
        $product->images()->create(['name' => 'catalog/detail/new.webp', 'variant' => 'detail']);

        $this->assertStringEndsWith('catalog/detail/new.webp', $this->getJson("/api/v1/products/{$product->id}")->json('data.image'));
    }
}
