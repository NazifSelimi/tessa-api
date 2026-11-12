<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\ReferralService;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\DB;

/**
 * Orders go to a Trash first (hidden from lists, revenue and referral credit)
 * and can be restored; only trashed orders can be deleted forever.
 */
class AdminOrderTrashController extends Controller
{
    public function __construct(
        private readonly ReferralService $referrals,
        private readonly OrderService $orders,
    ) {}

    public function trash(int $id)
    {
        $order = DB::transaction(function () use ($id) {
            $order = Order::query()->lockForUpdate()->findOrFail($id);
            $order->delete();
            // A trashed order no longer holds stock (a cancelled one already gave it back).
            if ($order->status !== Order::STATUS_CANCELLED) {
                $this->orders->moveStock($order, +1);
            }
            $this->referrals->syncOrder($order);

            return $order;
        });

        return ApiResponse::ok(['id' => (string) $order->id], 200, null, "Order #{$order->id} moved to the Trash");
    }

    public function restore(int $id)
    {
        $order = DB::transaction(function () use ($id) {
            $order = Order::onlyTrashed()->lockForUpdate()->findOrFail($id);
            $order->restore();
            if ($order->status !== Order::STATUS_CANCELLED) {
                $this->orders->moveStock($order, -1);
            }
            $this->referrals->syncOrder($order);

            return $order;
        });

        return ApiResponse::ok(['id' => (string) $order->id], 200, null, "Order #{$order->id} restored");
    }

    public function forceDelete(int $id)
    {
        $order = Order::onlyTrashed()->findOrFail($id);
        $orderId = $order->id;
        // Items and delivery details are removed with it; store-credit history keeps its rows (order link cleared).
        $order->forceDelete();

        return ApiResponse::ok(null, 200, null, "Order #{$orderId} deleted forever");
    }
}
