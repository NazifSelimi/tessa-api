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
            // Cancelled orders never brought money in.
            'totalRevenue' => (float) Order::where('status', '!=', Order::STATUS_CANCELLED)->sum('total'),
            'revenueThisMonth' => (float) Order::where('status', '!=', Order::STATUS_CANCELLED)
                ->where('created_at', '>=', now()->startOfMonth())->sum('total'),
            'pendingOrders' => Order::where('status', Order::STATUS_PENDING)->count(),
            'totalProducts' => Product::count(),
            'todo' => $this->adminTodo(),
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

    /**
     * Counts behind the dashboard to-do list; each matches an admin products filter.
     *
     * @return array<string, int>
     */
    private function adminTodo(): array
    {
        $readyForSale = fn ($query) => $query->readyForSale();

        return [
            'inReview' => Product::query()->whereNot($readyForSale)->count(),
            'inReviewInStock' => Product::query()->whereNot($readyForSale)->where('quantity', '>', 0)->count(),
            'missingPrice' => Product::query()
                ->where(fn ($q) => $q->where('price', '<=', 0)->orWhereNull('price')
                    ->orWhere('stylist_price', '<=', 0)->orWhereNull('stylist_price'))
                ->count(),
            'lowStock' => Product::query()->readyForSale()
                ->whereBetween('quantity', [1, ProductService::LOW_STOCK_THRESHOLD])->count(),
            'outOfStock' => Product::query()->readyForSale()->where('quantity', '<=', 0)->count(),
            'pendingOrders' => Order::where('status', Order::STATUS_PENDING)->count(),
            'pendingStylistRequests' => RequestStylist::where('status', RequestStylist::STATUS_PENDING)->count(),
        ];
    }
}
