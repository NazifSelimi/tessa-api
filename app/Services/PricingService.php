<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The single place that prices a cart: product prices, offers, coupon, the
 * minimum-profit limit and shipping. Checkout, the cart preview and the admin
 * offer calculator all use it, so they always agree.
 */
class PricingService
{
    public const DEFAULT_COST_PERCENTAGE = 65.0;
    public const DEFAULT_MIN_MARGIN_PERCENTAGE = 15.0;
    public const FREE_SHIPPING_FROM = 3000.0;
    public const SHIPPING_COST = 150.0;

    public function __construct(
        private readonly ReferralService $referrals
    ) {}

    public function settings(): array
    {
        return [
            'costPercentage' => (float) Setting::get('pricing.cost_percentage', self::DEFAULT_COST_PERCENTAGE),
            'minMarginPercentage' => (float) Setting::get('pricing.min_margin_percentage', self::DEFAULT_MIN_MARGIN_PERCENTAGE),
        ];
    }

    public function updateSettings(array $values): array
    {
        if (array_key_exists('costPercentage', $values)) {
            Setting::put('pricing.cost_percentage', (float) $values['costPercentage']);
        }
        if (array_key_exists('minMarginPercentage', $values)) {
            Setting::put('pricing.min_margin_percentage', (float) $values['minMarginPercentage']);
        }

        return $this->settings();
    }

    /**
     * Real purchase cost when known, else the category's or the global cost
     * percentage of the retail price. Null when there is nothing to estimate
     * from (no cost and no retail price); such a line is never discounted.
     */
    public function unitCost(Product $product, ?array $settings = null): ?float
    {
        if ((float) $product->cost_price > 0) {
            return (float) $product->cost_price;
        }

        if ((float) $product->price <= 0) {
            return null;
        }

        $settings ??= $this->settings();
        $percentage = $product->category?->cost_percentage ?? $settings['costPercentage'];

        return round((float) $product->price * (float) $percentage / 100, 2);
    }

    /**
     * Lowest unit price an offer or coupon may bring a product to: cost plus
     * the minimum margin, but never below what the customer already pays
     * (a salon price or sale under that line is a deliberate choice).
     */
    public function floorUnitPrice(float $unitPrice, ?float $unitCost, array $settings): float
    {
        // Switched off on /admin/features: offers and coupons are not limited by cost.
        if (! app(FeatureService::class)->enabled('profit_protection')) {
            return 0.0;
        }

        if ($unitCost === null) {
            return $unitPrice;
        }

        $margin = min(max($settings['minMarginPercentage'], 0), 90) / 100;

        return min($unitPrice, round($unitCost / (1 - $margin), 2));
    }

    /**
     * @param array<int, array{product: Product, qty: int}> $lines
     * @param bool $strict Checkout rejects offers the cart does not qualify for;
     *                     the cart preview skips them and says why instead.
     */
    public function quote(?User $user, array $lines, array $bundleIds = [], ?Coupon $coupon = null, bool $strict = true): array
    {
        $settings = $this->settings();
        $isStylist = $user?->isStylist() ?? false;

        $subtotal = 0.0;
        $floorTotal = 0.0;
        $cost = 0.0;
        $quantities = [];
        $prices = [];
        $quotedLines = [];

        $familyOf = [];
        foreach ($lines as ['product' => $product, 'qty' => $qty]) {
            $familyOf[$product->id] = $product->product_family_id;
            $unitPrice = $product->resolvePrice($isStylist);
            if ($unitPrice <= 0) {
                throw ValidationException::withMessages([
                    'items' => ["Product ID {$product->id} is not available for purchase."],
                ]);
            }

            $unitCost = $this->unitCost($product, $settings);
            $subtotal += $unitPrice * $qty;
            $floorTotal += $this->floorUnitPrice($unitPrice, $unitCost, $settings) * $qty;
            // An unknown cost is counted as the price paid: no profit is claimed for it.
            $cost += ($unitCost ?? $unitPrice) * $qty;
            $quantities[$product->id] = ($quantities[$product->id] ?? 0) + $qty;
            $prices[$product->id] = $unitPrice;

            $quotedLines[] = [
                'productId' => $product->id,
                'qty' => $qty,
                'unitPrice' => $unitPrice,
                'lineTotal' => round($unitPrice * $qty, 2),
                'unitCost' => $unitCost,
            ];
        }

        ['discount' => $bundleDiscount, 'applied' => $appliedBundles, 'skipped' => $skippedBundles]
            = $this->bundleDiscount($bundleIds, $quantities, $prices, $user, $strict);

        // "Pick any colours" offers apply by themselves once the cart reaches a step.
        ['discount' => $dealDiscount, 'applied' => $dealIds, 'progress' => $colourDeals]
            = $this->colourDeals($user, $quantities, $prices, $familyOf);
        $bundleDiscount += $dealDiscount;
        $appliedBundles = array_merge($appliedBundles, $dealIds);

        $couponDiscount = $coupon ? $coupon->calculateDiscount(max(0, $subtotal - $bundleDiscount)) : 0.0;

        // Offers and coupon together may not take the order under the profit floor.
        // The coupon gives way first, then the offers.
        $maxDiscount = max(0, $subtotal - $floorTotal);
        $limited = $bundleDiscount + $couponDiscount > $maxDiscount + 0.004;
        if ($limited) {
            $bundleDiscount = min($bundleDiscount, $maxDiscount);
            $couponDiscount = max(0, min($couponDiscount, $maxDiscount - $bundleDiscount));
        }

        // Whole denars: prices are whole, so totals stay whole too. Rounding the
        // discount down also keeps it inside the profit floor.
        $bundleDiscount = $this->wholeDenars($bundleDiscount);
        $couponDiscount = $this->wholeDenars($couponDiscount);

        // A stylist's referral credit is a cost too, so discount plus credit
        // stay inside the floor: the client discount gives way first, then the credit.
        $referralCommission = 0.0;
        if ($coupon?->isReferral()) {
            ['discount' => $couponDiscount, 'commission' => $referralCommission, 'limited' => $cut]
                = $this->fitReferral($coupon, $subtotal - $bundleDiscount, $couponDiscount, max(0, $maxDiscount - $bundleDiscount));
            $limited = $limited || $cut;
        }

        $discount = $bundleDiscount + $couponDiscount;
        $shipping = $subtotal >= self::FREE_SHIPPING_FROM ? 0.0 : self::SHIPPING_COST;
        $revenue = $subtotal - $discount;
        $profit = $revenue - $referralCommission - $cost;

        return [
            'lines' => $quotedLines,
            'subtotal' => round($subtotal, 2),
            'bundleDiscount' => $bundleDiscount,
            'couponDiscount' => $couponDiscount,
            'discount' => round($discount, 2),
            'discountLimited' => $limited,
            'appliedBundleIds' => $appliedBundles,
            'skippedBundles' => $skippedBundles,
            'colourDeals' => $colourDeals,
            'couponCode' => $coupon?->code,
            'shipping' => $shipping,
            'amountToFreeShipping' => round(max(0, self::FREE_SHIPPING_FROM - $subtotal), 2),
            'total' => round($revenue + $shipping, 2),
            // Admin-only figures; strip before sending to customers.
            'referralCommission' => $referralCommission,
            'cost' => round($cost, 2),
            'profit' => round($profit, 2),
            'marginPercentage' => $revenue > 0 ? round($profit / $revenue * 100, 1) : null,
        ];
    }

    /** Remove the figures customers must never see. */
    public function forCustomer(array $quote): array
    {
        unset($quote['cost'], $quote['profit'], $quote['marginPercentage'], $quote['referralCommission']);
        $quote['lines'] = array_map(function (array $line) {
            unset($line['unitCost']);
            return $line;
        }, $quote['lines']);

        return $quote;
    }

    /** Saving an offer gives on its own, before the profit floor. */
    public function offerSaving(Bundle $bundle, callable $priceOf): array
    {
        $regular = 0.0;
        foreach ($bundle->products as $product) {
            $regular += $priceOf($product) * (int) $product->pivot->quantity;
        }

        $saving = match ($bundle->promotion_type) {
            'fixed_price' => max(0, $regular - (float) $bundle->bundle_price),
            'bonus_items' => $bundle->products
                ->sum(fn ($product) => $priceOf($product) * (int) $product->pivot->bonus_quantity),
            default => $regular * ((float) $bundle->discount_percentage / 100),
        };

        return ['regular' => $regular, 'saving' => min($regular, $saving)];
    }

    /**
     * What the offer earns for retail customers and for salons: regular price,
     * offer price, estimated cost, profit before and after, and whether the
     * profit floor would cut the saving at checkout.
     */
    public function simulateOffer(Bundle $bundle): array
    {
        $settings = $this->settings();
        $result = ['settings' => $settings];

        foreach (['retail' => false, 'salon' => true] as $audience => $isStylist) {
            $priceOf = fn (Product $product) => $product->resolvePrice($isStylist);
            ['regular' => $regular, 'saving' => $saving] = $this->offerSaving($bundle, $priceOf);

            $cost = 0.0;
            $floor = 0.0;
            $unpriced = [];
            foreach ($bundle->products as $product) {
                $qty = (int) $product->pivot->quantity;
                $unitPrice = $priceOf($product);
                if ($unitPrice <= 0) {
                    $unpriced[] = $product->name;
                }
                $unitCost = $this->unitCost($product, $settings);
                $cost += ($unitCost ?? $unitPrice) * $qty;
                $floor += $this->floorUnitPrice($unitPrice, $unitCost, $settings) * $qty;
            }

            $applied = min($saving, max(0, $regular - $floor));
            $profitBefore = $regular - $cost;
            $profitAfter = $regular - $applied - $cost;

            $result[$audience] = [
                'available' => $audience === 'retail' ? $bundle->audience !== 'stylist' : $bundle->audience !== 'customer',
                'unpricedProducts' => $unpriced,
                'regularPrice' => round($regular, 2),
                'offerPrice' => round($regular - $saving, 2),
                'saving' => $this->wholeDenars($saving),
                'savingPercentage' => $regular > 0 ? round($saving / $regular * 100, 1) : 0,
                'cost' => round($cost, 2),
                'lowestAllowedPrice' => round($floor, 2),
                'appliedSaving' => $this->wholeDenars($applied),
                'limitedByProfitFloor' => $saving > $applied + 0.004,
                'profitBefore' => round($profitBefore, 2),
                'profitAfter' => round($profitAfter, 2),
                'marginAfterPercentage' => $regular - $applied > 0 ? round($profitAfter / ($regular - $applied) * 100, 1) : null,
                // How many offers must sell to earn what one sale at the regular price earns.
                'salesNeededToMatch' => $profitAfter > 0 && $profitBefore > 0 ? round($profitBefore / $profitAfter, 2) : null,
            ];
        }

        return $result;
    }

    /** Round a discount down to whole MKD (the epsilon absorbs float noise like 99.99999). */
    /**
     * Largest whole-denar referral discount whose discount plus stylist credit
     * fits in $room; the credit is cut only when even no discount does not fit.
     *
     * @return array{discount: float, commission: float, limited: bool}
     */
    private function fitReferral(Coupon $coupon, float $goods, float $discount, float $room): array
    {
        $commissionAt = fn (float $d) => $this->referrals->commission($coupon, $goods - $d);
        $wanted = $discount;

        if ($discount + $commissionAt($discount) > $room + 0.004) {
            // Uncapped: d + p(goods - d) <= room. Capped: d + cap <= room.
            $rate = min((float) $coupon->commission_percentage / 100, 0.99);
            $cap = $this->referrals->settings()['maxCommission'];
            $fit = ($room - $rate * $goods) / (1 - $rate);
            if ($cap > 0) {
                $fit = max($fit, $room - $cap);
            }
            $discount = $this->wholeDenars(max(0, min($discount, $fit)));
            // Commission rounds to whole denars; step down if that tipped it over.
            while ($discount > 0 && $discount + $commissionAt($discount) > $room + 0.004) {
                $discount--;
            }
        }

        $commission = min($commissionAt($discount), $this->wholeDenars(max(0, $room - $discount)));

        return [
            'discount' => $discount,
            'commission' => $commission,
            'limited' => $discount < $wanted || $commission < $commissionAt($discount),
        ];
    }

    /**
     * "Pick any" offers: every product the offer counts (shades of its colour
     * lines, or its own product list) adds to one total. The highest step
     * reached gives its free units (the cheapest ones), its gifts that are in
     * the cart (gift_ids: one of each; gift: gift_qty units from the offer's
     * gift list, e.g. an oxidant). Also returns how far each offer is, so the
     * cart can say "4 more for a free oxidant".
     */
    private function colourDeals(?User $user, array $quantities, array $prices, array $familyOf): array
    {
        $result = ['discount' => 0.0, 'applied' => [], 'progress' => []];
        if (! $quantities || ! app(FeatureService::class)->enabled('colour_deals')) {
            return $result;
        }

        $deals = Bundle::query()->with(['products', 'family'])
            ->where('promotion_type', Bundle::MIX_MATCH)
            ->where('is_active', true)
            ->get()
            ->filter(fn (Bundle $deal) => $deal->isRunning() && $deal->isAvailableFor($user));

        $giftUsed = [];
        $takeGift = function (int|string $id) use ($quantities, &$giftUsed): bool {
            if (($quantities[$id] ?? 0) <= ($giftUsed[$id] ?? 0)) {
                return false;
            }
            $giftUsed[$id] = ($giftUsed[$id] ?? 0) + 1;

            return true;
        };

        foreach ($deals as $deal) {
            $tiers = $deal->sortedTiers();
            $families = $deal->familyIds();
            $listed = $deal->products->reject(fn ($product) => Bundle::isGiftLine($product->pivot))->pluck('id')->all();
            $pool = array_filter($quantities, fn ($qty, $id) => in_array((int) ($familyOf[$id] ?? 0), $families, true)
                || in_array($id, $listed), ARRAY_FILTER_USE_BOTH);
            $count = array_sum($pool);
            if (! $tiers || $count === 0) {
                continue;
            }

            $reached = null;
            $next = null;
            foreach ($tiers as $tier) {
                if ($count >= $tier['min']) {
                    $reached = $tier;
                } elseif (! $next) {
                    $next = $tier;
                }
            }

            $saving = 0.0;
            $giftIds = [];
            $giftMissing = false;
            if ($reached) {
                if ($reached['free'] > 0) {
                    $units = [];
                    foreach ($pool as $id => $qty) {
                        array_push($units, ...array_fill(0, $qty, (float) $prices[$id]));
                    }
                    sort($units);
                    $saving += array_sum(array_slice($units, 0, $reached['free']));
                }
                foreach ($reached['gift_ids'] as $id) {
                    if ($takeGift($id)) {
                        $giftIds[] = (string) $id;
                        $saving += (float) $prices[$id];
                    } else {
                        $giftMissing = true;
                    }
                }
                if ($reached['gift']) {
                    $wanted = $reached['gift_qty'];
                    foreach ($deal->products->filter(fn ($product) => Bundle::isGiftLine($product->pivot)) as $option) {
                        while ($wanted > 0 && $takeGift($option->id)) {
                            $giftIds[] = (string) $option->id;
                            $saving += (float) $prices[$option->id];
                            $wanted--;
                        }
                    }
                    $giftMissing = $giftMissing || $wanted > 0;
                }
            }

            if ($saving > 0) {
                $result['discount'] += $saving;
                $result['applied'][] = (string) $deal->id;
            }
            $result['progress'][] = [
                'bundleId' => (string) $deal->id,
                'name' => $deal->name,
                'familyName' => $deal->family?->name,
                'count' => $count,
                'reached' => $reached,
                'next' => $next ? $next + ['need' => $next['min'] - $count] : null,
                'saving' => round($saving, 2),
                'giftProductId' => $giftIds[0] ?? null,
                'giftProductIds' => $giftIds,
                'giftOptions' => $deal->products->filter(fn ($product) => Bundle::isGiftLine($product->pivot))
                    ->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
                'giftMissing' => $giftMissing,
                // Colour lines count "colours"; a product list counts "pieces". Gift names for the reward text.
                'isColour' => (bool) $families,
                'giftNames' => (object) $deal->products->filter(fn ($product) => Bundle::isGiftLine($product->pivot))
                    ->mapWithKeys(fn ($product) => [(string) $product->id => $product->name])->all(),
            ];
        }

        return $result;
    }

    private function wholeDenars(float $amount): float
    {
        return (float) floor($amount + 0.0001);
    }

    private function bundleDiscount(array $bundleIds, array $quantities, array $prices, ?User $user, bool $strict): array
    {
        $result = ['discount' => 0.0, 'applied' => [], 'skipped' => []];
        if (empty($bundleIds)) {
            return $result;
        }

        // Offers switched off on /admin/features never discount a cart.
        $bundles = app(FeatureService::class)->enabled('bundles')
            ? Bundle::query()->with('products')->findMany($bundleIds)
            : collect();
        $fail = function (string $id, string $message) use ($strict, &$result) {
            if ($strict) {
                throw ValidationException::withMessages(['bundle_ids' => [$message]]);
            }
            $result['skipped'][] = ['bundleId' => $id, 'reason' => $message];
        };

        foreach (array_diff(array_map('strval', $bundleIds), $bundles->pluck('id')->map(fn ($id) => (string) $id)->all()) as $missing) {
            $fail($missing, 'One or more selected offers are unavailable.');
        }
        // Colour deals are priced by colourDeals(), whichever products the cart has.
        $bundles = $bundles->reject(fn (Bundle $bundle) => $bundle->isMixMatch());

        $remaining = $quantities;
        $isStylist = $user?->isStylist() ?? false;
        $priceOf = fn (Product $product) => $prices[$product->id] ?? $product->resolvePrice($isStylist);

        foreach ($bundles as $bundle) {
            if (!$bundle->isAvailableFor($user)) {
                $fail((string) $bundle->id, "{$bundle->name} is not available for this account.");
                continue;
            }

            $qualifies = $bundle->products->every(
                fn ($product) => ($remaining[$product->id] ?? 0) >= (int) $product->pivot->quantity
            );
            if (!$qualifies) {
                $fail((string) $bundle->id, "Add all items from {$bundle->name} to use this offer.");
                continue;
            }

            foreach ($bundle->products as $product) {
                $remaining[$product->id] -= (int) $product->pivot->quantity;
            }

            $result['discount'] += $this->offerSaving($bundle, $priceOf)['saving'];
            $result['applied'][] = (string) $bundle->id;
        }

        return $result;
    }
}
