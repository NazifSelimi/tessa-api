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
 * Admins choose who sees each product: guests, signed-in customers and
 * stylists, any mix of them, or nobody (hidden).
 */
class ProductAudienceVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    private function product(array $visibleTo): Product
    {
        $product = Product::factory()->create([
            'brand_id' => Brand::factory()->create()->id,
            'category_id' => Category::factory()->create()->id,
            'price' => 500,
            'stylist_price' => 400,
            'quantity' => 10,
        ]);
        $product->setVisibleTo($visibleTo)->save();

        return $product->fresh();
    }

    private function token(int $role): string
    {
        return User::factory()->create(['role' => $role])->createToken('test')->plainTextToken;
    }

    private function show(Product $product, ?string $token)
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return ($token ? $this->withToken($token) : $this)->getJson('/api/v1/products/' . $product->id);
    }

    private function checkout(Product $product, ?string $token)
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return ($token ? $this->withToken($token) : $this)->postJson('/api/v1/checkout', [
            'items' => [['product_id' => $product->id, 'qty' => 1]],
            'shipping_address' => [
                'firstName' => 'Test', 'lastName' => 'Buyer', 'email' => 'audience@example.com',
                'phone' => '070123123', 'address' => 'Street 1', 'city' => 'Skopje', 'zip' => '1000',
            ],
            'payment_method' => 'cod',
        ]);
    }

    #[Test]
    public function each_audience_sees_only_what_the_admin_opened_to_it(): void
    {
        $tokens = [
            'guests' => null,
            'customers' => $this->token(User::ROLE_USER),
            'stylists' => $this->token(User::ROLE_STYLIST),
        ];

        $cases = [
            [['guests', 'customers', 'stylists'], ['guests', 'customers', 'stylists']],
            [['stylists'], ['stylists']],
            [['customers'], ['customers']],
            [['guests', 'customers'], ['guests', 'customers']],
            [[], []],
        ];

        foreach ($cases as [$visibleTo, $expected]) {
            $product = $this->product($visibleTo);

            foreach ($tokens as $audience => $token) {
                $this->show($product, $token)->assertStatus(in_array($audience, $expected, true) ? 200 : 404);
            }

            $this->show($product, $this->token(User::ROLE_ADMIN))->assertOk()->assertJsonPath('data.visibleTo', $visibleTo);
        }
    }

    #[Test]
    public function hidden_products_cannot_be_ordered_through_the_api(): void
    {
        $product = $this->product(['stylists']);

        $this->checkout($product, null)->assertStatus(422)->assertJsonValidationErrors('items');
        $this->checkout($product, $this->token(User::ROLE_USER))->assertStatus(422);
        $this->assertSame(10, $product->fresh()->quantity);

        $this->checkout($product, $this->token(User::ROLE_STYLIST))->assertCreated();
    }

    #[Test]
    public function admin_sets_visibility_and_stylist_only_follows(): void
    {
        $product = $this->product(['guests', 'customers', 'stylists']);
        $admin = $this->token(User::ROLE_ADMIN);

        $this->withToken($admin)->putJson('/api/v1/admin/products/' . $product->id, ['visibleTo' => ['stylists']])->assertOk();
        $product->refresh();
        $this->assertSame(['stylists'], $product->visibleTo());
        $this->assertTrue($product->stylist_only);

        // Form posts send a comma list; 'none' hides the product from everyone.
        $this->withToken($admin)->putJson('/api/v1/admin/products/' . $product->id, ['visible_to' => 'none'])->assertOk();
        $this->assertSame([], $product->refresh()->visibleTo());

        $this->withToken($admin)->putJson('/api/v1/admin/products/' . $product->id, ['visible_to' => 'guests,customers'])->assertOk();
        $product->refresh();
        $this->assertSame(['guests', 'customers'], $product->visibleTo());
        $this->assertFalse($product->stylist_only);

        $this->withToken($admin)->getJson('/api/v1/admin/products?visibility=limited&perPage=500')
            ->assertOk()->assertJsonFragment(['id' => (string) $product->id]);

        // The old switch still works for older clients and importers.
        $product->update(['stylist_only' => true]);
        $this->assertSame(['stylists'], $product->refresh()->visibleTo());
    }
}
