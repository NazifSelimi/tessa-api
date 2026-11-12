<?php

namespace App\Services;

use App\Events\OrderStatusUpdated;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminOrderService
{
    public function __construct(
        private readonly ReferralService $referrals,
        private readonly OrderService $orders
    ) {}

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
        $query = Order::with(['user', 'info', 'items.product.images', 'items.product.brand', 'items.product.category', 'coupon', 'referralStylist']);

        if (($filters['trashed'] ?? null) === 'only') {
            $query->onlyTrashed();
        }

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
                  })
                  ->orWhereHas('info', function ($q2) use ($search) {
                      $q2->where('first_name', 'like', "%{$search}%")
                         ->orWhere('last_name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%")
                         ->orWhere('phone', 'like', "%{$search}%");
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
        $statusInt = self::STATUS_MAP[$status] ?? null;
        if ($statusInt === null) {
            throw new \InvalidArgumentException("Invalid status: {$status}");
        }

        [$order, $previousStatus] = DB::transaction(function () use ($id, $statusInt) {
            $order = Order::query()->lockForUpdate()->findOrFail($id);
            $previousStatus = $order->status;
            $order->update(['status' => $statusInt]);

            // Stock follows the order: cancelling puts items back, undoing a cancel takes them again
            // (the same as a customer cancelling their own order).
            $wasCancelled = $previousStatus === Order::STATUS_CANCELLED;
            $isCancelled = $statusInt === Order::STATUS_CANCELLED;
            if ($wasCancelled !== $isCancelled) {
                $this->orders->moveStock($order, $isCancelled ? +1 : -1);
            }

            $this->referrals->syncOrder($order);

            return [$order, $previousStatus];
        });

        try {
            OrderStatusUpdated::dispatch($order, $previousStatus);
        } catch (\Throwable $e) {
            Log::error('Failed to dispatch OrderStatusUpdated event', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $order->load(['user', 'info', 'items.product.images', 'items.product.brand', 'items.product.category', 'coupon', 'referralStylist']);
    }
}
