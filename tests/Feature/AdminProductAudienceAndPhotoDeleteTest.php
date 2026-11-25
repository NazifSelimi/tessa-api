<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Admins see who can see each product, and can delete a photo straight from the shop page. */
class AdminProductAudienceAndPhotoDeleteTest extends TestCase
{
    use DatabaseTransactions;

    private function token(string $role): string
    {
        return User::factory()->create(['role' => $role])->createToken('t')->plainTextToken;
    }

    private function product(array $audiences = ['guests', 'customers', 'stylists']): Product
    {
        $product = Product::factory()->create(['quantity' => 5, 'price' => 500, 'stylist_price' => 400]);
        $product->setVisibleTo($audiences)->save();
        $product->images()->create(['name' => 'second.jpg', 'url' => 'https://example.test/second.jpg', 'variant' => 'legacy', 'sort_order' => 5]);

        return $product->fresh();
    }

    #[Test]
    public function shop_photos_carry_an_id_for_admins_only(): void
    {
        $product = $this->product();

        $media = $this->withToken($this->token(User::ROLE_ADMIN))->getJson("/api/v1/products/{$product->id}")->assertOk()->json('data.media');
        $this->assertNotEmpty($media);
        $this->assertIsInt($media[0]['id']);

        $this->app['auth']->forgetGuards();
        $media = $this->withToken($this->token(User::ROLE_CUSTOMER))->getJson("/api/v1/products/{$product->id}")->assertOk()->json('data.media');
        $this->assertArrayNotHasKey('id', $media[0]);
    }

    #[Test]
    public function admin_deletes_one_photo_but_not_the_last(): void
    {
        $product = $this->product();
        $admin = $this->token(User::ROLE_ADMIN);
        $ids = $product->images()->pluck('id');
        $this->assertCount(2, $ids);

        $this->withToken($admin)->deleteJson("/api/v1/admin/products/{$product->id}/images/{$ids[1]}")->assertOk();
        $this->assertSame(1, $product->images()->count());

        $this->withToken($admin)->deleteJson("/api/v1/admin/products/{$product->id}/images/{$ids[0]}")->assertStatus(422);
        $this->assertSame(1, $product->images()->count());
    }

    #[Test]
    public function customers_cannot_delete_photos(): void
    {
        $product = $this->product();
        $id = $product->images()->value('id');

        $this->withToken($this->token(User::ROLE_CUSTOMER))->deleteJson("/api/v1/admin/products/{$product->id}/images/{$id}")->assertForbidden();
        $this->assertSame(2, $product->images()->count());
    }

    #[Test]
    public function admin_product_screens_say_who_can_see_the_product(): void
    {
        $product = $this->product(['stylists']);
        $admin = $this->token(User::ROLE_ADMIN);

        $gallery = $this->withToken($admin)->getJson("/api/v1/admin/products/{$product->id}/images")->assertOk();
        $this->assertSame(['stylists'], $gallery->json('product.visibleTo'));

        $row = collect($this->withToken($admin)->getJson('/api/v1/admin/products/problems')->json('products'))->firstWhere('id', $product->id);
        if ($row) {
            $this->assertSame(['stylists'], $row['visibleTo']);
        }

        $this->assertSame('Stylists only', Product::audienceLabel(['stylists']));
        $this->assertSame('Everyone', Product::audienceLabel(Product::AUDIENCES));
        $this->assertSame('Hidden', Product::audienceLabel([]));
        $this->assertSame(['guests', 'customers'], Product::audiencesOfRow((object) ['visible_to_guests' => 1, 'visible_to_customers' => 1, 'visible_to_stylists' => 0]));
    }
}
