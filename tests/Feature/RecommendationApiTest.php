<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Bundle;
use App\Models\Category;
use App\Models\HairConcern;
use App\Models\HairType;
use App\Models\Product;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class RecommendationApiTest extends TestCase
{
    use DatabaseTransactions;

    /* ------------------------------------------------- */
    /* helpers                                           */
    /* ------------------------------------------------- */

    private function seedHairData(): array
    {
        $hairType = HairType::factory()->create(['name' => 'Curly']);
        $concern1 = HairConcern::factory()->create(['name' => 'Dryness']);
        $concern2 = HairConcern::factory()->create(['name' => 'Frizz']);
        $brand    = Brand::factory()->create(['name' => 'TestBrand']);

        $shampoo    = Category::factory()->create(['name' => 'Shampoo']);
        $conditioner = Category::factory()->create(['name' => 'Conditioner']);
        $treatment  = Category::factory()->create(['name' => 'Treatment']);

        return compact('hairType', 'concern1', 'concern2', 'brand', 'shampoo', 'conditioner', 'treatment');
    }

    /* ------------------------------------------------- */
    /* TESTS – scoring logic                             */
    /* ------------------------------------------------- */

    /** @test */
    public function it_returns_products_sorted_by_recommendation_score_descending()
    {
        $d = $this->seedHairData();

        // Product A: matches hair type (+5) + 2 concerns (+6) = 11
        $pA = Product::factory()->create([
            'name' => 'Product A', 'brand_id' => $d['brand']->id,
            'category_id' => $d['shampoo']->id, 'price' => 25,
        ]);
        $pA->hairTypes()->attach($d['hairType']);
        $pA->hairConcerns()->attach([$d['concern1']->id, $d['concern2']->id]);

        // Product B: matches hair type (+5) + 1 concern (+3) = 8
        $pB = Product::factory()->create([
            'name' => 'Product B', 'brand_id' => $d['brand']->id,
            'category_id' => $d['conditioner']->id, 'price' => 30,
        ]);
        $pB->hairTypes()->attach($d['hairType']);
        $pB->hairConcerns()->attach([$d['concern1']->id]);

        // Product C: matches hair type only (+5) = 5
        $pC = Product::factory()->create([
            'name' => 'Product C', 'brand_id' => $d['brand']->id,
            'category_id' => $d['treatment']->id, 'price' => 15,
        ]);
        $pC->hairTypes()->attach($d['hairType']);

        $response = $this->postJson('/api/v1/recommendations', [
            'hair_type_id' => $d['hairType']->id,
            'concerns'     => [$d['concern1']->id, $d['concern2']->id],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(3, 'data.products');

        $names = collect($response->json('data.products'))->pluck('name')->all();
        $this->assertEquals(['Product A', 'Product B', 'Product C'], $names);
    }

    /** @test */
    public function it_returns_empty_products_when_no_matches()
    {
        $hairType = HairType::factory()->create(['name' => 'Straight']);
        $concern  = HairConcern::factory()->create(['name' => 'Breakage']);

        $response = $this->postJson('/api/v1/recommendations', [
            'hair_type_id' => $hairType->id,
            'concerns'     => [$concern->id],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.products', [])
            ->assertJsonPath('data.bundles', []);
    }

    /* ------------------------------------------------- */
    /* TESTS – bundle inclusion                          */
    /* ------------------------------------------------- */

    /** @test */
    public function it_includes_matching_static_bundles()
    {
        $d = $this->seedHairData();

        $p = Product::factory()->create([
            'name' => 'Curly Shampoo', 'brand_id' => $d['brand']->id,
            'category_id' => $d['shampoo']->id, 'price' => 20,
        ]);
        $p->hairTypes()->attach($d['hairType']);
        $p->hairConcerns()->attach([$d['concern1']->id]);

        $bundle = Bundle::factory()->create([
            'name' => 'Curly Care Bundle', 'is_dynamic' => false, 'discount_percentage' => 10,
        ]);
        $bundle->products()->attach($p->id, ['quantity' => 1]);

        $response = $this->postJson('/api/v1/recommendations', [
            'hair_type_id' => $d['hairType']->id,
            'concerns'     => [$d['concern1']->id],
        ]);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data.bundles')
            ->assertJsonPath('data.bundles.0.name', 'Curly Care Bundle');
    }

    /** @test */
    public function it_generates_dynamic_bundle_when_no_static_bundles_exist()
    {
        $d = $this->seedHairData();

        // Create one product per routine category
        foreach (['shampoo' => $d['shampoo'], 'conditioner' => $d['conditioner'], 'treatment' => $d['treatment']] as $cat) {
            $p = Product::factory()->create([
                'brand_id' => $d['brand']->id, 'category_id' => $cat->id, 'price' => 20,
            ]);
            $p->hairTypes()->attach($d['hairType']);
            $p->hairConcerns()->attach([$d['concern1']->id]);
        }

        $response = $this->postJson('/api/v1/recommendations', [
            'hair_type_id' => $d['hairType']->id,
            'concerns'     => [$d['concern1']->id],
        ]);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data.bundles')
            ->assertJsonPath('data.bundles.0.isDynamic', true)
            ->assertJsonPath('data.bundles.0.name', 'Your Personalised Routine');
    }

    /* ------------------------------------------------- */
    /* TESTS – validation failures                       */
    /* ------------------------------------------------- */

    /** @test */
    public function it_validates_required_fields()
    {
        $response = $this->postJson('/api/v1/recommendations', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['hair_type_id', 'concerns']);
    }

    /** @test */
    public function it_validates_hair_type_exists()
    {
        $concern = HairConcern::factory()->create();

        $response = $this->postJson('/api/v1/recommendations', [
            'hair_type_id' => 9999,
            'concerns'     => [$concern->id],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['hair_type_id']);
    }

    /** @test */
    public function it_validates_budget_range_format()
    {
        $hairType = HairType::factory()->create();
        $concern  = HairConcern::factory()->create();

        $response = $this->postJson('/api/v1/recommendations', [
            'hair_type_id' => $hairType->id,
            'concerns'     => [$concern->id],
            'budget_range' => 'invalid',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['budget_range']);
    }

    /** @test */
    public function it_filters_products_by_budget_range()
    {
        $d = $this->seedHairData();

        $cheap = Product::factory()->create([
            'name' => 'Cheap Product', 'brand_id' => $d['brand']->id,
            'category_id' => $d['shampoo']->id, 'price' => 15,
        ]);
        $cheap->hairTypes()->attach($d['hairType']);
        $cheap->hairConcerns()->attach([$d['concern1']->id]);

        $expensive = Product::factory()->create([
            'name' => 'Expensive Product', 'brand_id' => $d['brand']->id,
            'category_id' => $d['conditioner']->id, 'price' => 200,
        ]);
        $expensive->hairTypes()->attach($d['hairType']);
        $expensive->hairConcerns()->attach([$d['concern1']->id]);

        $response = $this->postJson('/api/v1/recommendations', [
            'hair_type_id' => $d['hairType']->id,
            'concerns'     => [$d['concern1']->id],
            'budget_range' => '10-50',
        ]);

        $response->assertStatus(200);
        $names = collect($response->json('data.products'))->pluck('name')->all();
        $this->assertContains('Cheap Product', $names);
        $this->assertNotContains('Expensive Product', $names);
    }
}
