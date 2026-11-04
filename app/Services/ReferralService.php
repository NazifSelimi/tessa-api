<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Setting;
use App\Models\StoreCreditTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stylist referral codes and store credit.
 *
 * A referral code is a coupon with a stylist_id. A retail client who uses it
 * gets the coupon discount (still limited by the profit floor in
 * PricingService); the stylist earns commission_percentage of the goods paid
 * as store credit, which they can spend on their own TESSA orders.
 *
 * Credit is a ledger (store_credit_transactions). syncOrder() makes the
 * ledger match an order's status, so it is safe to call on every change:
 * commission counts once the order is confirmed or shipped and is reversed
 * when it is cancelled; credit spent on a cancelled order is refunded.
 */
class ReferralService
{
    public const DEFAULT_DISCOUNT_PERCENTAGE = 10.0;
    public const DEFAULT_COMMISSION_PERCENTAGE = 5.0;
    public const DEFAULT_FIRST_ORDER_ONLY = true;
    /** Most a stylist earns from one client order, in MKD; 0 means no cap. */
    public const DEFAULT_MAX_COMMISSION = 150.0;
    /** Shown when a guest tries a stylist's code; the UI offers sign in / register. */
    public const GUEST_ERROR = 'Sign in or create an account to use this stylist code.';

    public function settings(): array
    {
        return [
            'discountPercentage' => (float) Setting::get('referral.discount_percentage', self::DEFAULT_DISCOUNT_PERCENTAGE),
            'commissionPercentage' => (float) Setting::get('referral.commission_percentage', self::DEFAULT_COMMISSION_PERCENTAGE),
            'firstOrderOnly' => (bool) (int) Setting::get('referral.first_order_only', self::DEFAULT_FIRST_ORDER_ONLY ? 1 : 0),
            'maxCommission' => (float) Setting::get('referral.max_commission', self::DEFAULT_MAX_COMMISSION),
        ];
    }

    /**
     * Defaults for codes created from now on. With $applyToExisting every
     * stylist's code takes the new terms too (orders already placed keep the
     * commission they were placed with).
     */
    public function updateSettings(array $values, bool $applyToExisting = false): array
    {
        if (array_key_exists('discountPercentage', $values)) {
            Setting::put('referral.discount_percentage', (float) $values['discountPercentage']);
        }
        if (array_key_exists('commissionPercentage', $values)) {
            Setting::put('referral.commission_percentage', (float) $values['commissionPercentage']);
        }
        if (array_key_exists('firstOrderOnly', $values)) {
            Setting::put('referral.first_order_only', $values['firstOrderOnly'] ? 1 : 0);
        }
        // The cap is one value for every code, so it needs no "apply to existing".
        if (array_key_exists('maxCommission', $values)) {
            Setting::put('referral.max_commission', round((float) $values['maxCommission']));
        }

        if ($applyToExisting) {
            $settings = $this->settings();
            Coupon::query()->whereNotNull('stylist_id')->update([
                'value' => (int) round($settings['discountPercentage']),
                'commission_percentage' => $settings['commissionPercentage'],
                'first_order_only' => $settings['firstOrderOnly'],
            ]);
        }

        return $this->settings();
    }

    /** The stylist's referral code, created on first use. */
    public function codeFor(User $stylist): Coupon
    {
        $existing = Coupon::query()->where('stylist_id', $stylist->id)->first();
        if ($existing) {
            return $existing;
        }

        $settings = $this->settings();
        $name = preg_replace('/[^A-Z]/', '', strtoupper(Str::ascii((string) $stylist->first_name)));
        $base = substr($name ?: 'SALON', 0, 8) . (int) round($settings['discountPercentage']);
        $code = $base;
        while (Coupon::query()->where('code', $code)->exists()) {
            $code = $base . random_int(10, 99);
        }

        return Coupon::create([
            'code' => $code,
            'type' => 'percentage',
            'value' => (int) round($settings['discountPercentage']),
            // Referral codes have unlimited uses; quantity is not checked for them.
            'quantity' => 0,
            'expiration_date' => null,
            'is_active' => true,
            'stylist_id' => $stylist->id,
            'commission_percentage' => $settings['commissionPercentage'],
            'first_order_only' => $settings['firstOrderOnly'],
        ]);
    }

    /** Let a stylist pick an easier code to share, e.g. "ANA-SALON". */
    public function renameCode(User $stylist, string $code): Coupon
    {
        $coupon = $this->codeFor($stylist);
        $code = strtoupper(trim($code));

        if (!preg_match('/^[A-Z0-9-]{4,20}$/', $code)) {
            throw ValidationException::withMessages([
                'code' => ['Use 4 to 20 letters (A–Z), numbers or dashes.'],
            ]);
        }
        if (Coupon::query()->where('code', $code)->whereKeyNot($coupon->id)->exists()) {
            throw ValidationException::withMessages(['code' => ['This code is already taken.']]);
        }

        $coupon->update(['code' => $code]);

        return $coupon;
    }

    /**
     * Throws when this buyer may not use the referral code: guests must sign in
     * or register first. Email and phone are known at checkout only, so the
     * cart preview checks the account alone.
     */
    public function assertUsable(Coupon $coupon, ?User $buyer, ?string $email = null, ?string $phone = null): void
    {
        if (!$coupon->isReferral()) {
            return;
        }

        // Codes are tied to a client account, so first-order checks and the
        // stylist's commission always point at a real customer.
        if (!$buyer) {
            throw ValidationException::withMessages(['coupon_code' => [self::GUEST_ERROR]]);
        }

        if ($buyer->isStylist() || $buyer->isAdmin()) {
            throw ValidationException::withMessages([
                'coupon_code' => ['Referral codes are for clients; salon accounts already get salon prices.'],
            ]);
        }

        if ($coupon->first_order_only && $this->hasOrderedBefore($buyer, $email, $phone)) {
            throw ValidationException::withMessages([
                'coupon_code' => ['This referral code is for your first order only.'],
            ]);
        }
    }

    /**
     * Commission in whole MKD on what the client pays for the goods (after
     * discounts, without shipping), never more than the admin's cap.
     */
    public function commission(Coupon $coupon, float $goodsTotal): float
    {
        $commission = round(max(0, $goodsTotal) * (float) $coupon->commission_percentage / 100);
        $cap = $this->settings()['maxCommission'];

        return $cap > 0 ? min($commission, $cap) : $commission;
    }

    public function balance(User $user): float
    {
        return round((float) StoreCreditTransaction::query()->where('user_id', $user->id)->sum('amount'), 2);
    }

    /**
     * Bring the ledger in line with the order: commission while it is
     * confirmed or shipped, credit spent unless it is cancelled.
     */
    public function syncOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $earning = in_array($order->status, [Order::STATUS_PAID, Order::STATUS_SHIPPED], true);
            if ($order->referral_stylist_id) {
                $this->settle(
                    $order->referral_stylist_id,
                    $order,
                    StoreCreditTransaction::TYPE_REFERRAL,
                    $earning ? (float) $order->referral_commission : 0.0
                );
            }

            if ($order->user_id && (float) $order->store_credit_used > 0) {
                $this->settle(
                    $order->user_id,
                    $order,
                    StoreCreditTransaction::TYPE_SPENT,
                    $order->status === Order::STATUS_CANCELLED ? 0.0 : -(float) $order->store_credit_used
                );
            }
        });
    }

    public function adjust(User $user, float $amount, string $note, User $admin): StoreCreditTransaction
    {
        return StoreCreditTransaction::create([
            'user_id' => $user->id,
            'type' => StoreCreditTransaction::TYPE_ADJUSTMENT,
            'amount' => round($amount, 2),
            'note' => $note,
            'created_by' => $admin->id,
        ]);
    }

    /** What the stylist's referral page shows. */
    public function summary(User $stylist): array
    {
        $coupon = $this->codeFor($stylist);

        $orders = Order::query()
            ->where('referral_stylist_id', $stylist->id)
            ->with('info')
            ->latest()
            ->get();
        $live = $orders->where('status', '!=', Order::STATUS_CANCELLED);

        return [
            'code' => $this->codeData($coupon),
            'stats' => [
                'clients' => $live->map(fn (Order $order) => $this->clientKey($order))->filter()->unique()->count(),
                'orders' => $live->count(),
                'clientSales' => round((float) $live->sum(fn (Order $order) => (float) $order->total - (float) $order->shipping), 2),
                'earned' => round((float) StoreCreditTransaction::query()
                    ->where('user_id', $stylist->id)
                    ->where('type', StoreCreditTransaction::TYPE_REFERRAL)
                    ->sum('amount'), 2),
                'pending' => round((float) $orders->where('status', Order::STATUS_PENDING)->sum('referral_commission'), 2),
                'balance' => $this->balance($stylist),
            ],
            // Clients are shown by first name and last initial only.
            'orders' => $orders->take(20)->map(fn (Order $order) => [
                'id' => (string) $order->id,
                'client' => trim(($order->info?->first_name ?? '') . ' ' . mb_substr((string) $order->info?->last_name, 0, 1) . '.'),
                'status' => $this->statusName($order->status),
                'goodsTotal' => round((float) $order->total - (float) $order->shipping, 2),
                'commission' => (float) $order->referral_commission,
                'createdAt' => $order->created_at?->toISOString(),
            ])->values(),
            'transactions' => StoreCreditTransaction::query()
                ->where('user_id', $stylist->id)
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (StoreCreditTransaction $row) => $this->transactionData($row))
                ->values(),
        ];
    }

    /** Every referral code with its results, for the admin page. */
    public function adminList(): array
    {
        $this->ensureCodesForAllStylists();

        $coupons = Coupon::query()->whereNotNull('stylist_id')->with('stylist')->orderBy('code')->get();
        $orderStats = Order::query()
            ->whereIn('referral_stylist_id', $coupons->pluck('stylist_id'))
            ->where('status', '!=', Order::STATUS_CANCELLED)
            ->groupBy('referral_stylist_id')
            ->selectRaw('referral_stylist_id, COUNT(*) as orders, SUM(total - shipping) as sales')
            ->get()
            ->keyBy('referral_stylist_id');
        $balances = StoreCreditTransaction::query()
            ->whereIn('user_id', $coupons->pluck('stylist_id'))
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(amount) as balance, SUM(CASE WHEN type = ? THEN amount ELSE 0 END) as earned', [StoreCreditTransaction::TYPE_REFERRAL])
            ->get()
            ->keyBy('user_id');

        return $coupons->map(fn (Coupon $coupon) => $this->codeData($coupon) + [
            'stylist' => [
                'id' => (string) $coupon->stylist_id,
                'name' => trim(($coupon->stylist?->first_name ?? '') . ' ' . ($coupon->stylist?->last_name ?? '')),
                'email' => $coupon->stylist?->email,
            ],
            'orders' => (int) ($orderStats[$coupon->stylist_id]->orders ?? 0),
            'clientSales' => round((float) ($orderStats[$coupon->stylist_id]->sales ?? 0), 2),
            'earned' => round((float) ($balances[$coupon->stylist_id]->earned ?? 0), 2),
            'balance' => round((float) ($balances[$coupon->stylist_id]->balance ?? 0), 2),
        ])->values()->all();
    }

    /** Give every stylist a code, so admins can print salon cards before they log in. */
    public function ensureCodesForAllStylists(): int
    {
        $missing = User::query()
            ->where('role', User::ROLE_STYLIST)
            ->whereNotIn('id', Coupon::query()->whereNotNull('stylist_id')->select('stylist_id'))
            ->get();
        $missing->each(fn (User $stylist) => $this->codeFor($stylist));

        return $missing->count();
    }

        public function codeData(Coupon $coupon): array
    {
        return [
            'id' => (string) $coupon->id,
            'code' => $coupon->code,
            'discountPercentage' => (int) $coupon->value,
            'commissionPercentage' => (float) $coupon->commission_percentage,
            'firstOrderOnly' => (bool) $coupon->first_order_only,
            'isActive' => $coupon->is_active !== false,
            'maxCommission' => $this->settings()['maxCommission'],
        ];
    }

    public function transactionData(StoreCreditTransaction $row): array
    {
        return [
            'id' => (string) $row->id,
            'type' => $row->type,
            'amount' => (float) $row->amount,
            'orderId' => $row->order_id ? (string) $row->order_id : null,
            'note' => $row->note,
            'createdAt' => $row->created_at?->toISOString(),
        ];
    }

    private function settle(int $userId, Order $order, string $type, float $target): void
    {
        $current = (float) StoreCreditTransaction::query()
            ->where('user_id', $userId)
            ->where('order_id', $order->id)
            ->where('type', $type)
            ->lockForUpdate()
            ->sum('amount');

        $difference = round($target - $current, 2);
        if (abs($difference) < 0.005) {
            return;
        }

        StoreCreditTransaction::create([
            'user_id' => $userId,
            'order_id' => $order->id,
            'type' => $type,
            'amount' => $difference,
        ]);
    }

    private function hasOrderedBefore(?User $buyer, ?string $email, ?string $phone): bool
    {
        $phoneKey = $this->phoneKey($phone);
        if (!$buyer && !$email && !$phoneKey) {
            return false;
        }

        $orders = Order::query()
            ->where('status', '!=', Order::STATUS_CANCELLED)
            ->where(function ($query) use ($buyer, $email, $phoneKey) {
                if ($buyer) {
                    $query->orWhere('user_id', $buyer->id);
                }
                if ($email) {
                    $query->orWhereHas('info', fn ($info) => $info->where('email', $email));
                }
                if ($phoneKey) {
                    // Narrow in SQL, then compare normalised numbers ("070 123 456" = "+38970123456").
                    $query->orWhereHas('info', fn ($info) => $info->where('phone', 'like', '%' . substr($phoneKey, -3)));
                }
            })
            ->with('info')
            ->get();

        return $orders->contains(fn (Order $order) => ($buyer && $order->user_id === $buyer->id)
            || ($email && strcasecmp((string) $order->info?->email, $email) === 0)
            || ($phoneKey && $this->phoneKey($order->info?->phone) === $phoneKey));
    }

    /** Last 8 digits: the same Macedonian number with or without +389 / leading 0. */
    private function phoneKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return strlen($digits) >= 8 ? substr($digits, -8) : null;
    }

    private function clientKey(Order $order): ?string
    {
        if ($order->user_id) {
            return 'u' . $order->user_id;
        }

        return $order->info?->email ? 'e' . strtolower($order->info->email) : null;
    }

    private function statusName(int $status): string
    {
        return match ($status) {
            Order::STATUS_PAID => 'confirmed',
            Order::STATUS_SHIPPED => 'shipped',
            Order::STATUS_CANCELLED => 'cancelled',
            default => 'pending',
        };
    }
}
