<?php

namespace App\Services;

use App\Models\CustomerNote;
use App\Models\Order;
use App\Models\User;
use App\Support\Customers\CustomerAggregator;
use App\Support\Customers\CustomerKey;
use App\Support\Customers\CustomerQuery;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The admin's customer list (simple CRM): accounts plus guest buyers, with
 * what they spent, when they last ordered, store credit, notes and tags.
 *
 * Built in memory from plain rows: fine for a shop with tens of thousands of
 * orders, and the grouping by email / phone can't be done in one SQL query.
 */
class CustomerDirectory
{
    /** @var list<array<string, mixed>>|null */
    private ?array $rows = null;

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $users = User::query()
            ->where('role', '!=', User::ROLE_ADMIN)
            ->get(['id', 'first_name', 'last_name', 'email', 'phone', 'city', 'role', 'created_at'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'city' => $user->city,
                'role' => (int) $user->role,
                'created_at' => $user->created_at?->format('Y-m-d H:i:s'),
            ])->all();

        // Trashed orders are left out; one row per order with its delivery details.
        $orders = DB::table('orders')
            ->leftJoin('order_infos', 'order_infos.order_id', '=', 'orders.id')
            ->whereNull('orders.deleted_at')
            ->orderBy('orders.id')
            ->get([
                'orders.id', 'orders.user_id', 'orders.status', 'orders.total', 'orders.created_at',
                'order_infos.first_name', 'order_infos.last_name', 'order_infos.email', 'order_infos.phone', 'order_infos.city',
            ])
            ->unique('id')
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'user_id' => $row->user_id ? (int) $row->user_id : null,
                'status' => (int) $row->status,
                'total' => (float) $row->total,
                'created_at' => $row->created_at ? (string) $row->created_at : null,
                'first_name' => $row->first_name,
                'last_name' => $row->last_name,
                'email' => $row->email,
                'phone' => $row->phone,
                'city' => $row->city,
            ])
            ->values()->all();

        $credits = DB::table('store_credit_transactions')
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(amount) as balance')
            ->pluck('balance', 'user_id')
            ->map(fn ($balance) => (float) $balance)
            ->all();

        $notes = CustomerNote::query()->get(['customer_key', 'notes', 'tags'])
            ->map(fn (CustomerNote $note) => ['customer_key' => $note->customer_key, 'notes' => $note->notes, 'tags' => $note->tags ?? []])
            ->all();

        return $this->rows = CustomerAggregator::build($users, $orders, $credits, $notes, new DateTimeImmutable(now()->format('Y-m-d H:i:s')));
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int, summary: array, all: list<array<string, mixed>>}
     */
    public function search(array $filters, string $sort, string $direction): array
    {
        $all = $this->all();
        $rows = CustomerQuery::sort(CustomerQuery::filter($all, $filters), $sort, $direction);

        return ['rows' => $rows, 'total' => count($rows), 'summary' => CustomerQuery::summary($rows), 'all' => $all];
    }

    /** The customer this key (or any of their other emails / phones) belongs to. */
    public function find(string $key): ?array
    {
        $key = $this->normaliseKey($key);
        foreach ($this->all() as $row) {
            if ($row['key'] === $key || in_array($key, $row['aliases'], true)) {
                return $row;
            }
        }

        return null;
    }

    /** Profile for the drawer: the row plus every order (cancelled ones too) with its products. */
    public function profile(array $row): array
    {
        $orders = Order::query()
            ->whereIn('id', $row['orderIds'])
            ->with(['items.product:id,name', 'info'])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get()
            ->map(fn (Order $order) => [
                'id' => (string) $order->id,
                'createdAt' => $order->created_at?->format('Y-m-d H:i:s'),
                'status' => self::statusName((int) $order->status),
                'total' => (float) $order->total,
                'paymentMethod' => $order->payment_method,
                'city' => $order->info?->city,
                'address' => $order->info?->address,
                'itemsCount' => (int) $order->items->sum('quantity'),
                'items' => $order->items->map(fn ($item) => [
                    'productId' => $item->product_id,
                    'name' => $item->product?->name ?? ('Product #' . $item->product_id),
                    'quantity' => (int) $item->quantity,
                    'price' => (float) $item->price,
                ])->values()->all(),
            ])->values()->all();

        // What they buy most: helps decide what to offer them next.
        $favourites = collect($orders)
            ->reject(fn ($order) => $order['status'] === 'cancelled')
            ->flatMap(fn ($order) => $order['items'])
            ->groupBy('productId')
            ->map(fn ($items) => ['productId' => $items[0]['productId'], 'name' => $items[0]['name'], 'quantity' => $items->sum('quantity')])
            ->sortByDesc('quantity')->take(5)->values()->all();

        return self::publicRow($row) + ['orders' => $orders, 'favouriteProducts' => $favourites];
    }

    public function saveNote(array $row, ?string $notes, array $tags, ?User $admin): array
    {
        // Reuse a note saved under one of their other keys (e.g. before they made an account).
        $note = CustomerNote::query()->whereIn('customer_key', array_values(array_unique(array_merge([$row['key']], $row['aliases']))))
            ->get()
            ->sortBy(fn (CustomerNote $note) => $note->customer_key === $row['key'] ? 0 : 1)
            ->first() ?? new CustomerNote();

        $notes = trim((string) $notes);
        $note->fill([
            'customer_key' => $row['key'],
            'user_id' => $row['userId'],
            'notes' => $notes === '' ? null : $notes,
            'tags' => CustomerQuery::cleanTags($tags),
            'updated_by' => $admin?->id,
        ])->save();

        $this->rows = null;

        return $this->find($row['key']) ?? $row;
    }

    /** Row without internal helpers, for the API. */
    public static function publicRow(array $row): array
    {
        unset($row['orderIds'], $row['firstSeenAt']);

        return $row;
    }

    public static function statusName(int $status): string
    {
        return match ($status) {
            Order::STATUS_PAID => 'confirmed',
            Order::STATUS_SHIPPED => 'shipped',
            Order::STATUS_CANCELLED => 'cancelled',
            default => 'pending',
        };
    }

    private function normaliseKey(string $key): string
    {
        if (str_starts_with($key, 'email-')) {
            return 'email-' . (CustomerKey::email(substr($key, 6)) ?? substr($key, 6));
        }
        if (str_starts_with($key, 'phone-')) {
            return 'phone-' . (CustomerKey::phone(substr($key, 6)) ?? substr($key, 6));
        }

        return $key;
    }
}
