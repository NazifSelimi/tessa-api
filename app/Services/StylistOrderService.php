<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;

class StylistOrderService
{
    /**
     * Get a stylist's own orders with pagination.
     */
    public function listByUser(User $user, int $perPage = 20)
    {
        return Order::where('user_id', $user->id)
            ->with(['items.product.images', 'coupon'])
            ->latest()
            ->paginate($perPage);
    }
}
