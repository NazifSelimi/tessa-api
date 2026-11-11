<?php

namespace App\Support\Customers;

/**
 * Search, filters and sorting for the customer rows CustomerAggregator builds.
 * Pure functions over arrays, so they are unit-testable without a database.
 */
final class CustomerQuery
{
    public const SORTS = [
        'name', 'city', 'orders_count', 'total_spent', 'average_order',
        'first_order_at', 'last_order_at', 'days_since_last_order', 'store_credit', 'joined_at',
    ];

    private const SORT_FIELDS = [
        'name' => 'name',
        'city' => 'city',
        'orders_count' => 'ordersCount',
        'total_spent' => 'totalSpent',
        'average_order' => 'averageOrder',
        'first_order_at' => 'firstOrderAt',
        'last_order_at' => 'lastOrderAt',
        'days_since_last_order' => 'daysSinceLastOrder',
        'store_credit' => 'storeCredit',
        'joined_at' => 'joinedAt',
    ];

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $filters q, type, city, tag, min_spent, max_spent, min_orders,
     *   last_order_after, last_order_before (Y-m-d), inactive_days, new_since (Y-m-d), has_orders
     * @return list<array<string, mixed>>
     */
    public static function filter(array $rows, array $filters): array
    {
        $q = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        $qDigits = preg_replace('/\D/', '', $q);
        $city = mb_strtolower(trim((string) ($filters['city'] ?? '')));
        $tag = mb_strtolower(trim((string) ($filters['tag'] ?? '')));
        $type = $filters['type'] ?? null;
        $has = fn (string $name) => isset($filters[$name]) && $filters[$name] !== '' && $filters[$name] !== null;

        return array_values(array_filter($rows, function (array $row) use ($q, $qDigits, $city, $tag, $type, $filters, $has) {
            if ($q !== '') {
                $text = mb_strtolower(implode(' ', [$row['name'], $row['email'], $row['city'], implode(' ', $row['tags'])]));
                $phoneDigits = preg_replace('/\D/', '', (string) $row['phone']);
                $phoneHit = strlen($qDigits) >= 3 && str_contains($phoneDigits, ltrim($qDigits, '0'));
                if (! str_contains($text, $q) && ! $phoneHit) {
                    return false;
                }
            }
            if ($type === 'registered' ? ! $row['registered'] : ($type && $row['type'] !== $type)) {
                return false;
            }
            if ($city !== '' && mb_strtolower((string) $row['city']) !== $city) {
                return false;
            }
            if ($tag !== '' && ! in_array($tag, array_map('mb_strtolower', $row['tags']), true)) {
                return false;
            }
            if ($has('min_spent') && $row['totalSpent'] < (float) $filters['min_spent']) {
                return false;
            }
            if ($has('max_spent') && $row['totalSpent'] > (float) $filters['max_spent']) {
                return false;
            }
            if ($has('min_orders') && $row['ordersCount'] < (int) $filters['min_orders']) {
                return false;
            }
            if ($has('has_orders') && filter_var($filters['has_orders'], FILTER_VALIDATE_BOOLEAN) !== $row['ordersCount'] > 0) {
                return false;
            }
            $lastDay = $row['lastOrderAt'] ? substr($row['lastOrderAt'], 0, 10) : null;
            if ($has('last_order_after') && (! $lastDay || $lastDay < $filters['last_order_after'])) {
                return false;
            }
            if ($has('last_order_before') && (! $lastDay || $lastDay > $filters['last_order_before'])) {
                return false;
            }
            // "Not ordered in N days": bought before, nothing since.
            if ($has('inactive_days') && ($row['daysSinceLastOrder'] === null || $row['daysSinceLastOrder'] < (int) $filters['inactive_days'])) {
                return false;
            }
            if ($has('new_since') && (! $row['firstSeenAt'] || substr($row['firstSeenAt'], 0, 10) < $filters['new_since'])) {
                return false;
            }

            return true;
        }));
    }

    /**
     * Empty values (no orders yet, no city) always go last.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function sort(array $rows, string $sort = 'last_order_at', string $direction = 'desc'): array
    {
        $field = self::SORT_FIELDS[$sort] ?? 'lastOrderAt';
        $desc = strtolower($direction) !== 'asc';

        usort($rows, function (array $a, array $b) use ($field, $desc) {
            $x = $a[$field];
            $y = $b[$field];
            $xEmpty = $x === null || $x === '';
            $yEmpty = $y === null || $y === '';
            if ($xEmpty || $yEmpty) {
                return ($xEmpty <=> $yEmpty) ?: strcmp((string) $a['key'], (string) $b['key']);
            }
            $cmp = is_string($x) ? strcoll(mb_strtolower($x), mb_strtolower($y)) : $x <=> $y;

            return ($desc ? -$cmp : $cmp) ?: strcmp((string) $a['key'], (string) $b['key']);
        });

        return $rows;
    }

    /** @return array{customers:int, withOrders:int, totalSpent:float, averageOrder:float} */
    public static function summary(array $rows): array
    {
        $spent = round(array_sum(array_column($rows, 'totalSpent')), 2);
        $orders = array_sum(array_column($rows, 'ordersCount'));

        return [
            'customers' => count($rows),
            'withOrders' => count(array_filter($rows, fn ($row) => $row['ordersCount'] > 0)),
            'totalSpent' => $spent,
            'averageOrder' => $orders ? round($spent / $orders, 2) : 0.0,
        ];
    }

    /** Every tag in use, alphabetical, for the tag filter. */
    public static function tags(array $rows): array
    {
        $tags = [];
        foreach ($rows as $row) {
            foreach ($row['tags'] as $tag) {
                $tags[mb_strtolower($tag)] ??= $tag;
            }
        }
        uasort($tags, 'strcoll');

        return array_values($tags);
    }

    /** Cities with the most customers first, for the city filter. */
    public static function cities(array $rows, int $limit = 60): array
    {
        $counts = [];
        $labels = [];
        foreach ($rows as $row) {
            $city = trim((string) $row['city']);
            if ($city === '') {
                continue;
            }
            $lower = mb_strtolower($city);
            $counts[$lower] = ($counts[$lower] ?? 0) + 1;
            $labels[$lower] ??= $city;
        }
        arsort($counts);

        return array_map(fn ($lower) => ['city' => $labels[$lower], 'count' => $counts[$lower]], array_slice(array_keys($counts), 0, $limit));
    }

    /** Clean typed tags: trimmed, single spaces, no duplicates (ignoring case). */
    public static function cleanTags(array $tags): array
    {
        $clean = [];
        foreach ($tags as $tag) {
            $tag = trim(preg_replace('/\s+/u', ' ', (string) $tag));
            if ($tag !== '') {
                $clean[mb_strtolower($tag)] ??= mb_substr($tag, 0, 40);
            }
        }

        return array_values($clean);
    }
}
