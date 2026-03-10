<?php

namespace App\Services;

use App\Models\DistributorCode;
use App\Models\Order;
use App\Models\User;

class StylistDashboardService
{
    /**
     * Get dashboard statistics for a stylist.
     */
    public function getDashboardData(User $user): array
    {
        $totalCodes = DistributorCode::where('created_by', $user->id)->count();
        $usedCodes = DistributorCode::where('created_by', $user->id)->where('used', true)->count();

        $totalOrders = Order::where('user_id', $user->id)->count();
        $totalSpent = Order::where('user_id', $user->id)->sum('total');

        $recentOrders = Order::where('user_id', $user->id)
            ->with(['items.product.images', 'coupon'])
            ->latest()
            ->limit(5)
            ->get();

        return [
            'totalCodes' => $totalCodes,
            'usedCodes' => $usedCodes,
            'totalOrders' => $totalOrders,
            'totalSpent' => number_format($totalSpent, 2),
            'recentOrders' => $recentOrders,
        ];
    }
}
