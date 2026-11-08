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
#[Description('Find products by name or brand: id, retail price, business price, stock and whether customers can see it.')]
class FindProductsTool extends Tool
{
    protected string $name = 'find_products';

    public function handle(Request $request): Response
    {
        $data = $request->validate(['search' => ['required', 'string', 'min:2', 'max:100'], 'limit' => ['nullable', 'integer', 'between:1,50']]);
        $term = '%' . $data['search'] . '%';
        $products = Product::with(['brand', 'category', 'images'])
            ->where(fn ($q) => $q->where('name', 'like', $term)->orWhereHas('brand', fn ($brand) => $brand->where('name', 'like', $term)))
            ->orderBy('name')->limit($data['limit'] ?? 20)->get();

        return Response::json($products->map(fn (Product $product) => [
            'id' => $product->id,
            'name' => $product->name,
            'brand' => $product->brand?->name,
            'category' => $product->category?->name,
            'retail_price_mkd' => round((float) $product->price),
            'business_price_mkd' => round((float) $product->stylist_price),
            'stock' => (int) $product->quantity,
            'visible_to_customers' => ! $product->isInReview() && (float) $product->price > 0,
            'needs' => $product->reviewReasons(),
        ])->all());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Part of the product or brand name')->required(),
            'limit' => $schema->integer()->description('How many (1–50, default 20)'),
        ];
    }
}
