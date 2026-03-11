<?php

namespace App\Services;

use App\Models\Order;

class AdminOrderService
{
    /**
     * Map string status names to the Order model integer constants.
     */
    private const STATUS_MAP = [
        'pending'   => Order::STATUS_PENDING,
        'confirmed' => Order::STATUS_PAID,
        'shipped'   => Order::STATUS_SHIPPED,
        'cancelled' => Order::STATUS_CANCELLED,
    ];

    /**
     * List orders with optional filters and pagination.
     */
    public function listFiltered(array $filters, int $perPage = 20)
    {
        $query = Order::with(['user', 'items.product.images', 'coupon']);

        if (!empty($filters['status'])) {
            $statusInt = self::STATUS_MAP[$filters['status']] ?? null;
            if ($statusInt !== null) {
                $query->where('status', $statusInt);
            }
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('id', $search)
                  ->orWhereHas('user', function ($q2) use ($search) {
                      $q2->where('first_name', 'like', "%{$search}%")
                         ->orWhere('last_name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        return $query->latest()->paginate($perPage);
    }

    /**
     * Update the status of an order.
     */
    public function updateStatus($id, string $status): Order
    {
        $order = Order::findOrFail($id);

        $statusInt = self::STATUS_MAP[$status] ?? null;
        if ($statusInt === null) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }

        $order->update(['status' => $statusInt]);

        return $order->load(['user', 'items.product.images', 'coupon']);
    }
}
