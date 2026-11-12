<?php

namespace App\Mcp\Tools;

use App\Models\Product;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Set how many pieces of a product are in stock (for example after a delivery or a stock count). Confirm the product id with find_products first.')]
class SetStockTool extends Tool
{
    protected string $name = 'set_stock';

    public function handle(Request $request): Response
    {
        $data = $request->validate(['product_id' => ['required', 'integer'], 'quantity' => ['required', 'integer', 'between:0,100000']]);
        $product = Product::find($data['product_id']);
        if (! $product) {
            return Response::error("Product #{$data['product_id']} does not exist.");
        }
        $before = (int) $product->quantity;
        $product->update(['quantity' => $data['quantity']]);
        Log::info('Stock set through the AI assistant', ['product_id' => $product->id, 'from' => $before, 'to' => $data['quantity'], 'user_id' => $request->user()?->id]);

        return Response::text("{$product->name}: stock changed from {$before} to {$data['quantity']}.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'product_id' => $schema->integer()->description('Product id from find_products')->required(),
            'quantity' => $schema->integer()->description('New number in stock')->required(),
        ];
    }
}
