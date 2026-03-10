<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Item;
use App\Models\Product;

class ReportService
{
    /**
     * Get sales report data for a given date range.
     */
    public function getSalesReport(string $startDate, string $endDate, string $groupBy = 'day'): array
    {
        // Get overall stats
        $overallStats = Order::whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw('
                SUM(total) as total_sales,
                COUNT(*) as total_orders,
                AVG(total) as average_order_value
            ')
            ->first();

        // Group by date format
        $dateFormat = match ($groupBy) {
            'week' => '%Y-%u',    // Year-Week
            'month' => '%Y-%m',   // Year-Month
            default => '%Y-%m-%d', // Year-Month-Day
        };

        // Get chart data
        $chartData = Order::whereBetween('created_at', [$startDate, $endDate])
            ->selectRaw("
                DATE_FORMAT(created_at, '{$dateFormat}') as date,
                SUM(total) as sales,
                COUNT(*) as orders
            ")
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($item) {
                return [
                    'date' => $item->date,
                    'sales' => (float) $item->sales,
                    'orders' => $item->orders,
                ];
            });

        return [
            'totalSales' => (float) ($overallStats->total_sales ?? 0),
            'totalOrders' => $overallStats->total_orders ?? 0,
            'averageOrderValue' => (float) ($overallStats->average_order_value ?? 0),
            'chartData' => $chartData,
        ];
    }

    /**
     * Get product performance analytics.
     */
    public function getProductReport(): array
    {
        return [
            'topSelling' => $this->getTopSellingProducts(),
            'lowStock' => $this->getLowStockProducts(),
            'outOfStock' => $this->getOutOfStockProducts(),
        ];
    }

    protected function getTopSellingProducts()
    {
        return Item::query()
            ->join('products', 'items.product_id', '=', 'products.id')
            ->select('products.id', 'products.name', 'products.price', 'products.image')
            ->selectRaw('SUM(items.quantity) as total_sold, SUM(items.price * items.quantity) as revenue')
            ->groupBy('products.id', 'products.name', 'products.price', 'products.image')
            ->orderByDesc('total_sold')
            ->limit(10)
            ->get()
            ->map(function ($product) {
                return [
                    'id' => (string) $product->id,
                    'name' => $product->name,
                    'price' => (float) $product->price,
                    'image' => $product->image,
                    'totalSold' => $product->total_sold,
                    'revenue' => number_format($product->revenue, 2),
                ];
            });
    }

    protected function getLowStockProducts()
    {
        return Product::where('quantity', '>', 0)
            ->where('quantity', '<', 10)
            ->select('id', 'name', 'quantity', 'price', 'image')
            ->orderBy('quantity')
            ->limit(10)
            ->get()
            ->map(function ($product) {
                return [
                    'id' => (string) $product->id,
                    'name' => $product->name,
                    'quantity' => $product->quantity,
                    'price' => (float) $product->price,
                    'image' => $product->image,
                ];
            });
    }

    protected function getOutOfStockProducts()
    {
        return Product::where('quantity', 0)
            ->select('id', 'name', 'price', 'image')
            ->limit(10)
            ->get()
            ->map(function ($product) {
                return [
                    'id' => (string) $product->id,
                    'name' => $product->name,
                    'price' => (float) $product->price,
                    'image' => $product->image,
                ];
            });
    }
}
