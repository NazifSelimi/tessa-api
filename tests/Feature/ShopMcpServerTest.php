<?php

namespace Tests\Feature;

use App\Mcp\Servers\ShopServer;
use App\Mcp\Tools\DraftEmailTool;
use App\Mcp\Tools\FindProductsTool;
use App\Mcp\Tools\SetStockTool;
use App\Mcp\Tools\ShopTodoTool;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShopMcpServerTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function the_endpoint_needs_an_admin_token()
    {
        Setting::put('features', '{}');
        Setting::put('business.plan', 'premium');
        $this->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();

        $customer = User::factory()->create(['role' => User::ROLE_USER]);
        $this->withToken($customer->createToken('t')->plainTextToken)
            ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertForbidden();
    }

    #[Test]
    public function an_admin_token_from_the_admin_works()
    {
        Setting::put('features', '{}');
        Setting::put('business.plan', 'premium');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $token = $this->withToken($admin->createToken('login')->plainTextToken)
            ->postJson('/api/v1/admin/ai-tokens', ['name' => 'Claude'])->assertCreated()->json('data.token');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertOk()->assertSee('shop_todo')->assertSee('find_products');
    }

    #[Test]
    public function tools_answer_with_shop_data()
    {
        $product = Product::factory()->create(['name' => 'Zzmcp Repair Mask 1000ml', 'quantity' => 2]);

        ShopServer::tool(ShopTodoTool::class)->assertOk()->assertSee('revenue_mkd');
        ShopServer::tool(FindProductsTool::class, ['search' => 'Zzmcp'])->assertOk()->assertSee('Zzmcp Repair Mask 1000ml');
        ShopServer::tool(SetStockTool::class, ['product_id' => $product->id, 'quantity' => 12])->assertOk()->assertSee('from 2 to 12');
        $this->assertSame(12, (int) $product->fresh()->quantity);
        ShopServer::tool(DraftEmailTool::class, ['audience' => 'customers', 'subject' => 'Sale', 'body' => 'Hi'])
            ->assertOk()->assertSee('/admin/emails?tab=compose&draft=');
    }
}
