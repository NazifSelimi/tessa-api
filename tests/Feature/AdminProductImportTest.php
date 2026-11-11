<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductImport\ProductImporter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminProductImportTest extends TestCase
{
    use DatabaseTransactions;

    private function product(array $attributes = []): Product
    {
        $product = Product::factory()->create(['name' => 'Шампон тест 350ml', 'price' => 500, 'stylist_price' => 300, 'quantity' => 5, ...array_diff_key($attributes, ['manufacturer_code' => 1])]);
        if (isset($attributes['manufacturer_code'])) {
            $product->forceFill(['manufacturer_code' => $attributes['manufacturer_code']])->save();
        }

        return $product;
    }

    #[Test]
    public function rows_are_matched_by_code_then_name_and_empty_cells_change_nothing()
    {
        $byCode = $this->product(['manufacturer_code' => '0012345']);
        $byName = $this->product(['name' => 'Маска тест 500ml']);

        $plan = (new ProductImporter())->plan([
            ['Шифра', 'Назив', 'Цена', 'Залиха'],
            ['0012345', 'друго име', '1.380,00', 12],
            ['', 'маска  тест 500ml', '', 7],
            ['999', 'Нов производ', 250, 3],
            ['', 'Маска тест 500ml', 'abc', 1],
        ], ['manufacturer_code' => 0, 'name' => 1, 'price' => 2, 'quantity' => 3], ['fields' => ['price', 'quantity']]);

        $this->assertSame(['rows' => 4, 'update' => 2, 'create' => 0, 'new' => 1, 'same' => 0, 'problems' => 1], $plan['summary']);
        $this->assertSame($byCode->id, $plan['lines'][0]['productId']);
        $this->assertEquals(['from' => 500, 'to' => 1380.0], $plan['lines'][0]['changes']['price']);
        $this->assertSame($byName->id, $plan['lines'][1]['productId']);
        $this->assertSame(['quantity'], array_keys($plan['lines'][1]['changes']));
    }

    #[Test]
    public function admin_uploads_applies_and_undoes_an_import()
    {
        Storage::fake('local');
        $product = $this->product(['manufacturer_code' => 'TST-77']);
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));

        $brand = $product->brand->name;
        $category = $product->category->name;
        $csv = "Article code;Name;Brand;Category;Retail price;Stock\nTST-77;;;;650;40\nTST-88;Brand new thing;{$brand};{$category};120;2\nTST-99;No brand thing;;;120;2\n";
        $upload = $this->post('/api/v1/admin/product-import', ['file' => UploadedFile::fake()->createWithContent('stock.csv', $csv)]);
        $upload->assertOk()->assertJsonPath('data.summary.update', 1)->assertJsonPath('data.summary.new', 2);
        $token = $upload->json('data.token');

        $applied = $this->postJson("/api/v1/admin/product-import/{$token}/apply", ['create_new' => true]);
        $this->assertSame(200, $applied->status(), $applied->getContent());
        $applied->assertJsonPath('data.updated', 1)->assertJsonPath('data.created', 1);
        $this->assertSame(40, $product->fresh()->quantity);
        $this->assertEquals(650, $product->fresh()->price);
        $created = Product::where('name', 'Brand new thing')->firstOrFail();
        $this->assertTrue($created->isInReview(), 'A product without a photo stays admin-only.');

        $this->postJson("/api/v1/admin/product-import/{$token}/undo")->assertOk()->assertJsonPath('data.restored', 1)->assertJsonPath('data.removed', 1);
        $this->assertSame(5, $product->fresh()->quantity);
        $this->assertEquals(500, $product->fresh()->price);
        $this->assertNull(Product::find($created->id));
    }

    #[Test]
    public function only_admins_can_import_or_export()
    {
        $this->postJson('/api/v1/admin/product-import')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_USER]));
        $this->getJson('/api/v1/admin/product-import/export')->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        $this->get('/api/v1/admin/product-import/export')->assertOk();
    }
}
