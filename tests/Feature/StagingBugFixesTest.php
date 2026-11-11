<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\CategoryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fixes from the staging walk-through on 11 Nov 2026.
 */
class StagingBugFixesTest extends TestCase
{
    use DatabaseTransactions;

    private function stylistToken(): string
    {
        return User::factory()->create(['role' => User::ROLE_STYLIST])->createToken('test')->plainTextToken;
    }

    #[Test]
    public function the_restock_colors_list_accepts_true_from_the_website(): void
    {
        $token = $this->stylistToken();

        $this->withToken($token)->getJson('/api/v1/products/quick-order?color_restock=true')->assertOk();
        $this->withToken($token)->getJson('/api/v1/products/quick-order?color_restock=1')->assertOk();
        $this->withToken($token)->getJson('/api/v1/products/quick-order?color_restock=maybe')->assertStatus(422);
    }

    #[Test]
    public function only_admins_see_the_catalogue_review_category(): void
    {
        Category::firstOrCreate(['name' => CategoryService::REVIEW_CATEGORY]);

        $names = fn ($response) => collect($response->json('data'))->pluck('name');

        $this->assertNotContains(CategoryService::REVIEW_CATEGORY, $names($this->getJson('/api/v1/categories')));
        $this->assertNotContains(
            CategoryService::REVIEW_CATEGORY,
            $names($this->withToken($this->stylistToken())->getJson('/api/v1/categories')),
        );

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->assertContains(
            CategoryService::REVIEW_CATEGORY,
            $names($this->withToken($admin->createToken('test')->plainTextToken)->getJson('/api/v1/categories')),
        );
    }

    #[Test]
    public function products_can_be_filtered_by_several_categories(): void
    {
        $brand = Brand::factory()->create();
        [$a, $b, $c] = Category::factory()->count(3)->create();
        $make = fn (Category $category) => Product::factory()->create([
            'brand_id' => $brand->id,
            'category_id' => $category->id,
            'price' => 900,
            'stylist_price' => 600,
            'quantity' => 5,
            'stylist_only' => false,
        ]);
        $inA = $make($a);
        $inB = $make($b);
        $inC = $make($c);

        // Admin view, so factory products without photos (in review) are still listed.
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN])->createToken('test')->plainTextToken;
        $ids = collect($this->withToken($admin)->getJson("/api/v1/products?category_ids={$a->id},{$b->id}&perPage=100")->assertOk()->json('data'))
            ->pluck('id')->map(fn ($id) => (string) $id);

        $this->assertContains((string) $inA->id, $ids);
        $this->assertContains((string) $inB->id, $ids);
        $this->assertNotContains((string) $inC->id, $ids);

        $this->getJson('/api/v1/products?category_ids=1;drop')->assertStatus(422);
    }
}
