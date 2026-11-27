<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Read-only price check of every offer and pick-any deal, for a shopper and a
 * salon: the cart price PricingService::quote gives must be the price the
 * offer card promises (worked out by hand here), a cart without the offer gets
 * no discount, no offer sells under cost or above the discount cap, and
 * products that sit in more than one offer are listed.
 *
 * Used by `php artisan offers:audit` and by OfferAuditTest. Everything runs
 * inside a transaction that is rolled back, so nothing is ever saved.
 */
class OfferAuditor
{
    public const OK = 'OK';
    public const WRONG = 'WRONG';
    public const SKIP = 'SKIP';
    public const NA = 'N/A';
    /** Needs a person: e.g. under an estimated cost, when the product has no real cost. */
    public const CHECK = 'CHECK';

    /** Differences under one denar are rounding (the cart rounds discounts down to whole denars). */
    private const TOLERANCE = 1.0;

    public function __construct(private readonly PricingService $pricing) {}

    /**
     * @param Collection<int, Bundle>|null $bundles defaults to every non-dynamic offer
     * @return array{rows: array<int, array>, overlaps: array<int, array>, settings: array}
     */
    public function audit(?Collection $bundles = null): array
    {
        DB::beginTransaction();
        try {
            $bundles ??= Bundle::query()->where('is_dynamic', false)->orderBy('id')->get();
            $bundles->load(['products.sale', 'products.category', 'family']);
            $settings = $this->pricing->settings();

            $rows = [];
            foreach ($bundles as $bundle) {
                foreach (['customer' => false, 'stylist' => true] as $audience => $isStylist) {
                    $user = $this->viewer($isStylist);
                    if ($bundle->isMixMatch()) {
                        array_push($rows, ...$this->auditDeal($bundle, $audience, $user, $settings));
                    } else {
                        $rows[] = $this->auditOffer($bundle, $audience, $user, $settings);
                    }
                }
            }

            return ['rows' => $rows, 'overlaps' => $this->overlaps($bundles), 'settings' => $settings];
        } finally {
            DB::rollBack();
        }
    }

    public static function hasWrong(array $report): bool
    {
        return collect($report['rows'])->contains(fn ($row) => $row['verdict'] === self::WRONG);
    }

    /** A shopper or a salon that is never saved. */
    private function viewer(bool $isStylist): User
    {
        return (new User())->forceFill(['id' => 0, 'role' => $isStylist ? User::ROLE_STYLIST : User::ROLE_USER]);
    }

    private function row(Bundle $bundle, string $audience, string $case = ''): array
    {
        return [
            'bundleId' => (string) $bundle->id,
            'offer' => $bundle->name,
            'type' => $bundle->promotion_type ?: 'percentage',
            'case' => $case,
            'audience' => $audience,
            'running' => $bundle->isRunning(),
            'expected' => null,
            'real' => null,
            'difference' => null,
            'withoutOffer' => null,
            'verdict' => self::OK,
            'why' => [],
            'notes' => [],
        ];
    }

    private function skipReason(Bundle $bundle, bool $isStylist): ?string
    {
        if (! $bundle->isRunning()) {
            return match (true) {
                ! $bundle->is_active => 'switched off',
                $bundle->starts_at?->isFuture() => 'starts ' . $bundle->starts_at->toDateString(),
                default => 'ended ' . $bundle->ends_at?->toDateString(),
            };
        }
        if ($bundle->audience === 'stylist' && ! $isStylist) {
            return 'salons only';
        }
        if ($bundle->audience === 'customer' && $isStylist) {
            return 'shoppers only';
        }

        return null;
    }

    /** Fixed, percentage and free-item offers. */
    private function auditOffer(Bundle $bundle, string $audience, User $user, array $settings): array
    {
        $isStylist = $user->isStylist();
        $row = $this->row($bundle, $audience);

        if ($reason = $this->skipReason($bundle, $isStylist)) {
            $row['verdict'] = $bundle->isRunning() ? self::NA : self::SKIP;
            $row['why'][] = $reason;
            return $row;
        }
        if ($bundle->products->isEmpty()) {
            return $this->wrong($row, 'offer has no products');
        }

        // The price on the offer card (BundleDealRail.bundlePrice in tessa-ui): list prices, no sale.
        $cardPrice = fn (Product $product) => (float) ($isStylist ? $product->stylist_price : $product->price);
        $regular = $bundle->products->sum(fn ($p) => $cardPrice($p) * (int) $p->pivot->quantity);
        $expected = match ($row['type']) {
            'fixed_price' => (float) $bundle->bundle_price > 0 ? (float) $bundle->bundle_price : $regular,
            'bonus_items' => $regular - $bundle->products->sum(fn ($p) => $cardPrice($p) * (int) $p->pivot->bonus_quantity),
            default => $bundle->discount_percentage !== null ? $regular * (1 - (float) $bundle->discount_percentage / 100) : $regular,
        };
        $row['expected'] = round($expected, 2);

        $lines = $bundle->products->map(fn ($p) => ['product' => $p, 'qty' => (int) $p->pivot->quantity])->all();
        $quote = $this->quote($row, $user, $lines, [(string) $bundle->id]);
        if (! $quote) {
            return $row;
        }

        $real = $quote['subtotal'] - $quote['discount'];
        $row['real'] = round($real, 2);
        $row['difference'] = round($real - $expected, 2);

        if (! in_array((string) $bundle->id, $quote['appliedBundleIds'], true)) {
            $reason = collect($quote['skippedBundles'])->firstWhere('bundleId', (string) $bundle->id)['reason'] ?? 'not applied';
            $this->wrong($row, "cart did not apply the offer: {$reason}");
        }
        if (abs($row['difference']) >= self::TOLERANCE) {
            $row['verdict'] = self::WRONG;
            $row['why'] = array_merge($row['why'], $this->explain($bundle, $isStylist, $quote, $regular, $expected, $cardPrice));
        }
        if ($others = array_diff($quote['appliedBundleIds'], [(string) $bundle->id])) {
            $row['notes'][] = 'also applied: ' . $this->names($others);
        }

        $this->limits($row, $quote, $settings, $lines);

        // 2. Same products, no offer chosen: only a pick-any deal may discount them by itself.
        $plain = $this->quote($row, $user, $lines, [], false);
        if ($plain) {
            $row['withoutOffer'] = round($plain['subtotal'] - $plain['discount'], 2);
            if ($plain['discount'] > 0) {
                $deals = Bundle::query()->whereIn('id', $plain['appliedBundleIds'])->get();
                $byItself = $deals->every(fn (Bundle $deal) => $deal->isMixMatch());
                $note = "without the offer the cart still takes {$this->mkd($plain['discount'])} off"
                    . ($deals->isNotEmpty() ? ' (' . $deals->pluck('name')->implode(', ') . ')' : '');
                if ($byItself) {
                    $row['notes'][] = $note . ' — a pick-any deal, meant to apply by itself';
                } else {
                    $this->wrong($row, $note);
                }
            }
        }

        return $row;
    }

    /**
     * Pick-any deals: for every step, a cart with exactly the step's number of
     * one shade (the shade the card opens) plus the step's gifts.
     */
    private function auditDeal(Bundle $deal, string $audience, User $user, array $settings): array
    {
        $isStylist = $user->isStylist();
        $tiers = $deal->sortedTiers();
        $base = $this->row($deal, $audience);

        if ($reason = $this->skipReason($deal, $isStylist)) {
            $base['verdict'] = $deal->isRunning() ? self::NA : self::SKIP;
            $base['why'][] = $reason;
            return [$base];
        }
        if (! app(FeatureService::class)->enabled('colour_deals')) {
            $base['verdict'] = self::SKIP;
            $base['why'][] = 'pick-any deals are switched off on /admin/features';
            return [$base];
        }
        if (! $tiers) {
            return [$this->wrong($base, 'deal has no steps')];
        }

        $giftLines = $deal->products->filter(fn ($p) => Bundle::isGiftLine($p->pivot))->values();
        $listed = $deal->products->reject(fn ($p) => Bundle::isGiftLine($p->pivot))->values();
        $families = $deal->familyIds();
        $shade = $listed->first(fn ($p) => $p->isPricedFor($isStylist))
            ?? Product::query()->with(['sale', 'category'])->whereIn('product_family_id', $families ?: [0])
                ->pricedFor($isStylist)->orderByDesc('quantity')->orderBy('id')->first();
        if (! $shade) {
            return [$this->wrong($base, 'no product the deal counts has a price for ' . ($isStylist ? 'salons' : 'shoppers'))];
        }
        $counts = fn (Product $p) => in_array((int) $p->product_family_id, $families, true) || $listed->contains('id', $p->id);

        $rows = [];
        foreach ($tiers as $tier) {
            $row = $this->row($deal, $audience, "{$tier['min']} pcs" . ($tier['free'] ? " +{$tier['free']} free" : '')
                . ($tier['gift'] || $tier['gift_ids'] ? ' + gift' : ''));
            $row['notes'][] = "counted shade: {$shade->name}";

            // The cart: min × shade, then the step's gifts.
            $qty = [$shade->id => $tier['min']];
            $products = [$shade->id => $shade];
            $giftUnits = [];
            foreach ($tier['gift_ids'] as $id) {
                $giftUnits[] = (int) $id;
            }
            if ($tier['gift'] && $giftLines->isNotEmpty()) {
                array_push($giftUnits, ...array_fill(0, $tier['gift_qty'], $giftLines->first()->id));
            } elseif ($tier['gift']) {
                $this->wrong($row, 'step gives a gift but the deal has no gift products');
            }
            foreach ($giftUnits as $id) {
                $products[$id] ??= $deal->products->firstWhere('id', $id) ?? Product::with(['sale', 'category'])->find($id);
                if (! $products[$id]) {
                    $this->wrong($row, "gift product #{$id} does not exist");
                    unset($products[$id]);
                    continue;
                }
                $qty[$id] = ($qty[$id] ?? 0) + 1;
            }

            // By hand: the cheapest `free` counted units, plus each gift at its price.
            $price = fn (Product $p) => $p->resolvePrice($isStylist);
            $subtotal = 0.0;
            $units = [];
            foreach ($qty as $id => $n) {
                $subtotal += $price($products[$id]) * $n;
                if ($counts($products[$id])) {
                    array_push($units, ...array_fill(0, $n, $price($products[$id])));
                }
            }
            $reached = collect($tiers)->filter(fn ($t) => count($units) >= $t['min'])->last();
            if ($reached !== $tier) {
                $row['notes'][] = "the gifts are counted shades too, so the cart reaches the {$reached['min']} step";
            }
            sort($units);
            $saving = array_sum(array_slice($units, 0, $reached['free']));
            foreach ($giftUnits as $id) {
                if (isset($products[$id])) {
                    $saving += $price($products[$id]);
                }
            }
            $expected = $subtotal - $saving;
            $row['expected'] = round($expected, 2);

            $lines = collect($qty)->map(fn ($n, $id) => ['product' => $products[$id], 'qty' => $n])->values()->all();
            $quote = $this->quote($row, $user, $lines, []);
            if (! $quote) {
                $rows[] = $row;
                continue;
            }
            $real = $quote['subtotal'] - $quote['discount'];
            $row['real'] = round($real, 2);
            $row['difference'] = round($real - $expected, 2);

            if ($saving > 0 && ! in_array((string) $deal->id, $quote['appliedBundleIds'], true)) {
                $this->wrong($row, 'deal not applied at this step');
            }
            if (abs($row['difference']) >= self::TOLERANCE) {
                $row['verdict'] = self::WRONG;
                if ($quote['discountCapped']) {
                    $row['why'][] = "cut by the {$this->pct($settings['maxDiscountPercentage'])} discount cap";
                } elseif ($quote['discountLimited']) {
                    $row['why'][] = 'cut by the profit floor (cost + ' . $this->pct($settings['minMarginPercentage']) . ')';
                } else {
                    $row['why'][] = "cart takes {$this->mkd($quote['discount'])} off, by hand {$this->mkd($saving)}";
                }
            }
            if ($others = array_diff($quote['appliedBundleIds'], [(string) $deal->id])) {
                $row['notes'][] = 'also applied: ' . $this->names($others);
            }
            $this->limits($row, $quote, $settings, $lines);

            // One unit short of the step: the step's reward must not be given yet.
            if ($tier['min'] > 1) {
                $short = $lines;
                foreach ($short as &$line) {
                    if ($line['product']->id === $shade->id) {
                        $line['qty']--;
                    }
                }
                unset($line);
                $plain = $this->quote($row, $user, $short, [], false);
                $lower = collect($tiers)->filter(fn ($t) => $t['min'] < $tier['min'])->last();
                if ($plain) {
                    $row['withoutOffer'] = round($plain['subtotal'] - $plain['discount'], 2);
                    if (! $lower && $plain['discount'] > 0 && in_array((string) $deal->id, $plain['appliedBundleIds'], true)) {
                        $this->wrong($row, "one shade short of the first step the cart still takes {$this->mkd($plain['discount'])} off");
                    }
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function quote(array &$row, User $user, array $lines, array $bundleIds, bool $flag = true): ?array
    {
        try {
            return $this->pricing->quote($user, $lines, $bundleIds, null, false);
        } catch (ValidationException $e) {
            if ($flag) {
                $this->wrong($row, 'cart refused: ' . collect($e->errors())->flatten()->implode(' '));
            }
            return null;
        }
    }

    /** 3. Never under cost, never more than the discount cap. */
    private function limits(array &$row, array $quote, array $settings, array $lines): void
    {
        $goods = $quote['subtotal'] - $quote['discount'];
        if ($goods + 0.004 < $quote['cost']) {
            $estimated = collect($lines)->contains(fn ($line) => (float) $line['product']->cost_price <= 0);
            $text = "below cost: pays {$this->mkd($goods)}, cost {$this->mkd($quote['cost'])}";
            if (! $estimated) {
                $this->wrong($row, $text);
            } else {
                // No real cost: the cost is a guess (a % of the shop price), so this needs a person to look.
                $plainUnder = $quote['subtotal'] + 0.004 < $quote['cost'];
                $row['why'][] = $text . ' (estimated: ' . $this->pct($settings['costPercentage']) . ' of the shop price, no real cost'
                    . ($plainUnder ? '; the price without any offer is already under it)' : ')');
                if ($row['verdict'] === self::OK) {
                    $row['verdict'] = self::CHECK;
                }
            }
        }
        $off = $quote['subtotal'] > 0 ? $quote['discount'] / $quote['subtotal'] * 100 : 0;
        if ($off > 40 + 0.01) {
            $this->wrong($row, 'discount ' . $this->pct($off) . ' is above 40%');
        }
        if ($quote['discountLimited'] && $row['verdict'] === self::OK) {
            $row['notes'][] = 'profit floor trimmed the discount (within rounding)';
        }
    }

    /** Why the cart and the card disagree. */
    private function explain(Bundle $bundle, bool $isStylist, array $quote, float $regular, float $expected, callable $cardPrice): array
    {
        $why = [];
        foreach ($bundle->products as $product) {
            $paid = $product->resolvePrice($isStylist);
            if ($paid + 0.004 < $cardPrice($product)) {
                $why[] = "{$product->name} is on sale: cart {$this->mkd($paid)}, card {$this->mkd($cardPrice($product))}";
            }
        }
        if ($bundle->promotion_type === 'fixed_price' && $expected > $quote['subtotal'] + 0.004) {
            $why[] = "fixed price {$this->mkd($expected)} is above the " . ($isStylist ? 'salon' : 'shop')
                . " prices ({$this->mkd($quote['subtotal'])}), so the cart gives no saving";
        }
        if ($quote['discountCapped']) {
            $why[] = 'cut by the ' . $this->pct($quote['maxDiscountPercentage']) . ' discount cap';
        } elseif ($quote['discountLimited']) {
            $why[] = 'cut by the profit floor (cost + minimum margin)';
        }

        return $why ?: ['cart ' . $this->mkd($quote['subtotal'] - $quote['discount']) . ', card ' . $this->mkd($expected)];
    }

    /**
     * 4. Products in more than one offer or deal: listed in an offer, counted
     * by a deal (its colour lines or its product list) or given as a deal's gift.
     */
    private function overlaps(Collection $bundles): array
    {
        $in = [];
        $add = function (int $productId, Bundle $bundle, string $how) use (&$in) {
            $in[$productId][(string) $bundle->id] = ['bundle' => $bundle, 'how' => $how];
        };

        foreach ($bundles as $bundle) {
            foreach ($bundle->products as $product) {
                $add($product->id, $bundle, $bundle->isMixMatch()
                    ? (Bundle::isGiftLine($product->pivot) ? 'gift' : 'counted')
                    : (Bundle::isGiftLine($product->pivot) ? 'free item' : 'in offer'));
            }
            if ($bundle->isMixMatch()) {
                foreach ($bundle->sortedTiers() as $tier) {
                    foreach ($tier['gift_ids'] as $id) {
                        $add((int) $id, $bundle, "gift at {$tier['min']}");
                    }
                }
            }
        }

        // Shades a deal counts through its colour lines.
        $deals = $bundles->filter(fn (Bundle $b) => $b->isMixMatch());
        $familyDeals = [];
        foreach ($deals as $deal) {
            foreach ($deal->familyIds() as $familyId) {
                $familyDeals[$familyId][] = $deal;
            }
        }
        $result = [];
        if ($familyDeals) {
            // Shades that are also in another offer or deal, one by one.
            $candidates = Product::query()->whereIn('product_family_id', array_keys($familyDeals))
                ->whereIn('id', array_keys($in))->get(['id', 'product_family_id']);
            foreach ($candidates as $product) {
                foreach ($familyDeals[$product->product_family_id] as $deal) {
                    $add($product->id, $deal, 'colour line');
                }
            }
            // A whole colour line counted by two or more deals: one row for the line.
            $lineNames = \App\Models\ProductFamily::query()->whereIn('id', array_keys($familyDeals))->pluck('name', 'id');
            foreach ($familyDeals as $familyId => $lineDeals) {
                if (count($lineDeals) < 2) {
                    continue;
                }
                $result[] = [
                    'productId' => 'line ' . $familyId,
                    'product' => 'every shade of ' . ($lineNames[$familyId] ?? "line #{$familyId}")
                        . ' (' . Product::query()->where('product_family_id', $familyId)->count() . ')',
                    'offers' => collect($lineDeals)->map(fn ($d) => "{$d->name} (#{$d->id}, colour line"
                        . ($d->isRunning() ? '' : ', not running') . ')')->all(),
                    'runningCount' => collect($lineDeals)->filter->isRunning()->count(),
                ];
            }
        }

        $names = Product::query()->whereIn('id', array_keys($in))->pluck('name', 'id');
        foreach ($in as $productId => $offers) {
            if (count($offers) < 2) {
                continue;
            }
            $running = collect($offers)->filter(fn ($o) => $o['bundle']->isRunning());
            $result[] = [
                'productId' => (string) $productId,
                'product' => $names[$productId] ?? "#{$productId}",
                'offers' => collect($offers)->map(fn ($o) => "{$o['bundle']->name} (#{$o['bundle']->id}, {$o['how']}"
                    . ($o['bundle']->isRunning() ? '' : ', not running') . ')')->values()->all(),
                'runningCount' => $running->count(),
            ];
        }
        usort($result, fn ($a, $b) => [$b['runningCount'], $a['product']] <=> [$a['runningCount'], $b['product']]);

        return $result;
    }

    private function wrong(array &$row, string $why): array
    {
        $row['verdict'] = self::WRONG;
        $row['why'][] = $why;

        return $row;
    }

    private function names(array $ids): string
    {
        return Bundle::query()->whereIn('id', $ids)->pluck('name')->implode(', ');
    }

    private function mkd(float $amount): string
    {
        return number_format($amount, abs($amount - round($amount)) < 0.005 ? 0 : 2, '.', ',') . ' MKD';
    }

    private function pct(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.') . '%';
    }

    /** The report as a Markdown page. */
    public static function markdown(array $report, string $title): string
    {
        $money = fn ($v) => $v === null ? '—' : number_format((float) $v, abs($v - round($v)) < 0.005 ? 0 : 2, '.', ',');
        $cell = fn (string $s) => str_replace(['|', "\n"], ['\|', ' '], $s);
        $rows = collect($report['rows']);
        $count = fn ($v) => $rows->where('verdict', $v)->count();

        $out = "# {$title}\n\n";
        $out .= 'Settings: discount cap ' . $report['settings']['maxDiscountPercentage'] . '%, profit floor cost + '
            . $report['settings']['minMarginPercentage'] . '%, estimated cost ' . $report['settings']['costPercentage']
            . "% of the shop price when a product has no cost.\n\n";
        $out .= "**{$count(self::WRONG)} WRONG**, {$count(self::CHECK)} CHECK (under the estimated cost), {$count(self::OK)} OK, {$count(self::SKIP)} not running, {$count(self::NA)} not for that audience.\n\n";
        $out .= "Totals are the goods only (no shipping), in MKD. Expected = the offer card's price worked out by hand; "
            . "real = PricingService::quote. \"No offer\" = same products without choosing the offer "
            . "(for pick-any deals: one shade short of the step).\n\n";

        $out .= "## Price check\n\n| # | Offer | Type | Step | Who | Expected | Real | Diff | No offer | Result | Why / notes |\n|---|---|---|---|---|---:|---:|---:|---:|---|---|\n";
        $order = [self::WRONG => 0, self::CHECK => 1, self::OK => 2, self::NA => 3, self::SKIP => 4];
        foreach ($rows->sortBy(fn ($r) => [$order[$r['verdict']], (int) $r['bundleId']])->values() as $r) {
            $why = implode('; ', $r['why']);
            $notes = implode('; ', $r['notes']);
            $text = trim($why . ($why && $notes ? ' — ' : '') . ($notes ? "_{$notes}_" : ''));
            $out .= "| {$r['bundleId']} | {$cell($r['offer'])} | {$r['type']} | {$cell($r['case'])} | {$r['audience']} | "
                . "{$money($r['expected'])} | {$money($r['real'])} | {$money($r['difference'])} | {$money($r['withoutOffer'])} | "
                . ($r['verdict'] === self::WRONG ? '**WRONG**' : $r['verdict']) . " | {$cell($text)} |\n";
        }

        $out .= "\n## Products in more than one offer or deal\n\n";
        if (! $report['overlaps']) {
            $out .= "None.\n";
        } else {
            $out .= "| Product | Running offers | Offers / deals |\n|---|---:|---|\n";
            foreach ($report['overlaps'] as $o) {
                $out .= "| {$cell($o['product'])} (#{$o['productId']}) | {$o['runningCount']} | {$cell(implode('; ', $o['offers']))} |\n";
            }
        }

        return $out;
    }
}
