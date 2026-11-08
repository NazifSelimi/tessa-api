<?php

namespace App\Mcp\Tools;

use App\Models\Order;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Full details of one order: customer, delivery address, products, discounts, total and status.')]
class GetOrderTool extends Tool
{
    protected string $name = 'get_order';

    public function handle(Request $request): Response
    {
        $data = $request->validate(['id' => ['required', 'integer']]);
        $order = Order::with(['info', 'user', 'items.product.brand'])->find($data['id']);
        if (! $order) {
            return Response::error("Order #{$data['id']} does not exist.");
        }
        $status = [Order::STATUS_PENDING => 'pending', Order::STATUS_PAID => 'confirmed', Order::STATUS_SHIPPED => 'shipped', Order::STATUS_CANCELLED => 'cancelled'];

        return Response::json([
            'id' => $order->id,
            'status' => $status[$order->status] ?? 'unknown',
            'placed_at' => $order->created_at?->toDateTimeString(),
            'customer' => [
                'name' => trim(($order->info?->first_name ?? '') . ' ' . ($order->info?->last_name ?? '')),
                'email' => $order->info?->email ?: $order->user?->email,
                'phone' => $order->info?->phone,
                'address' => $order->info?->address,
                'city' => $order->info?->city,
                'account' => $order->user_id ? 'registered' : 'guest',
            ],
            'items' => $order->items->map(fn ($item) => [
                'product' => $item->product?->name,
                'brand' => $item->product?->brand?->name,
                'quantity' => $item->quantity,
                'price_mkd' => round((float) $item->price),
            ])->all(),
            'discount_mkd' => round((float) $order->discount),
            'coupon' => $order->coupon_code,
            'shipping_mkd' => round((float) $order->shipping),
            'total_mkd' => round((float) $order->total),
            'note' => $order->message,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['id' => $schema->integer()->description('Order number')->required()];
    }
}
