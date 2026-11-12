<?php

namespace App\Mcp\Tools;

use App\Models\Product;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Products for sale that are running low or sold out, lowest stock first. Use it to plan re-orders.')]
class LowStockTool extends Tool
{
    protected string $name = 'low_stock';

    public function handle(Request $request): Response
    {
        $data = $request->validate(['max_stock' => ['nullable', 'integer', 'between:0,1000'], 'limit' => ['nullable', 'integer', 'between:1,100']]);
        $products = Product::with('brand')->readyForSale()
            ->where('quantity', '<=', $data['max_stock'] ?? 3)
            ->orderBy('quantity')->orderBy('name')->limit($data['limit'] ?? 40)->get();

        return Response::json($products->map(fn (Product $product) => [
            'id' => $product->id,
            'name' => $product->name,
            'brand' => $product->brand?->name,
            'stock' => (int) $product->quantity,
            'supplier_code' => $product->manufacturer_code ?? null,
        ])->all());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'max_stock' => $schema->integer()->description('Include products with this many or fewer in stock (default 3)'),
            'limit' => $schema->integer()->description('How many (default 40)'),
        ];
    }
}
