<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;

class DashboardService
{
    /**
     * Get admin dashboard statistics.
     */
    public function getAdminDashboard(): array
    {
        return [
            'totalOrders' => Order::count(),
            'totalRevenue' => number_format(Order::sum('total'), 2),
            'totalProducts' => Product::count(),
            'totalUsers' => User::count(),
            'recentOrders' => Order::with('user')
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn ($order) => [
                    'id' => (string) $order->id,
                    'total' => (float) $order->total,
                    'status' => $order->status,
                    'userName' => $order->user
                        ? trim($order->user->first_name . ' ' . $order->user->last_name)
                        : 'Unknown',
                    'createdAt' => $order->created_at?->toISOString(),
                ]),
        ];
    }
}
