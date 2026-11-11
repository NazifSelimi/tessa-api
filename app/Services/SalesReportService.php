<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\Item;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Reports\ReportPeriod;
use App\Support\Reports\SalesReportCalculator as Calc;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Loads the rows behind /admin/reports and hands them to SalesReportCalculator.
 * Trashed orders are always left out (the Order model's soft-delete scope);
 * cancelled ones only count in "orders by status". Costs come from
 * PricingService, so reports and checkout use the same cost rule.
 */
class SalesReportService
{
    /**
     * One customer = one e-mail address (account or checkout), else the account,
     * else the phone number, so a guest who later registers is still one person.
     */
    private const CUSTOMER_KEY = "COALESCE(LOWER(NULLIF(TRIM(users.email), '')), LOWER(NULLIF(TRIM(order_infos.email), '')), CONCAT('user:', orders.user_id), CONCAT('phone:', NULLIF(TRIM(order_infos.phone), '')))";

    public function __construct(
        private readonly PricingService $pricing
    ) {}

    public function overview(ReportPeriod $period): array
    {
        $orders = $this->orders($period->start, $period->end);
        $previousOrders = $this->orders($period->previousStart, $period->previousEnd);
        $lines = $this->lines($period->start, $period->end);
        $firstOrders = $this->firstOrderDates($period->end);

        $summary = Calc::summary($orders, $lines, $firstOrders, $period->start, $period->end);
        $previous = Calc::summary(
            $previousOrders,
            $this->lines($period->previousStart, $period->previousEnd),
            $firstOrders,
            $period->previousStart,
            $period->previousEnd
        );

        return [
            'period' => $period->toArray(),
            'summary' => $summary,
            'previous' => $previous,
            'changes' => Calc::compare($summary, $previous),
            'timeline' => Calc::timeline($orders, $period),
            'byStatus' => Calc::byStatus($orders),
            'byBrand' => Calc::groupLines($lines, 'brand'),
            'byCategory' => Calc::groupLines($lines, 'category'),
            'topProducts' => Calc::products($lines),
            'topCustomers' => Calc::customers($orders, $firstOrders, $period->start, $period->end),
            'codes' => Calc::codes($orders, $this->codeInfo($orders)),
            'costSettings' => $this->pricing->settings(),
        ];
    }

    /** Just the headline figures and their change, for the dashboard card. */
    public function summary(ReportPeriod $period): array
    {
        $firstOrders = $this->firstOrderDates($period->end);
        $summary = Calc::summary(
            $this->orders($period->start, $period->end),
            $this->lines($period->start, $period->end),
            $firstOrders,
            $period->start,
            $period->end
        );
        $previous = Calc::summary(
            $this->orders($period->previousStart, $period->previousEnd),
            $this->lines($period->previousStart, $period->previousEnd),
            $firstOrders,
            $period->previousStart,
            $period->previousEnd
        );

        return [
            'period' => $period->toArray(),
            'summary' => $summary,
            'previous' => $previous,
            'changes' => Calc::compare($summary, $previous),
        ];
    }

    /**
     * Products in stock that sold nothing (outside cancelled orders) for $days
     * days. Products added within those days are left out: they had no chance yet.
     */
    public function slowMovers(int $days, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $cutoff = $now->subDays($days)->startOfDay();
        $settings = $this->pricing->settings();

        $lastSold = Item::query()
            ->join('orders', 'orders.id', '=', 'items.order_id')
            ->whereNull('orders.deleted_at')
            ->where('orders.status', '!=', Order::STATUS_CANCELLED)
            ->groupBy('items.product_id')
            ->selectRaw('items.product_id, MAX(orders.created_at) as last_sold_at, SUM(items.quantity) as sold_ever');

        $rows = Product::query()
            ->leftJoinSub($lastSold, 'sold', 'sold.product_id', '=', 'products.id')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->where('products.quantity', '>', 0)
            ->where(fn ($query) => $query->whereNull('products.created_at')->orWhere('products.created_at', '<', $cutoff))
            ->where(fn ($query) => $query->whereNull('sold.last_sold_at')->orWhere('sold.last_sold_at', '<', $cutoff))
            ->select([
                'products.id', 'products.name', 'products.quantity', 'products.price', 'products.cost_price',
                'products.created_at',
                'brands.name as brand_name', 'categories.name as category_name', 'categories.cost_percentage',
                'sold.last_sold_at', 'sold.sold_ever',
            ])
            ->toBase()
            ->get();

        $result = $rows->map(function ($row) use ($settings, $now) {
            [$unitCost, $source] = $this->unitCost($row, $settings);
            $lastSold = $row->last_sold_at ? CarbonImmutable::parse($row->last_sold_at) : null;

            return [
                'productId' => (int) $row->id,
                'name' => $row->name,
                'brand' => $row->brand_name,
                'category' => $row->category_name,
                'stock' => (int) $row->quantity,
                'price' => (float) $row->price,
                'lastSoldAt' => $lastSold?->toDateString(),
                'daysSinceSale' => $lastSold ? (int) $lastSold->startOfDay()->diffInDays($now->startOfDay()) : null,
                'soldEver' => (int) ($row->sold_ever ?? 0),
                'stockValueRetail' => round((float) $row->price * (int) $row->quantity, 2),
                'stockValueCost' => $unitCost === null ? null : round($unitCost * (int) $row->quantity, 2),
                'costSource' => $source,
            ];
        })->sortByDesc(fn (array $row) => $row['stockValueCost'] ?? $row['stockValueRetail'])->values()->all();

        return [
            'days' => $days,
            'products' => $result,
            'totalStockValueCost' => round(array_sum(array_map(fn ($row) => $row['stockValueCost'] ?? 0, $result)), 2),
            'totalStockValueRetail' => round(array_sum(array_column($result, 'stockValueRetail')), 2),
        ];
    }

    /** Orders created in the window, every status, as plain rows. */
    private function orders(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return Order::query()
            ->leftJoin('users', 'users.id', '=', 'orders.user_id')
            ->leftJoin('order_infos', 'order_infos.order_id', '=', 'orders.id')
            ->whereBetween('orders.created_at', [$start, $end])
            ->select([
                'orders.id', 'orders.created_at', 'orders.status', 'orders.total', 'orders.discount',
                'orders.shipping', 'orders.store_credit_used', 'orders.referral_commission', 'orders.coupon_code',
                'users.role',
            ])
            ->selectRaw(self::CUSTOMER_KEY . ' as customer_key')
            ->selectRaw("NULLIF(TRIM(CONCAT_WS(' ', users.first_name, users.last_name)), '') as user_name")
            ->selectRaw("NULLIF(TRIM(CONCAT_WS(' ', order_infos.first_name, order_infos.last_name)), '') as info_name")
            ->selectRaw('COALESCE(NULLIF(users.email, \'\'), order_infos.email) as customer_email')
            ->toBase()
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'createdAt' => (string) $row->created_at,
                'status' => (int) $row->status,
                'total' => (float) $row->total,
                'discount' => (float) $row->discount,
                'shipping' => (float) $row->shipping,
                'storeCreditUsed' => (float) $row->store_credit_used,
                'referralCommission' => (float) $row->referral_commission,
                'couponCode' => $row->coupon_code,
                'customerKey' => $row->customer_key,
                'customerName' => $row->user_name ?: $row->info_name,
                'customerEmail' => $row->customer_email,
                'isBusiness' => (int) $row->role === User::ROLE_STYLIST,
            ])
            ->all();
    }

    /** Sold quantity and sales per product in non-cancelled orders of the window. */
    private function lines(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $settings = $this->pricing->settings();

        return Item::query()
            ->join('orders', 'orders.id', '=', 'items.order_id')
            ->leftJoin('products', 'products.id', '=', 'items.product_id')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->whereNull('orders.deleted_at')
            ->where('orders.status', '!=', Order::STATUS_CANCELLED)
            ->whereBetween('orders.created_at', [$start, $end])
            ->groupBy(
                'items.product_id', 'products.id', 'products.name', 'products.price', 'products.cost_price',
                'products.quantity', 'brands.name', 'categories.name', 'categories.cost_percentage'
            )
            ->select([
                'items.product_id', 'products.id', 'products.name', 'products.price', 'products.cost_price',
                'products.quantity', 'brands.name as brand_name', 'categories.name as category_name',
                'categories.cost_percentage',
            ])
            ->selectRaw('SUM(items.quantity) as qty, SUM(items.price * items.quantity) as revenue')
            ->toBase()
            ->get()
            ->map(function ($row) use ($settings) {
                [$unitCost, $source] = $this->unitCost($row, $settings);

                return [
                    'productId' => $row->product_id === null ? null : (int) $row->product_id,
                    'name' => $row->name ?? "Deleted product #{$row->product_id}",
                    'brand' => $row->brand_name,
                    'category' => $row->category_name,
                    'qty' => (int) $row->qty,
                    'revenue' => (float) $row->revenue,
                    'unitCost' => $unitCost,
                    'costSource' => $source,
                    'stock' => $row->id === null ? null : (int) $row->quantity,
                ];
            })
            ->all();
    }

    /** First non-cancelled order of every customer up to $until. */
    private function firstOrderDates(CarbonImmutable $until): array
    {
        return Order::query()
            ->leftJoin('users', 'users.id', '=', 'orders.user_id')
            ->leftJoin('order_infos', 'order_infos.order_id', '=', 'orders.id')
            ->where('orders.status', '!=', Order::STATUS_CANCELLED)
            ->where('orders.created_at', '<=', $until)
            ->selectRaw(self::CUSTOMER_KEY . ' as customer_key, MIN(orders.created_at) as first_order_at')
            ->groupBy(DB::raw(self::CUSTOMER_KEY))
            ->toBase()
            ->get()
            ->filter(fn ($row) => $row->customer_key !== null)
            ->mapWithKeys(fn ($row) => [$row->customer_key => (string) $row->first_order_at])
            ->all();
    }

    /** Which codes are stylist referral codes, and whose. */
    private function codeInfo(array $orders): array
    {
        $codes = array_values(array_unique(array_filter(array_map(
            fn (array $order) => $order['couponCode'] ? strtoupper(trim($order['couponCode'])) : null,
            $orders
        ))));
        if (!$codes) {
            return [];
        }

        return Coupon::query()
            ->with('stylist:id,first_name,last_name')
            ->whereIn(DB::raw('UPPER(code)'), $codes)
            ->get()
            ->mapWithKeys(fn (Coupon $coupon) => [strtoupper($coupon->code) => [
                'isReferral' => $coupon->isReferral(),
                'stylistName' => $coupon->stylist
                    ? trim($coupon->stylist->first_name . ' ' . $coupon->stylist->last_name) ?: null
                    : null,
            ]])
            ->all();
    }

    /**
     * The checkout cost rule (PricingService::unitCost) applied to a joined row.
     *
     * @return array{0: ?float, 1: string} unit cost and real|estimated|unknown
     */
    private function unitCost(object $row, array $settings): array
    {
        if ($row->id === null) {
            return [null, 'unknown'];
        }

        $product = new Product();
        $product->setRawAttributes(['price' => $row->price, 'cost_price' => $row->cost_price]);
        $product->setRelation('category', $row->category_name === null && $row->cost_percentage === null
            ? null
            : (new Category())->setRawAttributes(['cost_percentage' => $row->cost_percentage]));

        $unitCost = $this->pricing->unitCost($product, $settings);
        $source = match (true) {
            $unitCost === null => 'unknown',
            (float) $row->cost_price > 0 => 'real',
            default => 'estimated',
        };

        return [$unitCost, $source];
    }
}
