<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\RequestStylist;
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
            'pendingStylistRequests' => RequestStylist::where('status', RequestStylist::STATUS_PENDING)->count(),
            'recentOrders' => Order::with(['user', 'info'])
                ->latest()
                ->limit(5)
                ->get()
                ->map(function ($order) {
                    $infoName = trim(($order->info->first_name ?? '') . ' ' . ($order->info->last_name ?? ''));
                    $userName = $order->user
                        ? trim($order->user->first_name . ' ' . $order->user->last_name)
                        : '';

                    return [
                        'id' => (string) $order->id,
                        'total' => (float) $order->total,
                        'status' => $order->status,
                        'userName' => $infoName !== '' ? $infoName : ($userName !== '' ? $userName : 'Unknown'),
                        'createdAt' => $order->created_at?->toISOString(),
                    ];
                }),
        ];
    }
}
