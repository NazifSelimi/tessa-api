<?php

namespace App\Support\Customers;

use DateTimeImmutable;

/**
 * Turns accounts and orders into one row per customer, without touching the
 * database (CustomerDirectory loads the plain rows), so it is unit-testable.
 *
 * Who is one customer:
 * - every customer / business account (admins are not customers);
 * - a guest order whose email or phone matches an account belongs to that account;
 * - other guest orders are grouped together when they share an email or a phone
 *   ("070 123 456" = "+389 70 123 456").
 *
 * Spending counts every order that is not cancelled (trashed orders are left
 * out by the loader).
 */
final class CustomerAggregator
{
    public const STATUS_CANCELLED = 3;

    /**
     * @param list<array{id:int, first_name:?string, last_name:?string, email:?string, phone:?string, city:?string, role:int, created_at:?string}> $users
     * @param list<array{id:int, user_id:?int, status:int, total:float|string|null, created_at:?string, first_name:?string, last_name:?string, email:?string, phone:?string, city:?string}> $orders
     * @param array<int, float> $credits store credit balance per user id
     * @param list<array{customer_key:string, notes:?string, tags:?array}> $notes
     * @return list<array<string, mixed>>
     */
    public static function build(array $users, array $orders, array $credits, array $notes, DateTimeImmutable $now): array
    {
        $groups = [];
        $byEmail = [];
        $byPhone = [];
        $sharedPhones = [];

        foreach ($users as $user) {
            $key = CustomerKey::forUser((int) $user['id']);
            $groups[$key] = ['user' => $user, 'orders' => [], 'aliases' => [$key => true]];
            if ($email = CustomerKey::email($user['email'] ?? null)) {
                $byEmail[$email] ??= $key;
            }
            if ($phone = CustomerKey::phone($user['phone'] ?? null)) {
                if (isset($byPhone[$phone]) && $byPhone[$phone] !== $key) {
                    $sharedPhones[$phone] = true; // two accounts, one number: don't guess
                }
                $byPhone[$phone] ??= $key;
            }
        }
        foreach (array_keys($sharedPhones) as $phone) {
            unset($byPhone[$phone]);
        }

        // Guest orders: find their account, or union them by email / phone.
        $parent = [];
        $find = function (string $id) use (&$parent, &$find): string {
            if (! isset($parent[$id]) || $parent[$id] === $id) {
                return $parent[$id] = $id;
            }

            return $parent[$id] = $find($parent[$id]);
        };
        $guestOrders = [];

        foreach ($orders as $order) {
            $userKey = $order['user_id'] ? CustomerKey::forUser((int) $order['user_id']) : null;
            $email = CustomerKey::email($order['email'] ?? null);
            $phone = CustomerKey::phone($order['phone'] ?? null);

            if ($userKey && isset($groups[$userKey])) {
                $groups[$userKey]['orders'][] = $order;
                continue;
            }
            // Ordered as a guest (or by an admin / a deleted account) with known contact details.
            $match = ($email ? $byEmail[$email] ?? null : null) ?? ($phone ? $byPhone[$phone] ?? null : null);
            if ($match) {
                $groups[$match]['orders'][] = $order;
                if ($email) {
                    $groups[$match]['aliases'][CustomerKey::forEmail($email)] = true;
                }
                if ($phone) {
                    $groups[$match]['aliases'][CustomerKey::forPhone($phone)] = true;
                }
                continue;
            }

            $ids = array_values(array_filter([
                $email ? CustomerKey::forEmail($email) : null,
                $phone ? CustomerKey::forPhone($phone) : null,
            ]));
            if ($ids === []) {
                $ids = ['order-' . $order['id']]; // no contact details: still counted, on its own
            }
            foreach ($ids as $id) {
                $find($id);
            }
            if (count($ids) === 2) {
                $parent[$find($ids[1])] = $find($ids[0]);
            }
            $guestOrders[] = ['order' => $order, 'id' => $ids[0]];
        }

        $guests = [];
        foreach ($guestOrders as $entry) {
            $root = $find($entry['id']);
            $guests[$root]['orders'][] = $entry['order'];
        }
        foreach (array_keys($parent) as $id) {
            $guests[$find($id)]['aliases'][$id] = true;
        }

        foreach ($guests as $group) {
            $aliases = array_keys($group['aliases'] ?? []);
            // The newest order's email is the guest's key; otherwise their phone.
            $orders = $group['orders'];
            usort($orders, fn ($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));
            $key = null;
            foreach ($orders as $order) {
                if ($email = CustomerKey::email($order['email'] ?? null)) {
                    $key = CustomerKey::forEmail($email);
                    break;
                }
            }
            $key ??= collect($aliases)->first(fn ($alias) => str_starts_with($alias, 'phone-')) ?? $aliases[0];
            $groups[$key] = ['user' => null, 'orders' => $group['orders'], 'aliases' => array_fill_keys($aliases, true)];
        }

        $notesByKey = [];
        foreach ($notes as $note) {
            $notesByKey[$note['customer_key']] = $note;
        }

        $rows = [];
        foreach ($groups as $key => $group) {
            $rows[] = self::row((string) $key, $group, $credits, $notesByKey, $now);
        }

        return $rows;
    }

    private static function row(string $key, array $group, array $credits, array $notesByKey, DateTimeImmutable $now): array
    {
        $user = $group['user'];
        $orders = $group['orders'];
        usort($orders, fn ($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']) ?: ($b['id'] <=> $a['id']));
        $active = array_values(array_filter($orders, fn ($order) => (int) $order['status'] !== self::STATUS_CANCELLED));

        $latest = fn (string $field) => self::firstFilled(array_map(fn ($order) => $order[$field] ?? null, $orders));
        $name = $user ? trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) : '';
        if ($name === '') {
            foreach ($orders as $order) {
                $name = trim(($order['first_name'] ?? '') . ' ' . ($order['last_name'] ?? ''));
                if ($name !== '') {
                    break;
                }
            }
        }

        $spent = round(array_sum(array_map(fn ($order) => (float) $order['total'], $active)), 2);
        $count = count($active);
        $last = $active[0]['created_at'] ?? null;
        $first = $count ? $active[$count - 1]['created_at'] : null;
        $joined = $user['created_at'] ?? null;

        $aliases = array_keys($group['aliases']);
        $note = $notesByKey[$key] ?? null;
        foreach ($aliases as $alias) {
            $note ??= $notesByKey[$alias] ?? null;
        }

        return [
            'key' => $key,
            'aliases' => $aliases,
            'userId' => $user ? (int) $user['id'] : null,
            'type' => $user ? ((int) $user['role'] === 2 ? 'business' : 'customer') : 'guest',
            'registered' => (bool) $user,
            'name' => $name,
            'email' => self::firstFilled([$user['email'] ?? null]) ?? $latest('email'),
            'phone' => self::firstFilled([$user['phone'] ?? null]) ?? $latest('phone'),
            'city' => self::firstFilled([$user['city'] ?? null]) ?? $latest('city'),
            'ordersCount' => $count,
            'cancelledCount' => count($orders) - $count,
            'totalSpent' => $spent,
            'averageOrder' => $count ? round($spent / $count, 2) : 0.0,
            'firstOrderAt' => $first,
            'lastOrderAt' => $last,
            'daysSinceLastOrder' => $last ? self::daysBetween($last, $now) : null,
            'joinedAt' => $joined,
            'firstSeenAt' => ($seen = array_filter([$joined, $first])) ? min($seen) : null,
            'storeCredit' => $user ? round((float) ($credits[(int) $user['id']] ?? 0), 2) : 0.0,
            'tags' => array_values($note['tags'] ?? []),
            'notes' => $note['notes'] ?? null,
            'orderIds' => array_map(fn ($order) => (int) $order['id'], $orders),
        ];
    }

    private static function firstFilled(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private static function daysBetween(string $date, DateTimeImmutable $now): int
    {
        $then = new DateTimeImmutable(substr($date, 0, 10));

        return max(0, (int) $then->diff(new DateTimeImmutable($now->format('Y-m-d')))->format('%r%a'));
    }
}
