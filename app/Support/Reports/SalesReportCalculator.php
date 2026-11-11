<?php

namespace App\Support\Reports;

use Carbon\CarbonImmutable;

/**
 * Turns plain order and product rows into the sales report figures. It never
 * touches the database, so every rule here is unit tested on its own.
 *
 * Rules:
 * - Cancelled orders never count as revenue (they are listed by status only).
 * - Revenue is what the order was worth: products − discounts + shipping. Store
 *   credit the buyer spent is added back, because it is a payment too.
 * - Estimated profit = product sales − discounts − referral credit − cost.
 *   Shipping is left out: it pays the courier. A product without any cost or
 *   retail price is counted at what it sold for, so no profit is claimed.
 * - A customer is "new" when their first non-cancelled order ever falls in the
 *   period, otherwise "returning".
 *
 * Order row: id, createdAt, status, total, discount, shipping, storeCreditUsed,
 *   referralCommission, couponCode, customerKey, customerName, customerEmail, isBusiness.
 * Line row (one per product): productId, name, brand, category, qty, revenue,
 *   unitCost (null when unknown), costSource (real|estimated|unknown), stock.
 */
final class SalesReportCalculator
{
    public const STATUS_LABELS = [0 => 'pending', 1 => 'confirmed', 2 => 'shipped', 3 => 'cancelled'];
    public const CANCELLED = 3;

    /**
     * @param array<int, array> $orders
     * @param array<int, array> $lines
     * @param array<string, string> $firstOrderAt customerKey => date of their first non-cancelled order
     */
    public static function summary(array $orders, array $lines, array $firstOrderAt, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $active = array_filter($orders, fn (array $order) => (int) $order['status'] !== self::CANCELLED);
        $cancelled = array_filter($orders, fn (array $order) => (int) $order['status'] === self::CANCELLED);

        $revenue = array_sum(array_map([self::class, 'orderValue'], $active));
        $orderCount = count($active);
        $discounts = self::sum($active, 'discount');
        $referralCredit = self::sum($active, 'referralCommission');

        $costed = array_map([self::class, 'costLine'], $lines);
        $productSales = array_sum(array_column($costed, 'revenue'));
        $cost = array_sum(array_column($costed, 'cost'));
        $realCostSales = array_sum(array_map(
            fn (array $line) => $line['costSource'] === 'real' ? $line['revenue'] : 0,
            $costed
        ));
        $netSales = $productSales - $discounts;
        $profit = $netSales - $referralCredit - $cost;

        $business = array_filter($active, fn (array $order) => (bool) ($order['isBusiness'] ?? false));
        $businessRevenue = array_sum(array_map([self::class, 'orderValue'], $business));

        ['new' => $new, 'returning' => $returning, 'unknown' => $unknown] = self::customerSplit($active, $firstOrderAt, $start, $end);

        return [
            'revenue' => self::money($revenue),
            'orders' => $orderCount,
            'averageOrderValue' => $orderCount > 0 ? self::money($revenue / $orderCount) : 0.0,
            'itemsSold' => (int) array_sum(array_column($lines, 'qty')),
            'productSales' => self::money($productSales),
            'shipping' => self::money(self::sum($active, 'shipping')),
            'discounts' => self::money($discounts),
            'referralCredit' => self::money($referralCredit),
            'storeCreditUsed' => self::money(self::sum($active, 'storeCreditUsed')),
            'cost' => self::money($cost),
            'profit' => self::money($profit),
            'marginPercentage' => $netSales > 0 ? round($profit / $netSales * 100, 1) : null,
            // Share of product sales whose purchase cost is entered; below 100 the profit is an estimate.
            'costCoveragePercentage' => $productSales > 0 ? round($realCostSales / $productSales * 100, 1) : 100.0,
            'profitEstimated' => $productSales > 0 && $realCostSales < $productSales,
            'customers' => $new + $returning,
            'newCustomers' => $new,
            'returningCustomers' => $returning,
            'unidentifiedOrders' => $unknown,
            'businessOrders' => count($business),
            'businessOrderShare' => $orderCount > 0 ? round(count($business) / $orderCount * 100, 1) : 0.0,
            'businessRevenue' => self::money($businessRevenue),
            'businessRevenueShare' => $revenue > 0 ? round($businessRevenue / $revenue * 100, 1) : 0.0,
            'cancelledOrders' => count($cancelled),
            'cancelledValue' => self::money(array_sum(array_map([self::class, 'orderValue'], $cancelled))),
        ];
    }

    /** Percentage change per figure; null when there is nothing to compare with. */
    public static function compare(array $current, array $previous): array
    {
        $changes = [];
        foreach ($current as $key => $value) {
            if (!is_int($value) && !is_float($value)) {
                continue;
            }
            $before = $previous[$key] ?? null;
            $changes[$key] = is_numeric($before) && (float) $before != 0.0
                ? round(((float) $value - (float) $before) / abs((float) $before) * 100, 1)
                : null;
        }

        return $changes;
    }

    /** Revenue and order count per chart bar, including empty bars. */
    public static function timeline(array $orders, ReportPeriod $period): array
    {
        $bars = array_fill_keys($period->bucketKeys(), ['revenue' => 0.0, 'orders' => 0]);

        foreach ($orders as $order) {
            if ((int) $order['status'] === self::CANCELLED) {
                continue;
            }
            $key = ReportPeriod::bucketKey(CarbonImmutable::parse($order['createdAt']), $period->bucket);
            if (!isset($bars[$key])) {
                continue;
            }
            $bars[$key]['revenue'] += self::orderValue($order);
            $bars[$key]['orders']++;
        }

        $result = [];
        foreach ($bars as $key => $bar) {
            $result[] = ['date' => $key, 'revenue' => self::money($bar['revenue']), 'orders' => $bar['orders']];
        }

        return $result;
    }

    /** Every status, cancelled included, so the admin sees how much was lost. */
    public static function byStatus(array $orders): array
    {
        $rows = [];
        foreach (self::STATUS_LABELS as $code => $label) {
            $rows[$code] = ['status' => $label, 'orders' => 0, 'value' => 0.0];
        }
        foreach ($orders as $order) {
            $code = (int) $order['status'];
            $rows[$code] ??= ['status' => (string) $code, 'orders' => 0, 'value' => 0.0];
            $rows[$code]['orders']++;
            $rows[$code]['value'] += self::orderValue($order);
        }

        return array_values(array_map(fn (array $row) => [...$row, 'value' => self::money($row['value'])], $rows));
    }

    /** Products ranked by sales, with cost and profit per product (before order discounts). */
    public static function products(array $lines, int $limit = 100): array
    {
        $rows = array_map(function (array $line) {
            $line = self::costLine($line);
            $profit = $line['revenue'] - $line['cost'];

            return [
                'productId' => $line['productId'],
                'name' => $line['name'],
                'brand' => $line['brand'],
                'category' => $line['category'],
                'qty' => (int) $line['qty'],
                'revenue' => self::money($line['revenue']),
                'cost' => self::money($line['cost']),
                'profit' => self::money($profit),
                'marginPercentage' => $line['revenue'] > 0 ? round($profit / $line['revenue'] * 100, 1) : null,
                'costSource' => $line['costSource'],
                'stock' => $line['stock'] ?? null,
            ];
        }, $lines);

        usort($rows, fn (array $a, array $b) => [$b['revenue'], $b['qty']] <=> [$a['revenue'], $a['qty']]);

        return array_slice($rows, 0, $limit);
    }

    /** Sales grouped by brand or category name ('brand' | 'category'). */
    public static function groupLines(array $lines, string $field): array
    {
        $groups = [];
        foreach ($lines as $line) {
            $line = self::costLine($line);
            $name = trim((string) ($line[$field] ?? '')) ?: 'Unassigned';
            $groups[$name] ??= ['name' => $name, 'qty' => 0, 'revenue' => 0.0, 'profit' => 0.0];
            $groups[$name]['qty'] += (int) $line['qty'];
            $groups[$name]['revenue'] += $line['revenue'];
            $groups[$name]['profit'] += $line['revenue'] - $line['cost'];
        }

        $rows = array_map(fn (array $group) => [
            ...$group,
            'revenue' => self::money($group['revenue']),
            'profit' => self::money($group['profit']),
        ], array_values($groups));
        usort($rows, fn (array $a, array $b) => $b['revenue'] <=> $a['revenue']);

        return $rows;
    }

    /** Customers ranked by what they spent in the period. */
    public static function customers(array $orders, array $firstOrderAt, CarbonImmutable $start, CarbonImmutable $end, int $limit = 50): array
    {
        $customers = [];
        foreach ($orders as $order) {
            $key = $order['customerKey'] ?? null;
            if (!$key || (int) $order['status'] === self::CANCELLED) {
                continue;
            }
            $customers[$key] ??= [
                'key' => $key,
                'name' => $order['customerName'] ?? null,
                'email' => $order['customerEmail'] ?? null,
                'isBusiness' => false,
                'orders' => 0,
                'revenue' => 0.0,
                'lastOrderAt' => null,
                'isNew' => self::isNew($key, $firstOrderAt, $start, $end),
            ];
            $row = &$customers[$key];
            $row['name'] = $row['name'] ?: ($order['customerName'] ?? null);
            $row['email'] = $row['email'] ?: ($order['customerEmail'] ?? null);
            $row['isBusiness'] = $row['isBusiness'] || (bool) ($order['isBusiness'] ?? false);
            $row['orders']++;
            $row['revenue'] += self::orderValue($order);
            $at = CarbonImmutable::parse($order['createdAt'])->toDateString();
            $row['lastOrderAt'] = max($row['lastOrderAt'] ?? $at, $at);
            unset($row);
        }

        $rows = array_map(fn (array $row) => [
            ...$row,
            'revenue' => self::money($row['revenue']),
            'averageOrderValue' => self::money($row['revenue'] / max(1, $row['orders'])),
        ], array_values($customers));
        usort($rows, fn (array $a, array $b) => [$b['revenue'], $b['orders']] <=> [$a['revenue'], $a['orders']]);

        return array_slice($rows, 0, $limit);
    }

    /**
     * How each coupon and stylist referral code performed.
     *
     * @param array<string, array{isReferral: bool, stylistName: ?string}> $codeInfo keyed by upper-case code
     */
    public static function codes(array $orders, array $codeInfo = []): array
    {
        $codes = [];
        foreach ($orders as $order) {
            $code = strtoupper(trim((string) ($order['couponCode'] ?? '')));
            if ($code === '' || (int) $order['status'] === self::CANCELLED) {
                continue;
            }
            $codes[$code] ??= [
                'code' => $code,
                'isReferral' => (bool) ($codeInfo[$code]['isReferral'] ?? ((float) ($order['referralCommission'] ?? 0) > 0)),
                'stylistName' => $codeInfo[$code]['stylistName'] ?? null,
                'orders' => 0,
                'revenue' => 0.0,
                'discount' => 0.0,
                'referralCredit' => 0.0,
            ];
            $codes[$code]['orders']++;
            $codes[$code]['revenue'] += self::orderValue($order);
            $codes[$code]['discount'] += (float) ($order['discount'] ?? 0);
            $codes[$code]['referralCredit'] += (float) ($order['referralCommission'] ?? 0);
        }

        $rows = array_map(fn (array $row) => [
            ...$row,
            'revenue' => self::money($row['revenue']),
            'discount' => self::money($row['discount']),
            'referralCredit' => self::money($row['referralCredit']),
            // What the code cost the shop per denar it brought in.
            'costPercentage' => $row['revenue'] > 0 ? round(($row['discount'] + $row['referralCredit']) / $row['revenue'] * 100, 1) : null,
        ], array_values($codes));
        usort($rows, fn (array $a, array $b) => $b['revenue'] <=> $a['revenue']);

        return $rows;
    }

    public static function orderValue(array $order): float
    {
        return (float) ($order['total'] ?? 0) + (float) ($order['storeCreditUsed'] ?? 0);
    }

    /** Adds the line's total cost; an unknown cost is counted at the sale price. */
    private static function costLine(array $line): array
    {
        $revenue = (float) ($line['revenue'] ?? 0);
        $qty = (int) ($line['qty'] ?? 0);
        $unitCost = $line['unitCost'] ?? null;
        $line['revenue'] = $revenue;
        $line['cost'] = $unitCost === null ? $revenue : (float) $unitCost * $qty;
        $line['costSource'] = $unitCost === null ? 'unknown' : ($line['costSource'] ?? 'estimated');

        return $line;
    }

    private static function customerSplit(array $orders, array $firstOrderAt, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $seen = [];
        $unknown = 0;
        foreach ($orders as $order) {
            $key = $order['customerKey'] ?? null;
            if (!$key) {
                $unknown++;
                continue;
            }
            $seen[$key] = self::isNew($key, $firstOrderAt, $start, $end);
        }

        $new = count(array_filter($seen));

        return ['new' => $new, 'returning' => count($seen) - $new, 'unknown' => $unknown];
    }

    private static function isNew(string $key, array $firstOrderAt, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        if (!isset($firstOrderAt[$key])) {
            return true;
        }
        $first = CarbonImmutable::parse($firstOrderAt[$key]);

        return $first >= $start && $first <= $end;
    }

    private static function sum(array $rows, string $field): float
    {
        return array_sum(array_map(fn (array $row) => (float) ($row[$field] ?? 0), $rows));
    }

    private static function money(float $amount): float
    {
        return round($amount, 2);
    }
}
