<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The admin "Fix products" list: read-only, most important problems first. */
class AdminProductProblemsTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): string
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN])->createToken('t')->plainTextToken;
    }

    private function problems(array $rows, int $id): array
    {
        return collect($rows)->firstWhere('id', $id)['problems'] ?? [];
    }

    #[Test]
    public function it_lists_problems_sorted_with_live_products_without_photo_first(): void
    {
        $shampoo = Category::factory()->create(['name' => 'Shampoo']);
        $mask = Category::factory()->create(['name' => 'Mask']);
        $brand = Brand::factory()->create(['name' => 'Fanola']);
        $base = ['brand_id' => $brand->id, 'visible_to_guests' => true, 'visible_to_customers' => true, 'visible_to_stylists' => true, 'stylist_only' => false];

        $noPhotoLive = Product::factory()->withoutPhoto()->create($base + ['name' => 'Zz Live 250ml', 'category_id' => $shampoo->id, 'quantity' => 9, 'price' => 500, 'stylist_price' => 400]);
        $wrongCategory = Product::factory()->create($base + ['name' => 'Botugen Shampoo 1000ml', 'category_id' => $mask->id, 'quantity' => 3, 'price' => 500, 'stylist_price' => 600, 'manufacturer_code' => 'TEST-DUP-1', 'package_label' => '100 ml']);
        $twin = Product::factory()->create($base + ['name' => 'Twin product', 'category_id' => $shampoo->id, 'quantity' => 0, 'price' => 0, 'stylist_price' => 300, 'manufacturer_code' => 'test-dup-1']);
        $before = Product::count();

        $response = $this->withToken($this->admin())->getJson('/api/v1/admin/products/problems')->assertOk()
            ->assertJsonStructure(['total_products', 'products_with_problems', 'summary' => [['group', 'label', 'products']], 'products' => [['id', 'name', 'groups', 'problems']]]);
        $rows = $response->json('products');

        $this->assertSame($noPhotoLive->id, $rows[0]['id'], 'a live product without a photo comes first');
        $codes = fn (Product $p) => array_column($this->problems($rows, $p->id), 'code');
        $this->assertContains('no_photo', $codes($noPhotoLive));
        $this->assertContains('category_mismatch', $codes($wrongCategory));
        $this->assertContains('stylist_not_cheaper', $codes($wrongCategory));
        $this->assertContains('size_mismatch', $codes($wrongCategory));
        $this->assertContains('same_code', $codes($wrongCategory));
        $this->assertContains('same_code', $codes($twin));
        $this->assertContains('no_price', $codes($twin));
        $this->assertContains('visible_no_stock', $codes($twin));

        $photoGroup = collect($response->json('summary'))->firstWhere('group', 'photo')['products'];
        $this->assertSame(collect($rows)->filter(fn ($r) => in_array('photo', $r['groups'], true))->count(), $photoGroup);
        $this->assertSame($before, Product::count(), 'the check changes nothing');
    }

    #[Test]
    public function only_admins_can_read_it(): void
    {
        $this->getJson('/api/v1/admin/products/problems')->assertUnauthorized();
        $customer = User::factory()->create(['role' => User::ROLE_USER])->createToken('t')->plainTextToken;
        $this->withToken($customer)->getJson('/api/v1/admin/products/problems')->assertForbidden();
    }

    #[Test]
    public function photo_from_url_refuses_internal_addresses(): void
    {
        $product = Product::factory()->create();
        $count = $product->images()->count();
        $this->withToken($this->admin())->postJson("/api/v1/admin/products/{$product->id}/images/from-url", ['url' => 'http://127.0.0.1/secret.png'])
            ->assertStatus(422);
        $this->assertSame($count, $product->images()->count());
    }

    #[Test]
    public function image_preview_downloads_without_saving_and_refuses_internal_addresses(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), 200, ['Content-Type' => 'image/png'])]);
        $images = \App\Models\Image::count();
        $admin = $this->admin();
        $this->withToken($admin)->post('/api/v1/admin/products/image-preview', ['url' => 'https://example.com/a.png'], ['Accept' => 'application/json'])
            ->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->withToken($admin)->postJson('/api/v1/admin/products/image-preview', ['url' => 'http://10.0.0.1/a.png'])->assertStatus(422);
        $this->assertSame($images, \App\Models\Image::count());
    }

    #[Test]
    public function admin_can_save_size_and_manufacturer_code_inline(): void
    {
        $product = Product::factory()->create(['name' => 'Inline 250ml']);
        $this->withToken($this->admin())->putJson("/api/v1/admin/products/{$product->id}", [
            'manufacturer_code' => 'ABC-1', 'size_value' => '250', 'size_unit' => 'ml', 'translations' => ['en' => 'Nice', 'mk' => 'Убаво', 'shq' => 'Bukur'],
        ])->assertOk();
        $product->refresh();
        $this->assertSame(['ABC-1', '250', 'ml'], [$product->manufacturer_code, $product->size_value, $product->size_unit]);
        $this->assertSame('Убаво', $product->translations()->where('locale', 'mk')->value('description'));
    }

    #[Test]
    public function a_photo_removed_from_the_shop_is_kept_but_no_longer_counts(): void
    {
        $product = Product::factory()->create();
        $image = $product->images()->first();
        $this->withToken($this->admin())->patchJson("/api/v1/admin/products/{$product->id}/images/{$image->id}", ['review_status' => 'hidden'])
            ->assertOk()->assertJsonPath('images.0.review_status', 'hidden');
        $product->refresh()->load('images');
        $this->assertTrue($product->images->contains('id', $image->id), 'the file and record are kept');
        $this->assertContains('no_photo', $product->reviewReasons());
        $this->assertFalse(Product::whereKey($product->id)->readyForSale()->exists());
        $this->withToken($this->admin())->patchJson("/api/v1/admin/products/{$product->id}/images/{$image->id}", ['make_primary' => true])->assertStatus(422);
    }

    #[Test]
    public function description_drafts_come_from_the_same_product_in_another_size(): void
    {
        $brand = Brand::factory()->create();
        $category = Category::factory()->create();
        $otherType = Product::factory()->create(['brand_id' => $brand->id, 'name' => 'Draft Test Shampoo 500ml']);
        $otherType->translations()->create(['locale' => 'mk', 'description' => 'Wrong type of product']);
        $big = Product::factory()->create(['brand_id' => $brand->id, 'category_id' => $category->id, 'name' => 'Draft Test Shampoo 1000ml']);
        $big->translations()->create(['locale' => 'en', 'description' => 'Draft Test Shampoo 1000ml cleans gently. 1000ml bottle.']);
        $small = Product::factory()->create(['brand_id' => $brand->id, 'category_id' => $category->id, 'name' => 'Draft Test Shampoo 250ml']);

        $response = $this->withToken($this->admin())->getJson("/api/v1/admin/products/{$small->id}/description-suggestions")->assertOk();
        $response->assertJsonPath('drafts.en.kind', 'same_product_other_size')
            ->assertJsonPath('drafts.en.from.id', $big->id)
            ->assertJsonPath('drafts.en.text', 'Draft Test Shampoo 250ml cleans gently. 250ml bottle.')
            ->assertJsonPath('drafts.mk', null);
        $this->assertSame(0, $small->translations()->count(), 'nothing is saved');
    }

    #[Test]
    public function a_photo_can_be_deleted_only_when_another_remains_and_the_next_becomes_main(): void
    {
        $admin = $this->admin();
        $product = Product::factory()->create();
        $first = $product->images()->first();
        $this->withToken($admin)->deleteJson("/api/v1/admin/products/{$product->id}/images/{$first->id}")->assertStatus(422);

        $second = $product->images()->create(['name' => 'test/second.webp', 'variant' => 'gallery', 'sort_order' => 10]);
        $this->withToken($admin)->patchJson("/api/v1/admin/products/{$product->id}/images/{$first->id}", ['make_primary' => true])->assertOk();
        $this->withToken($admin)->deleteJson("/api/v1/admin/products/{$product->id}/images/{$first->id}")
            ->assertOk()->assertJsonCount(1, 'images')->assertJsonPath('images.0.id', $second->id)->assertJsonPath('images.0.is_primary', true);
        $this->assertDatabaseMissing('images', ['id' => $first->id]);
    }

    #[Test]
    public function a_product_with_past_orders_cannot_be_deleted(): void
    {
        $product = Product::factory()->create();
        $order = \App\Models\Order::factory()->create();
        \App\Models\Item::create(['product_id' => $product->id, 'order_id' => $order->id, 'quantity' => 1, 'price' => 100]);
        $this->withToken($this->admin())->deleteJson("/api/v1/admin/products/{$product->id}")->assertStatus(400);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }
}
