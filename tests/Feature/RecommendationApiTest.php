<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
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

    #[Test]
    public function it_returns_products_sorted_by_recommendation_score_descending()
    {
        $d = $this->seedHairData();

        // Product A: matches hair type (+5) + 2 concerns (+6) = 11
        $pA = Product::factory()->create([
            'name' => 'Product A', 'brand_id' => $d['brand']->id, 'quantity' => 10,
            'category_id' => $d['shampoo']->id, 'price' => 25,
        ]);
        $pA->hairTypes()->attach($d['hairType']);
        $pA->hairConcerns()->attach([$d['concern1']->id, $d['concern2']->id]);

        // Product B: matches hair type (+5) + 1 concern (+3) = 8
        $pB = Product::factory()->create([
            'name' => 'Product B', 'brand_id' => $d['brand']->id, 'quantity' => 10,
            'category_id' => $d['conditioner']->id, 'price' => 30,
        ]);
        $pB->hairTypes()->attach($d['hairType']);
        $pB->hairConcerns()->attach([$d['concern1']->id]);

        // Product C: matches hair type only
        $pC = Product::factory()->create([
            'name' => 'Product C', 'brand_id' => $d['brand']->id, 'quantity' => 10,
            'category_id' => $d['treatment']->id, 'price' => 15, 'quiz_step' => 'mask',
        ]);
        $pC->hairTypes()->attach($d['hairType']);

        $response = $this->postJson('/api/v1/recommendations', [
            'hair_type_id' => $d['hairType']->id,
            'concerns'     => [$d['concern1']->id, $d['concern2']->id],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data.products');

        // Product C only suits the hair type: it may fill a routine step but is
        // not listed as a match.
        $names = collect($response->json('data.products'))->pluck('name')->all();
        $this->assertEquals(['Product A', 'Product B'], $names);
    }

    #[Test]
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

    #[Test]
    public function it_includes_matching_static_bundles()
    {
        $d = $this->seedHairData();

        $p = Product::factory()->create([
            'name' => 'Curly Shampoo', 'brand_id' => $d['brand']->id, 'quantity' => 10,
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

    /* ------------------------------------------------- */
    /* TESTS – validation failures                       */
    /* ------------------------------------------------- */

    #[Test]
    public function it_validates_required_fields()
    {
        $response = $this->postJson('/api/v1/recommendations', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['hair_type_ids', 'concerns']);
    }

    #[Test]
    public function it_validates_hair_type_exists()
    {
        $concern = HairConcern::factory()->create();

        $response = $this->postJson('/api/v1/recommendations', [
            'hair_type_id' => 9999,
            'concerns'     => [$concern->id],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['hair_type_ids.0']);
    }

    #[Test]
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

    #[Test]
    public function it_filters_products_by_budget_range()
    {
        $d = $this->seedHairData();

        $cheap = Product::factory()->create([
            'name' => 'Cheap Product', 'brand_id' => $d['brand']->id, 'quantity' => 10,
            'category_id' => $d['shampoo']->id, 'price' => 15,
        ]);
        $cheap->hairTypes()->attach($d['hairType']);
        $cheap->hairConcerns()->attach([$d['concern1']->id]);

        $expensive = Product::factory()->create([
            'name' => 'Expensive Product', 'brand_id' => $d['brand']->id, 'quantity' => 10,
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

    /* ------------------------------------------------- */
    /* TESTS – quiz v2: multi-select, gating, routine     */
    /* ------------------------------------------------- */

    private function need(string $slug, bool $gated = false, string $group = 'lengths'): HairConcern
    {
        return HairConcern::query()->create(['name' => ucfirst($slug) . ' ' . uniqid(), 'slug' => $slug . '_' . uniqid(), 'group' => $group, 'gated' => $gated]);
    }

    private function type(string $slug, string $group): HairType
    {
        return HairType::query()->create(['name' => ucfirst($slug) . ' ' . uniqid(), 'slug' => $slug . '_' . uniqid(), 'group' => $group]);
    }

    private function care(string $name, string $step, array $needs = [], array $types = [], array $extra = []): Product
    {
        $product = Product::factory()->create(array_merge([
            'name' => $name, 'price' => 500, 'quantity' => 10, 'quiz_step' => $step,
        ], $extra));
        $product->hairConcerns()->attach(collect($needs)->pluck('id'));
        $product->hairTypes()->attach(collect($types)->pluck('id'));

        return $product;
    }

    private function ask(array $types, array $needs, array $steps = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/recommendations', [
            'hair_type_ids' => collect($types)->pluck('id')->all(),
            'concerns' => collect($needs)->pluck('id')->all(),
            'steps' => $steps,
        ])->assertOk();
    }

    #[Test]
    public function gated_toners_only_show_for_people_who_asked_for_them()
    {
        $straight = $this->type('straight', 'pattern');
        $dry = $this->need('dryness');
        $yellow = $this->need('brassy_yellow', true, 'colour');
        $this->care('Violet Toner Shampoo', 'shampoo', [$yellow, $dry]);
        $this->care('Hydra Shampoo', 'shampoo', [$dry]);

        $names = collect($this->ask([$straight], [$dry])->json('data.products'))->pluck('name');
        $this->assertSame(['Hydra Shampoo'], $names->all());

        $names = collect($this->ask([$straight], [$dry, $yellow])->json('data.products'))->pluck('name');
        $this->assertSame('Violet Toner Shampoo', $names->first());
    }

    #[Test]
    public function hair_type_tags_restrict_per_group_and_untagged_products_suit_everyone()
    {
        $straight = $this->type('straight', 'pattern');
        $curly = $this->type('curly', 'pattern');
        $fine = $this->type('fine', 'thickness');
        $thick = $this->type('thick', 'thickness');
        $frizz = $this->need('frizz');
        $volume = $this->need('volume', false, 'style');

        $this->care('Curl Cream', 'style', [$frizz], [$curly]);
        $this->care('Volume Shampoo', 'shampoo', [$volume], [$fine]);
        $this->care('Smooth Serum', 'leave_in', [$frizz]);

        $names = collect($this->ask([$straight, $thick], [$frizz, $volume])->json('data.products'))->pluck('name')->all();
        $this->assertSame(['Smooth Serum'], $names);

        $names = collect($this->ask([$curly, $fine], [$frizz, $volume])->json('data.products'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Curl Cream', 'Volume Shampoo', 'Smooth Serum'], $names);
    }

    #[Test]
    public function it_builds_a_routine_with_one_product_per_step_and_collapses_sizes()
    {
        $wavy = $this->type('wavy', 'pattern');
        $dry = $this->need('dryness');
        $frizz = $this->need('frizz');
        $brand = Brand::factory()->create();

        $this->care('Argan Shampoo 1000ml', 'shampoo', [$dry], [], ['brand_id' => $brand->id]);
        $this->care('Argan Shampoo 350ml', 'shampoo', [$dry], [], ['brand_id' => $brand->id]);
        $this->care('Argan Mask 500ml', 'mask', [$dry, $frizz]);
        $this->care('Frizz Spray', 'leave_in', [$frizz]);
        $this->care('Hairspray', 'style', []);

        $response = $this->ask([$wavy], [$dry, $frizz], ['shampoo', 'condition', 'leave_in', 'style']);

        $routine = collect($response->json('data.routine'));
        $this->assertSame(['shampoo', 'condition', 'leave_in'], $routine->pluck('step')->all());
        $this->assertSame(['Argan Shampoo 350ml', 'Argan Mask 500ml', 'Frizz Spray'], $routine->pluck('product.name')->all());
        $this->assertCount(1, collect($response->json('data.products'))->filter(fn ($p) => str_starts_with($p['name'], 'Argan Shampoo')));
        $this->assertEqualsCanonicalizing([$dry->id, $frizz->id], $routine[1]['product']['matchedNeedIds']);
    }

    #[Test]
    public function default_steps_add_a_scalp_treatment_for_scalp_needs()
    {
        $straight = $this->type('straight', 'pattern');
        $loss = $this->need('hair_loss', false, 'scalp');
        $this->care('Energy Lotion', 'scalp', [$loss]);
        $this->care('Energy Shampoo', 'shampoo', [$loss]);

        $response = $this->ask([$straight], [$loss]);
        $this->assertContains('scalp', $response->json('data.steps'));
        $this->assertSame(['Energy Shampoo', 'Energy Lotion'], collect($response->json('data.routine'))->pluck('product.name')->all());
    }

    #[Test]
    public function non_care_products_are_never_recommended()
    {
        $straight = $this->type('straight', 'pattern');
        $dry = $this->need('dryness');
        $hydrogen = Category::factory()->create(['name' => 'Hydrogen Peroxide']);
        $p = Product::factory()->create(['name' => 'Oxidant 6%', 'category_id' => $hydrogen->id, 'quantity' => 5, 'price' => 100]);
        $p->hairConcerns()->attach($dry->id);

        $this->assertSame([], $this->ask([$straight], [$dry])->json('data.products'));
    }

    #[Test]
    public function a_toner_in_the_routine_comes_with_a_regular_product_for_the_other_washes()
    {
        $straight = $this->type('straight', 'pattern');
        $yellow = $this->need('brassy_yellow', true, 'colour');
        $bond = $this->need('bond_repair');
        $this->care('Violet Shampoo', 'shampoo', [$yellow]);
        $this->care('Bond Shampoo', 'shampoo', [$bond]);

        $routine = $this->ask([$straight], [$yellow, $bond], ['shampoo'])->json('data.routine');
        $this->assertSame('Violet Shampoo', $routine[0]['product']['name']);
        $this->assertSame('Bond Shampoo', $routine[0]['companion']['name']);
    }

    #[Test]
    public function colour_masks_of_one_brand_are_one_result_with_their_shades()
    {
        $straight = $this->type('straight', 'pattern');
        $refresh = HairConcern::query()->firstOrCreate(['slug' => 'color_refresh'], ['name' => 'Refresh colour', 'group' => 'colour', 'gated' => true]);
        $brand = Brand::factory()->create();
        foreach (['Color Mask Red 30ml', 'Color Mask Blue 30ml', 'Color Mask Pink 200ml'] as $name) {
            $this->care($name, 'mask', [$refresh], [], ['brand_id' => $brand->id]);
        }

        $products = $this->ask([$straight], [$refresh])->json('data.products');
        $this->assertCount(1, $products);
        $this->assertSame('Color Mask Pink 200ml', $products[0]['name']);
        $this->assertCount(3, $products[0]['shadeOptions']);
    }
}
