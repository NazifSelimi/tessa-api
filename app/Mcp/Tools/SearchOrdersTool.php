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
#[Description('List orders, newest first. Filter by status (pending, confirmed, shipped, cancelled), by customer name / email / phone, or by date range.')]
class SearchOrdersTool extends Tool
{
    protected string $name = 'search_orders';

    private const STATUS = ['pending' => Order::STATUS_PENDING, 'confirmed' => Order::STATUS_PAID, 'shipped' => Order::STATUS_SHIPPED, 'cancelled' => Order::STATUS_CANCELLED];

    public function handle(Request $request): Response
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:pending,confirmed,shipped,cancelled'],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ]);

        $query = Order::query()->with(['info', 'user'])->withCount('items')->latest('id');
        if (! empty($data['status'])) {
            $query->where('status', self::STATUS[$data['status']]);
        }
        if (! empty($data['from'])) {
            $query->whereDate('created_at', '>=', $data['from']);
        }
        if (! empty($data['to'])) {
            $query->whereDate('created_at', '<=', $data['to']);
        }
        if (! empty($data['search'])) {
            $term = '%' . $data['search'] . '%';
            $query->where(fn ($q) => $q->whereHas('info', fn ($info) => $info->where('first_name', 'like', $term)
                ->orWhere('last_name', 'like', $term)->orWhere('email', 'like', $term)->orWhere('phone', 'like', $term))
                ->orWhereHas('user', fn ($user) => $user->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)->orWhere('email', 'like', $term)));
        }

        $status = array_flip(self::STATUS);

        return Response::json($query->limit($data['limit'] ?? 20)->get()->map(fn (Order $order) => [
            'id' => $order->id,
            'status' => $status[$order->status] ?? 'unknown',
            'customer' => trim(($order->info?->first_name ?? $order->user?->first_name ?? '') . ' ' . ($order->info?->last_name ?? $order->user?->last_name ?? '')),
            'phone' => $order->info?->phone,
            'city' => $order->info?->city,
            'items' => $order->items_count,
            'total_mkd' => round((float) $order->total),
            'placed_at' => $order->created_at?->toDateTimeString(),
        ])->all());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(['pending', 'confirmed', 'shipped', 'cancelled'])->description('Only orders with this status'),
            'search' => $schema->string()->description('Part of the customer name, email or phone'),
            'from' => $schema->string()->description('From date, YYYY-MM-DD'),
            'to' => $schema->string()->description('To date, YYYY-MM-DD'),
            'limit' => $schema->integer()->description('How many orders (1–50, default 20)'),
        ];
    }
}
