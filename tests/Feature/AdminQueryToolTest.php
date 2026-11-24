<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\AdminQuery\AdminQueryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Admin query tool: structured filters, allowlist checks, presets and saved queries. */
class AdminQueryToolTest extends TestCase
{
    use DatabaseTransactions;

    private Brand $brand;
    private Category $shampoo;
    private Category $mask;
    /** @var array<string, Product> */
    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->brand = Brand::factory()->create(['name' => 'QueryTest Brand']);
        $this->shampoo = Category::factory()->create(['name' => 'QueryTest Shampoo']);
        $this->mask = Category::factory()->create(['name' => 'QueryTest Mask']);
        $make = fn (string $name, array $attrs, bool $photo = true) => ($photo ? Product::factory() : Product::factory()->withoutPhoto())
            ->create($attrs + ['name' => $name, 'brand_id' => $this->brand->id, 'category_id' => $this->shampoo->id]);

        $this->p = [
            'cheap' => $make('QT Cheap Shampoo', ['price' => 300, 'stylist_price' => 400, 'cost_price' => 350, 'quantity' => 5]),
            'normal' => $make('QT Normal Shampoo', ['price' => 500, 'stylist_price' => 400, 'cost_price' => 200, 'quantity' => 0]),
            'equal' => $make('QT Equal Mask', ['price' => 400, 'stylist_price' => 400, 'cost_price' => 100, 'quantity' => 2, 'category_id' => $this->mask->id]),
            'nostylist' => $make('QT No Stylist 100%_off', ['price' => 600, 'stylist_price' => 0, 'cost_price' => 100, 'quantity' => 10], false),
            'zero' => $make('QT Zero Stylist', ['price' => 700, 'stylist_price' => 0, 'cost_price' => 100, 'quantity' => 1, 'category_id' => $this->mask->id], false),
        ];
    }

    /** Ids the filter matches, limited to this test's brand. */
    private function ids(array $filter, string $entity = 'products'): array
    {
        $scoped = ['logic' => 'and', 'conditions' => [
            ['field' => 'brand_id', 'op' => 'in', 'value' => [$this->brand->id]],
            $filter,
        ]];

        return app(AdminQueryService::class)->build($entity, $scoped)->pluck('products.id')->sort()->values()->all();
    }

    private function expect(array $keys): array
    {
        return collect($keys)->map(fn ($k) => $this->p[$k]->id)->sort()->values()->all();
    }

    private function admin(): string
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN])->createToken('t')->plainTextToken;
    }

    #[Test]
    public function price_below_stylist_price_compares_two_columns(): void
    {
        $filter = ['logic' => 'and', 'conditions' => [['field' => 'price', 'op' => 'lt', 'value' => ['field' => 'stylist_price']]]];
        $this->assertSame($this->expect(['cheap']), $this->ids($filter));

        $filter['conditions'][0]['op'] = 'lte';
        $this->assertSame($this->expect(['cheap', 'equal']), $this->ids($filter));
    }

    #[Test]
    public function field_vs_value_ranges_and_empty(): void
    {
        $one = fn ($field, $op, $value = null) => ['logic' => 'and', 'conditions' => [compact('field', 'op', 'value')]];

        $this->assertSame($this->expect(['normal']), $this->ids($one('quantity', 'lte', 0)));
        $this->assertSame($this->expect(['equal', 'zero']), $this->ids($one('quantity', 'between', [1, 3])));
        $this->assertSame($this->expect(['equal', 'zero']), $this->ids($one('quantity', 'between', [3, 1])), 'reversed range is fixed');
        $this->assertSame($this->expect(['nostylist', 'zero']), $this->ids($one('stylist_price', 'empty')));
        $this->assertSame($this->expect(['cheap', 'normal', 'equal']), $this->ids($one('stylist_price', 'not_empty')));
        $this->assertSame($this->expect(['nostylist', 'zero']), $this->ids($one('has_photo', 'is_false')));
        $this->assertSame($this->expect(['cheap', 'normal', 'equal']), $this->ids($one('has_photo', 'is_true')));
        $this->assertSame($this->expect(['equal', 'zero']), $this->ids($one('category_id', 'in', [$this->mask->id])));
        $this->assertSame($this->expect(['cheap', 'normal', 'nostylist']), $this->ids($one('category_id', 'not_in', [$this->mask->id])));
    }

    #[Test]
    public function text_operators_escape_wildcards(): void
    {
        $one = fn ($op, $value) => ['logic' => 'and', 'conditions' => [['field' => 'name', 'op' => $op, 'value' => $value]]];

        $this->assertSame($this->expect(['cheap', 'normal']), $this->ids($one('contains', 'shampoo')));
        $this->assertSame($this->expect(['equal', 'nostylist', 'zero']), $this->ids($one('not_contains', 'Shampoo')));
        $this->assertSame($this->expect(['nostylist']), $this->ids($one('contains', '100%_')));
        $this->assertSame([], $this->ids($one('contains', 'Zero%')), '% is a literal, not a wildcard');
        $this->assertSame($this->expect(['equal']), $this->ids($one('ends_with', 'Mask')));
        $this->assertSame($this->expect(['cheap', 'normal', 'equal', 'nostylist', 'zero']), $this->ids($one('starts_with', 'QT ')));
    }

    #[Test]
    public function and_or_groups_nest(): void
    {
        // Mask AND (out of stock OR stylist price missing)  → zero
        $filter = ['logic' => 'and', 'conditions' => [
            ['field' => 'category_id', 'op' => 'in', 'value' => [$this->mask->id]],
            ['logic' => 'or', 'conditions' => [
                ['field' => 'quantity', 'op' => 'lte', 'value' => 0],
                ['field' => 'stylist_price', 'op' => 'empty'],
            ]],
        ]];
        $this->assertSame($this->expect(['zero']), $this->ids($filter));

        // price < stylist OR price < cost OR stock = 0  → cheap, normal
        $filter = ['logic' => 'or', 'conditions' => [
            ['field' => 'price', 'op' => 'lt', 'value' => ['field' => 'stylist_price']],
            ['field' => 'price', 'op' => 'lt', 'value' => ['field' => 'cost_price']],
            ['field' => 'quantity', 'op' => 'eq', 'value' => 0],
        ]];
        $this->assertSame($this->expect(['cheap', 'normal']), $this->ids($filter));
    }

    #[Test]
    public function every_preset_runs(): void
    {
        $service = app(AdminQueryService::class);
        foreach ($service->schema()['entities'] as $entity) {
            foreach ($entity['presets'] as $preset) {
                $service->build($entity['key'], $preset['filter'])->count();
            }
        }

        $preset = collect($service->schema()['entities'][0]['presets'])->firstWhere('key', 'price_below_stylist');
        $this->assertSame($this->expect(['cheap']), $this->ids($preset['filter']));
    }

    #[Test]
    public function it_rejects_fields_and_operators_outside_the_allowlist(): void
    {
        $token = $this->admin();
        $run = fn (array $condition) => $this->withToken($token)->postJson('/api/v1/admin/query/run', [
            'entity' => 'products',
            'filter' => ['logic' => 'and', 'conditions' => [$condition]],
        ]);

        $run(['field' => 'password', 'op' => 'eq', 'value' => 'x'])->assertStatus(422);
        $run(['field' => 'price; drop table products', 'op' => 'eq', 'value' => 1])->assertStatus(422);
        $run(['field' => 'price', 'op' => 'like', 'value' => '%'])->assertStatus(422);
        $run(['field' => 'price', 'op' => 'lt', 'value' => ['field' => 'users.password']])->assertStatus(422);
        $run(['field' => 'price', 'op' => 'lt', 'value' => ['field' => 'name']])->assertStatus(422);
        $run(['field' => 'price', 'op' => 'lt', 'value' => 'abc'])->assertStatus(422);
        $run(['field' => 'has_photo', 'op' => 'lt', 'value' => ['field' => 'price']])->assertStatus(422);
        $this->withToken($token)->postJson('/api/v1/admin/query/run', ['entity' => 'users_raw'])->assertStatus(422);
    }

    #[Test]
    public function the_endpoint_is_admin_only_and_returns_rows(): void
    {
        $customer = User::factory()->create(['role' => User::ROLE_USER])->createToken('t')->plainTextToken;
        $body = ['entity' => 'products', 'filter' => ['logic' => 'and', 'conditions' => [
            ['field' => 'brand_id', 'op' => 'in', 'value' => [$this->brand->id]],
            ['field' => 'price', 'op' => 'lt', 'value' => ['field' => 'stylist_price']],
        ]]];

        $this->withToken($customer)->postJson('/api/v1/admin/query/run', $body)->assertForbidden();
        $this->app['auth']->forgetGuards();

        $response = $this->withToken($this->admin())->postJson('/api/v1/admin/query/run', $body)->assertOk();
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('QT Cheap Shampoo', $response->json('data.0.name'));
        $this->assertSame('QueryTest Brand', $response->json('data.0.brand'));
        $this->assertEquals(300, $response->json('data.0.price'));
        $this->assertEquals(400, $response->json('data.0.stylist_price'));
    }

    #[Test]
    public function orders_and_customers_can_be_queried(): void
    {
        $buyer = User::factory()->create(['role' => User::ROLE_USER, 'first_name' => 'Querytestina', 'email' => 'qt-buyer@example.test']);
        $idle = User::factory()->create(['role' => User::ROLE_USER, 'first_name' => 'Querytestidle', 'email' => 'qt-idle@example.test']);
        $big = Order::factory()->create(['user_id' => $buyer->id, 'total' => 9000, 'status' => Order::STATUS_PAID]);
        Order::factory()->create(['user_id' => $buyer->id, 'total' => 100, 'status' => Order::STATUS_CANCELLED]);

        $service = app(AdminQueryService::class);
        $orders = $service->build('orders', ['logic' => 'and', 'conditions' => [
            ['field' => 'customer', 'op' => 'contains', 'value' => 'qt-buyer@'],
            ['field' => 'total', 'op' => 'gte', 'value' => 5000],
            ['field' => 'status', 'op' => 'in', 'value' => [Order::STATUS_PAID]],
        ]])->pluck('orders.id')->all();
        $this->assertSame([$big->id], $orders);

        $customers = $service->build('customers', ['logic' => 'and', 'conditions' => [
            ['field' => 'email', 'op' => 'ends_with', 'value' => '@example.test'],
            ['field' => 'first_name', 'op' => 'starts_with', 'value' => 'Querytest'],
            ['field' => 'orders_count', 'op' => 'eq', 'value' => 0],
        ]])->pluck('users.id')->all();
        $this->assertSame([$idle->id], $customers);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $service->build('orders', ['logic' => 'and', 'conditions' => [['field' => 'status', 'op' => 'in', 'value' => [99]]]]);
    }

    #[Test]
    public function queries_can_be_saved_opened_and_deleted(): void
    {
        $token = $this->admin();
        $filter = ['logic' => 'and', 'conditions' => [['field' => 'price', 'op' => 'lt', 'value' => ['field' => 'stylist_price']]]];

        $id = $this->withToken($token)->postJson('/api/v1/admin/query/saved', ['name' => 'Cheap', 'entity' => 'products', 'filter' => $filter])
            ->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/v1/admin/query/saved', ['name' => 'Bad', 'entity' => 'products', 'filter' => ['logic' => 'and', 'conditions' => [['field' => 'nope', 'op' => 'eq', 'value' => 1]]]])
            ->assertStatus(422);

        $saved = collect($this->withToken($token)->getJson('/api/v1/admin/query/saved')->assertOk()->json('data'))->firstWhere('id', $id);
        $this->assertSame('Cheap', $saved['name']);
        $this->assertSame($filter, $saved['filter']);

        $this->withToken($token)->postJson('/api/v1/admin/query/saved', ['id' => $id, 'name' => 'Cheaper', 'entity' => 'products', 'filter' => $filter])->assertOk();
        $this->assertSame('Cheaper', collect($this->withToken($token)->getJson('/api/v1/admin/query/saved')->json('data'))->firstWhere('id', $id)['name']);

        $this->withToken($token)->deleteJson("/api/v1/admin/query/saved/{$id}")->assertOk();
        $this->assertNull(collect($this->withToken($token)->getJson('/api/v1/admin/query/saved')->json('data'))->firstWhere('id', $id));
    }

    #[Test]
    public function schema_lists_fields_operators_and_presets(): void
    {
        $data = $this->withToken($this->admin())->getJson('/api/v1/admin/query/schema')->assertOk()->json('data');
        $products = collect($data['entities'])->firstWhere('key', 'products');
        $price = collect($products['fields'])->firstWhere('name', 'price');
        $this->assertTrue($price['comparable']);
        $this->assertContains('lt', $price['operators']);
        $this->assertNotNull(collect(collect($products['fields'])->firstWhere('name', 'brand_id')['options'])->firstWhere('label', 'QueryTest Brand'));
        $this->assertSame(['products', 'orders', 'customers'], array_column($data['entities'], 'key'));
    }
}
