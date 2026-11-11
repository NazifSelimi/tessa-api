<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Documents\OrderDocumentData;
use App\Services\Documents\PriceListData;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminDocumentsTest extends TestCase
{
    use DatabaseTransactions;

    private function order(): Order
    {
        $product = Product::factory()->create(['name' => 'Шампон за коса 350ml', 'price' => 500, 'stylist_price' => 300, 'quantity' => 5]);
        $order = Order::create(['total' => 1150, 'discount' => 0, 'shipping' => 150, 'status' => 0, 'payment_method' => 'cod']);
        Item::create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 2, 'price' => 500]);
        $order->info()->create(['first_name' => 'Ана', 'last_name' => 'Анева', 'phone' => '070123456', 'address' => 'Улица 1', 'city' => 'Тетово']);

        return $order;
    }

    #[Test]
    public function order_lines_and_totals_are_worked_out_for_the_document()
    {
        $data = OrderDocumentData::for($this->order(), 'mk');

        $this->assertSame(1000.0, $data['subtotal']);
        $this->assertSame(2, $data['pieces']);
        $this->assertSame('Ана Анева', $data['buyer']['name']);
        $this->assertStringContainsString('Тетово', $data['buyer']['address']);
    }

    #[Test]
    public function admin_downloads_order_pdfs_and_others_cannot()
    {
        $order = $this->order();

        $this->getJson("/api/v1/admin/orders/{$order->id}/documents/invoice")->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_USER]));
        $this->getJson("/api/v1/admin/orders/{$order->id}/documents/invoice")->assertForbidden();

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        foreach (['invoice', 'delivery-note'] as $type) {
            $response = $this->get("/api/v1/admin/orders/{$order->id}/documents/{$type}?locale=mk");
            $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }

        $bulk = $this->post('/api/v1/admin/orders/delivery-notes', ['ids' => [$order->id]]);
        $bulk->assertOk();
        $this->assertStringStartsWith('%PDF', $bulk->getContent());
    }

    #[Test]
    public function price_list_uses_the_price_of_the_chosen_audience()
    {
        $this->order();
        Product::factory()->create(['name' => 'Без цена', 'price' => 0, 'stylist_price' => 0]);

        $retail = PriceListData::build(['audience' => 'retail'], 'en');
        $business = PriceListData::build(['audience' => 'business'], 'en');

        $this->assertSame(1, $retail['count']);
        $this->assertSame(500.0, $retail['groups'][0]['categories'][0]['rows'][0]['price']);
        $this->assertSame(300.0, $business['groups'][0]['categories'][0]['rows'][0]['price']);

        Sanctum::actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        $pdf = $this->post('/api/v1/admin/price-list/pdf', ['audience' => 'business', 'locale' => 'sq', 'in_stock_only' => true]);
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->getJson('/api/v1/admin/price-list/options')->assertOk()->assertJsonStructure(['data' => ['brands', 'categories']]);
    }
}
