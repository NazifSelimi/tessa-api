<?php

namespace App\Services\Messaging;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Builds the "send to a list" list for /admin/messages: one row per phone
 * number, from customer accounts, business accounts and guest buyers (who
 * only left a phone with their order). The admin then goes down the list and
 * sends each message themself from Viber or WhatsApp; nothing is sent here.
 */
class MessagingAudience
{
    public const MAX = 2000;

    public const SEGMENTS = ['all', 'customers', 'business', 'recent_buyers', 'inactive_buyers', 'numbers'];

    /**
     * @return list<array{phone: string, name: string, type: string, lastOrderAt: string|null, orders: int, locale: string|null, userId: int|null}>
     */
    public function build(string $segment, int $days = 90, string $numbers = ''): array
    {
        $people = $this->everyone();

        if ($segment === 'numbers') {
            return $this->pasted($numbers, $people);
        }

        $since = now()->subDays($days)->toDateTimeString();
        $rows = array_filter($people, fn (array $p) => match ($segment) {
            'customers' => $p['type'] !== 'business',
            'business' => $p['type'] === 'business',
            'recent_buyers' => $p['lastOrderAt'] !== null && $p['lastOrderAt'] >= $since,
            'inactive_buyers' => $p['lastOrderAt'] !== null && $p['lastOrderAt'] < $since,
            default => true,
        });
        usort($rows, fn ($a, $b) => strcmp((string) $b['lastOrderAt'], (string) $a['lastOrderAt']));

        return array_slice(array_values($rows), 0, self::MAX);
    }

    /** @return array<string, array<string, mixed>> keyed by international phone digits */
    private function everyone(): array
    {
        $people = [];

        // Phones left with orders (guests and signed-in buyers). Cancelled and trashed orders don't count as buying.
        $infos = DB::table('order_infos')
            ->join('orders', 'orders.id', '=', 'order_infos.order_id')
            ->whereNull('orders.deleted_at')
            ->where('orders.status', '!=', Order::STATUS_CANCELLED)
            ->whereNotNull('order_infos.phone')->where('order_infos.phone', '!=', '')
            ->orderBy('orders.created_at')
            ->select('order_infos.phone', 'order_infos.first_name', 'order_infos.last_name', 'orders.user_id', 'orders.created_at')
            ->cursor();

        $phoneOfUser = [];
        foreach ($infos as $info) {
            $phone = PhoneNumber::normalize($info->phone);
            if (! $phone) {
                continue;
            }
            $row = $people[$phone] ?? ['phone' => $phone, 'name' => '', 'type' => 'guest', 'lastOrderAt' => null, 'orders' => 0, 'locale' => null, 'userId' => null];
            $row['name'] = trim($info->first_name.' '.$info->last_name) ?: $row['name']; // newest name wins
            $row['lastOrderAt'] = (string) $info->created_at;
            $row['orders']++;
            if ($info->user_id) {
                $phoneOfUser[$info->user_id] = $phone;
            }
            $people[$phone] = $row;
        }

        // Accounts: their own phone, or else the phone of their last order.
        $users = User::query()
            ->where('role', '!=', User::ROLE_ADMIN)
            ->select('id', 'first_name', 'last_name', 'phone', 'role', 'preferred_locale')
            ->cursor();
        foreach ($users as $user) {
            $phone = PhoneNumber::normalize($user->phone) ?? ($phoneOfUser[$user->id] ?? null);
            if (! $phone) {
                continue;
            }
            $row = $people[$phone] ?? ['phone' => $phone, 'lastOrderAt' => null, 'orders' => 0];
            $people[$phone] = [
                'phone' => $phone,
                'name' => trim($user->first_name.' '.$user->last_name) ?: ($row['name'] ?? ''),
                'type' => (int) $user->role === User::ROLE_STYLIST ? 'business' : 'customer',
                'lastOrderAt' => $row['lastOrderAt'],
                'orders' => $row['orders'],
                'locale' => $user->preferred_locale ?: null,
                'userId' => $user->id,
            ];
        }

        return $people;
    }

    /** Pasted numbers, one per line; "078 123 456, Ana" or "Ana 078 123 456" both work. */
    private function pasted(string $text, array $people): array
    {
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n|;/', $text) ?: [] as $line) {
            if (! preg_match('/\+?[\d][\d\s\-\/().]{6,}\d/', $line, $match)) {
                continue;
            }
            $phone = PhoneNumber::normalize($match[0]);
            if (! $phone || isset($rows[$phone])) {
                continue;
            }
            $typedName = trim((string) preg_replace('/\s+/', ' ', str_replace($match[0], ' ', $line)), " \t,;-");
            $known = $people[$phone] ?? null;
            $rows[$phone] = [
                'phone' => $phone,
                'name' => $typedName !== '' ? $typedName : ($known['name'] ?? ''),
                'type' => $known['type'] ?? 'pasted',
                'lastOrderAt' => $known['lastOrderAt'] ?? null,
                'orders' => $known['orders'] ?? 0,
                'locale' => $known['locale'] ?? null,
                'userId' => $known['userId'] ?? null,
            ];
            if (count($rows) >= self::MAX) {
                break;
            }
        }

        return array_values($rows);
    }
}
