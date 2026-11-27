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
    /** Defaults; the free-shipping amount and the discount cap are settings (owner rules Q1, Q6). */
    public const FREE_SHIPPING_FROM = 3000.0;
    public const SHIPPING_COST = 150.0;
    public const DEFAULT_MAX_DISCOUNT_PERCENTAGE = 40.0;

    public function __construct(
        private readonly ReferralService $referrals
    ) {}

    public function settings(): array
    {
        return [
            'costPercentage' => (float) Setting::get('pricing.cost_percentage', self::DEFAULT_COST_PERCENTAGE),
            'minMarginPercentage' => (float) Setting::get('pricing.min_margin_percentage', self::DEFAULT_MIN_MARGIN_PERCENTAGE),
            // All discounts together (offers + pick-any deals + coupon) never go above this % of the goods (Q1).
            'maxDiscountPercentage' => (float) Setting::get('pricing.max_discount_percentage', config('tessa.max_discount_percentage', self::DEFAULT_MAX_DISCOUNT_PERCENTAGE)),
        ] + $this->shippingSettings();
    }

    /** Free shipping (Q6). Public: the shop shows these numbers before a cart is priced. */
    public function shippingSettings(): array
    {
        return [
            'freeShippingFrom' => (float) Setting::get('shipping.free_from', self::FREE_SHIPPING_FROM),
            // false (default, as before): count the goods before discounts; true: what the customer pays for them.
            'freeShippingAfterDiscounts' => filter_var(Setting::get('shipping.free_after_discounts', false), FILTER_VALIDATE_BOOLEAN),
            'shippingCost' => self::SHIPPING_COST,
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
        if (array_key_exists('maxDiscountPercentage', $values)) {
            Setting::put('pricing.max_discount_percentage', (float) $values['maxDiscountPercentage']);
        }
        if (array_key_exists('freeShippingFrom', $values)) {
            Setting::put('shipping.free_from', (float) $values['freeShippingFrom']);
        }
        if (array_key_exists('freeShippingAfterDiscounts', $values)) {
            Setting::put('shipping.free_after_discounts', $values['freeShippingAfterDiscounts'] ? '1' : '0');
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
    /**
     * @param bool|null $asStylist admin preview only: price as a salon (true) or a shopper (false)
     *                             instead of by the account's own role.
     */
    public function quote(?User $user, array $lines, array $bundleIds = [], ?Coupon $coupon = null, bool $strict = true, ?bool $asStylist = null, bool $suggest = true): array
    {
        $settings = $this->settings();
        $isStylist = $asStylist ?? ($user?->isStylist() ?? false);

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

        // Only the offers and pick-any deals the customer picked (bundle_ids) count;
        // nothing applies by itself. Two picks that need the same units: the last one wins.
        ['discount' => $bundleDiscount, 'applied' => $appliedBundles, 'skipped' => $skippedBundles,
            'replaced' => $replacedBundles, 'progress' => $colourDeals, 'used' => $offersUsed]
            = $this->pickedOffers($bundleIds, $quantities, $prices, $familyOf, $user, $strict, $isStylist);

        $couponDiscount = $coupon ? $coupon->calculateDiscount(max(0, $subtotal - $bundleDiscount)) : 0.0;

        // Offers and coupon together may not take the order under the profit floor,
        // nor above the discount cap (Q1). The coupon gives way first, then the offers.
        $floorRoom = max(0, $subtotal - $floorTotal);
        $capRoom = $this->wholeDenars($subtotal * min(max($settings['maxDiscountPercentage'], 0), 100) / 100);
        $maxDiscount = min($floorRoom, $capRoom);
        $capBinds = $capRoom < $floorRoom;
        $over = $bundleDiscount + $couponDiscount > $maxDiscount + 0.004;
        if ($over) {
            $bundleDiscount = min($bundleDiscount, $maxDiscount);
            $couponDiscount = max(0, min($couponDiscount, $maxDiscount - $bundleDiscount));
        }
        // "Limited" = the profit floor cut it; "capped" = the discount cap cut it ("Maximum discount reached").
        $limited = $over && ! $capBinds;
        $capped = $over && $capBinds;

        // Whole denars: prices are whole, so totals stay whole too. Rounding the
        // discount down also keeps it inside the profit floor.
        $bundleDiscount = $this->wholeDenars($bundleDiscount);
        $couponDiscount = $this->wholeDenars($couponDiscount);

        // "Offer used: <name> −<saving>": what each offer really gives after the cap and floor,
        // earliest pick first, so the lines add up to the offer discount.
        $left = $bundleDiscount;
        foreach ($offersUsed as &$used) {
            $used['saving'] = min($this->wholeDenars($used['saving']), $left);
            $used['price'] = round($used['regular'] - $used['saving'], 2);
            $left -= $used['saving'];
        }
        unset($used);

        // A stylist's referral credit is a cost too, so discount plus credit
        // stay inside the floor: the client discount gives way first, then the credit.
        $referralCommission = 0.0;
        if ($coupon?->isReferral()) {
            ['discount' => $couponDiscount, 'commission' => $referralCommission, 'limited' => $cut]
                = $this->fitReferral($coupon, $subtotal - $bundleDiscount, $couponDiscount, max(0, $maxDiscount - $bundleDiscount));
            $limited = $limited || ($cut && ! $capBinds);
            $capped = $capped || ($cut && $capBinds);
        }

        $discount = $bundleDiscount + $couponDiscount;
        // Free shipping from the amount set in Admin, counted before or after discounts (Q6).
        $shippingBase = $settings['freeShippingAfterDiscounts'] ? $subtotal - $discount : $subtotal;
        $shipping = $shippingBase >= $settings['freeShippingFrom'] ? 0.0 : $settings['shippingCost'];
        $revenue = $subtotal - $discount;
        $profit = $revenue - $referralCommission - $cost;

        return [
            'lines' => $quotedLines,
            'subtotal' => round($subtotal, 2),
            'bundleDiscount' => $bundleDiscount,
            'couponDiscount' => $couponDiscount,
            'discount' => round($discount, 2),
            'discountLimited' => $limited,
            'discountCapped' => $capped,
            'maxDiscountPercentage' => $settings['maxDiscountPercentage'],
            'appliedBundleIds' => $appliedBundles,
            'skippedBundles' => $skippedBundles,
            // Picked offers another, later pick took the products of: the cart drops them.
            'replacedBundles' => $replacedBundles,
            'offersUsed' => $offersUsed,
            // Offers the cart matches but the customer did not pick: the shop asks "Use offer X and save Y?".
            'suggestedOffers' => ! $strict && $suggest
                ? $this->suggestions($user, $lines, $bundleIds, $quantities, $prices, $familyOf, $isStylist, $asStylist, $bundleDiscount)
                : [],
            'colourDeals' => $colourDeals,
            'couponCode' => $coupon?->code,
            'shipping' => $shipping,
            'amountToFreeShipping' => round(max(0, $settings['freeShippingFrom'] - $shippingBase), 2),
            'freeShippingFrom' => $settings['freeShippingFrom'],
            'freeShippingAfterDiscounts' => $settings['freeShippingAfterDiscounts'],
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
     * One pick-any deal on the given units. Null when it counts none of them.
     *
     * @return array{saving: float, taken: array<int|string, int>, progress: array}|null
     */
    private function evaluateDeal(Bundle $deal, array $available, array $prices, array $familyOf): ?array
    {
        $tiers = $deal->sortedTiers();
        $families = $deal->familyIds();
        $giftLines = $deal->products->filter(fn ($product) => Bundle::isGiftLine($product->pivot));
        $listed = $deal->products->reject(fn ($product) => Bundle::isGiftLine($product->pivot))->pluck('id')->all();
        $pool = array_filter($available, fn ($qty, $id) => in_array((int) ($familyOf[$id] ?? 0), $families, true)
            || in_array($id, $listed), ARRAY_FILTER_USE_BOTH);
        $count = array_sum($pool);
        if (! $tiers || $count === 0) {
            return null;
        }

        $giftUsed = [];
        $takeGift = function (int|string $id) use ($available, &$giftUsed): bool {
            // As before: a free colour may be one of the counted shades.
            if (($available[$id] ?? 0) <= ($giftUsed[$id] ?? 0)) {
                return false;
            }
            $giftUsed[$id] = ($giftUsed[$id] ?? 0) + 1;

            return true;
        };

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
                foreach ($giftLines as $option) {
                    while ($wanted > 0 && $takeGift($option->id)) {
                        $giftIds[] = (string) $option->id;
                        $saving += (float) $prices[$option->id];
                        $wanted--;
                    }
                }
                $giftMissing = $giftMissing || $wanted > 0;
            }
        }

        // Units this deal uses: the counted ones, plus gifts (a gift may be a counted unit).
        $taken = $pool;
        foreach ($giftUsed as $id => $qty) {
            $taken[$id] = max($taken[$id] ?? 0, $qty);
        }

        return [
            'saving' => $saving,
            'taken' => $taken,
            'progress' => [
                'bundleId' => (string) $deal->id,
                'name' => $deal->name,
                'familyName' => $deal->family?->name,
                'count' => $count,
                'reached' => $reached,
                'next' => $next ? $next + ['need' => $next['min'] - $count] : null,
                'saving' => round($saving, 2),
                'giftProductId' => $giftIds[0] ?? null,
                'giftProductIds' => $giftIds,
                'giftOptions' => $giftLines->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
                'giftMissing' => $giftMissing,
                // Colour lines count "colours"; a product list counts "pieces". Gift names for the reward text.
                'isColour' => (bool) $families,
                'giftNames' => (object) $giftLines
                    ->mapWithKeys(fn ($product) => [(string) $product->id => $product->name])->all(),
            ],
        ];
    }

    private function wholeDenars(float $amount): float
    {
        return (float) floor($amount + 0.0001);
    }

    /**
     * The offers and pick-any deals the customer picked, newest pick first: a
     * pick takes the product units it needs, so when two picks need the same
     * units the one picked last keeps them and the other is "replaced". An
     * offer whose products are not all in the cart gives nothing and says what
     * to add. A picked deal gives the reward of the step its units reach.
     *
     * @return array{discount: float, applied: array, skipped: array, replaced: array, progress: array, used: array}
     */
    private function pickedOffers(array $bundleIds, array $quantities, array $prices, array $familyOf, ?User $user, bool $strict, bool $isStylist): array
    {
        $result = ['discount' => 0.0, 'applied' => [], 'skipped' => [], 'replaced' => [], 'progress' => [], 'used' => []];
        $ids = array_values(array_unique(array_map('strval', $bundleIds)));
        if (! $ids) {
            return $result;
        }

        $features = app(FeatureService::class);
        $bundles = Bundle::query()->with(['products', 'family'])->findMany($ids)->keyBy(fn (Bundle $b) => (string) $b->id);
        $fail = function (string $id, string $message, array $extra = []) use ($strict, &$result) {
            if ($strict) {
                throw ValidationException::withMessages(['bundle_ids' => [$message]]);
            }
            $result['skipped'][] = ['bundleId' => $id, 'reason' => $message] + $extra;
        };
        $priceOf = fn (Product $product) => $prices[$product->id] ?? $product->resolvePrice($isStylist);

        $available = array_filter($quantities, fn ($qty) => $qty > 0);
        // product id => ids of the picks that took its units, to say who replaced whom.
        $takenBy = [];
        $take = function (string $id, array $units) use (&$available, &$takenBy) {
            foreach ($units as $productId => $qty) {
                $available[$productId] = ($available[$productId] ?? 0) - $qty;
                $takenBy[$productId][] = $id;
            }
            $available = array_filter($available, fn ($qty) => $qty > 0);
        };
        $replacedBy = function (string $id, Bundle $bundle, array $productIds) use (&$result, &$takenBy, $bundles, $fail) {
            $by = collect($productIds)->flatMap(fn ($productId) => $takenBy[$productId] ?? [])->unique()->first();
            $byName = $by !== null ? $bundles[$by]?->name : null;
            $result['replaced'][] = ['bundleId' => $id, 'name' => $bundle->name, 'byBundleId' => $by, 'byName' => $byName];
            $fail($id, "Offer {$bundle->name} was replaced by offer {$byName} (they share products).", [
                'name' => $bundle->name, 'replacedBy' => $by,
            ]);
        };

        $applied = [];
        $regular = [];
        foreach (array_reverse($ids) as $id) {
            $bundle = $bundles[$id] ?? null;
            $on = $bundle && $features->enabled($bundle->isMixMatch() ? 'colour_deals' : 'bundles');
            if (! $bundle || ! $on) {
                $fail($id, 'One or more selected offers are unavailable.');
                continue;
            }
            if (! $bundle->isAvailableFor($user)) {
                $fail($id, "{$bundle->name} is not available for this account.", ['name' => $bundle->name]);
                continue;
            }

            if ($bundle->isMixMatch()) {
                $deal = $this->evaluateDeal($bundle, $available, $prices, $familyOf);
                if ($deal && $deal['saving'] > 0) {
                    $take($id, $deal['taken']);
                    $applied[$id] = $deal['saving'];
                    // The units the deal uses at their normal price, for "Offer: … 1.390 (1.580)".
                    $regular[$id] = collect($deal['taken'])->sum(fn ($qty, $productId) => (float) ($prices[$productId] ?? 0) * $qty);
                    $result['progress'][$id] = $deal['progress'];
                    continue;
                }
                $full = $this->evaluateDeal($bundle, $quantities, $prices, $familyOf);
                if ($full && $full['saving'] > 0) {
                    // The cart reaches a step, but a later pick took those units.
                    $replacedBy($id, $bundle, array_keys($full['taken']));
                    continue;
                }
                // Picked, no step reached yet: no discount, the cart says how many more.
                if ($deal ?? $full) {
                    $result['progress'][$id] = ($deal ?? $full)['progress'];
                }
                if (! $strict) {
                    $result['skipped'][] = ['bundleId' => $id, 'name' => $bundle->name, 'reason' => "Add more to reach a step of {$bundle->name}.", 'missing' => []];
                }
                continue;
            }

            $need = $bundle->products->mapWithKeys(fn ($product) => [$product->id => (int) $product->pivot->quantity]);
            $missing = $bundle->products
                ->filter(fn ($product) => ($quantities[$product->id] ?? 0) < $need[$product->id])
                ->map(fn ($product) => [
                    'productId' => (string) $product->id,
                    'name' => $product->name,
                    'qty' => $need[$product->id] - ($quantities[$product->id] ?? 0),
                ])->values()->all();
            if ($missing) {
                $names = collect($missing)->map(fn ($m) => ($m['qty'] > 1 ? "{$m['qty']}× " : '') . $m['name'])->implode(', ');
                $fail($id, "Add {$names} to use {$bundle->name}.", ['name' => $bundle->name, 'missing' => $missing]);
                continue;
            }
            $short = $need->filter(fn ($qty, $productId) => ($available[$productId] ?? 0) < $qty)->keys()->all();
            if ($short) {
                $replacedBy($id, $bundle, $short);
                continue;
            }

            $take($id, $need->all());
            ['regular' => $regular[$id], 'saving' => $applied[$id]] = $this->offerSaving($bundle, $priceOf);
        }

        // Back to pick order.
        foreach ($ids as $id) {
            if (isset($applied[$id])) {
                $result['discount'] += $applied[$id];
                $result['applied'][] = $id;
                $result['used'][] = ['bundleId' => $id, 'name' => $bundles[$id]->name, 'saving' => $applied[$id], 'regular' => round($regular[$id], 2)];
            }
        }
        $result['progress'] = array_values(array_filter(array_map(fn ($id) => $result['progress'][$id] ?? null, $ids)));

        return $result;
    }

    /**
     * Running offers and deals the cart matches that the customer did not
     * pick, with what picking each one would save (the cart priced again with
     * it picked, so the cap, the floor and a replaced pick are counted).
     */
    private function suggestions(?User $user, array $lines, array $bundleIds, array $quantities, array $prices, array $familyOf, bool $isStylist, ?bool $asStylist, float $currentDiscount): array
    {
        $quantities = array_filter($quantities, fn ($qty) => $qty > 0);
        if (! $quantities) {
            return [];
        }
        $picked = array_map('strval', $bundleIds);
        $features = app(FeatureService::class);
        // Admin "view as": the offers of the audience being viewed.
        $fits = fn (Bundle $bundle) => $bundle->isRunning() && ($asStylist === null
            ? $bundle->isAvailableFor($user)
            : match ($bundle->audience) { 'stylist' => $asStylist, 'customer' => ! $asStylist, default => true });

        $candidates = collect();
        if ($features->enabled('bundles')) {
            $candidates = $candidates->merge(Bundle::query()->with('products')
                ->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('promotion_type')->orWhere('promotion_type', '!=', Bundle::MIX_MATCH))
                ->whereNotIn('id', $picked ?: [0])
                ->whereHas('products', fn ($q) => $q->whereIn('products.id', array_keys($quantities)))
                ->get()
                ->filter($fits)
                ->filter(fn (Bundle $b) => $b->products->every(fn ($p) => ($quantities[$p->id] ?? 0) >= (int) $p->pivot->quantity)));
        }
        if ($features->enabled('colour_deals')) {
            $candidates = $candidates->merge(Bundle::query()->with(['products', 'family'])
                ->where('promotion_type', Bundle::MIX_MATCH)->where('is_active', true)
                ->whereNotIn('id', $picked ?: [0])
                ->get()
                ->filter($fits)
                ->filter(fn (Bundle $deal) => ($this->evaluateDeal($deal, $quantities, $prices, $familyOf)['saving'] ?? 0) > 0));
        }

        $found = [];
        foreach ($candidates->take(10) as $candidate) {
            $with = $this->quote($user, $lines, [...$picked, (string) $candidate->id], null, false, $asStylist, false);
            $saving = $with['bundleDiscount'] - $currentDiscount;
            if (in_array((string) $candidate->id, $with['appliedBundleIds'], true) && $saving >= 1) {
                $found[] = [
                    'bundleId' => (string) $candidate->id,
                    'name' => $candidate->name,
                    'saving' => $saving,
                    // Picked offers this one would take the place of.
                    'replaces' => array_column($with['replacedBundles'], 'name'),
                ];
            }
        }
        usort($found, fn ($a, $b) => $b['saving'] <=> $a['saving']);

        return array_slice($found, 0, 3);
    }
}
