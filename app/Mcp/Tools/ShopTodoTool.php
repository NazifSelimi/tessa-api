<?php

namespace App\Mcp\Tools;

use App\Services\DashboardService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('What needs attention in the shop today: orders waiting, applications to approve, hidden or unpriced products, low stock, failed emails, plus revenue (all time excluding cancelled, and this month).')]
class ShopTodoTool extends Tool
{
    protected string $name = 'shop_todo';

    public function handle(Request $request, DashboardService $dashboard): Response
    {
        $data = $dashboard->getAdminDashboard();

        return Response::json([
            'revenue_mkd' => round((float) $data['totalRevenue']),
            'revenue_this_month_mkd' => round((float) $data['revenueThisMonth']),
            'orders_total' => $data['totalOrders'],
            'todo' => $data['todo'],
            'latest_orders' => $data['recentOrders'],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
