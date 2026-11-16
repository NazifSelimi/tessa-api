<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\HairConcern;
use App\Models\HairType;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Hair quiz engine.
 *
 * A profile is: hair types (pattern + thickness, several allowed), needs
 * (hair_concerns, several allowed), the routine steps the person wants and an
 * optional budget per product. Every care product says what it does through
 * its needs, which hair types it is made for and which routine step it fills.
 *
 * Rules:
 * - Hair types work per group (pattern / thickness): a product tagged in a
 *   group only suits those values; no tags in a group = suits everyone.
 * - Gated needs (toners, colour masks) are only shown to people who chose
 *   that need: nobody with brown hair gets a violet No Yellow shampoo.
 * - Score = needs it answers (gated ones weigh double) + explicit hair type
 *   match + a small bonus for focused products; Featured always wins.
 * - Sizes and shades of one product collapse into one result.
 * - The routine is the best product for each step the person asked for.
 */
class RecommendationService
{
    /** Values of products.quiz_mode, set by admins on the Hair quiz page. */
    public const QUIZ_MODES = ['auto', 'featured', 'hidden'];

    /** Values of products.quiz_step. */
    public const STEPS = ['shampoo', 'conditioner', 'mask', 'leave_in', 'scalp', 'style'];

    /** Routine steps a person can ask for, and the product steps that fill them. */
    public const ROUTINE_STEPS = [
        'shampoo' => ['shampoo'],
        'condition' => ['mask', 'conditioner'],
        'leave_in' => ['leave_in'],
        'scalp' => ['scalp'],
        'style' => ['style'],
    ];

    /** Product step when products.quiz_step is empty, by category name. */
    private const STEP_BY_CATEGORY = [
        'shampoo' => 'shampoo',
        'conditioner' => 'conditioner',
        'mask' => 'mask',
        'color mask' => 'mask',
        'filler' => 'mask',
        'fluid' => 'leave_in',
        'spray' => 'leave_in',
        'lotion' => 'scalp',
        'styling' => 'style',
    ];

    /** Large enough that a matching featured product always outranks profile-only matches. */
    private const FEATURED_BOOST = 100;

    private const NEED_POINTS = 4;

    private const GATED_NEED_POINTS = 8;

    private const TYPE_POINTS = 2;

    /** Retail size the quiz prefers when a product comes in several sizes. */
    private const PREFERRED_ML = 350;

    private const MAX_PRODUCTS = 16;

    /**
     * @param  list<int>  $hairTypeIds
     * @param  list<int>  $needIds
     * @param  list<string>  $steps  keys of ROUTINE_STEPS; empty = chosen from the needs
     * @return array{products: list<Product>, routine: list<array{step: string, product: Product}>, bundles: Collection, steps: list<string>}
     */
    public function getRecommendations(
        array $hairTypeIds,
        array $needIds,
        array $steps = [],
        ?string $budgetRange = null,
        string $audience = 'customers',
    ): array {
        $hairTypeIds = array_values(array_unique(array_map('intval', $hairTypeIds)));
        $needIds = array_values(array_unique(array_map('intval', $needIds)));
        $steps = $steps === [] ? $this->defaultSteps($needIds) : array_values(array_intersect(array_keys(self::ROUTINE_STEPS), $steps));

        $products = $this->rankedProducts($hairTypeIds, $needIds, $budgetRange, $audience);
        $routine = $this->buildRoutine($products, $steps);

        return [
            // Only products that answer the profile; the routine may also use a
            // plain shampoo/conditioner that simply suits the hair type.
            'products' => $products->filter(fn (Product $p) => $p->recommendation_score > 0)->take(self::MAX_PRODUCTS)->values()->all(),
            'routine' => $routine,
            'bundles' => $this->matchingBundles($hairTypeIds, $needIds, $audience),
            'steps' => $steps,
        ];
    }

    /**
     * Shampoo, conditioner/mask and a leave-in for everyone; a scalp treatment
     * for scalp needs; styling for styling needs.
     *
     * @param  list<int>  $needIds
     * @return list<string>
     */
    public function defaultSteps(array $needIds): array
    {
        $groups = HairConcern::query()->whereIn('id', $needIds)->pluck('group')->unique();
        $steps = ['shampoo', 'condition', 'leave_in'];
        if ($groups->contains('scalp')) {
            $steps[] = 'scalp';
        }
        if ($groups->contains('style')) {
            $steps[] = 'style';
        }

        return $steps;
    }

    /** The routine step a product fills, or null when it is not a care product. */
    public static function stepOf(Product $product): ?string
    {
        if ($product->quiz_step) {
            return $product->quiz_step;
        }

        return self::STEP_BY_CATEGORY[strtolower(trim((string) $product->category?->name))] ?? null;
    }

    /* -------------------------------------------------
     * SCORING
     * ------------------------------------------------- */

    /**
     * Products the admin opened to this audience ('guests', 'customers' or
     * 'stylists'), priced for it and ready for sale.
     */
    protected function restrictToAudience(Builder $query, string $audience): void
    {
        $query->where($query->qualifyColumn('visible_to_' . $audience), true)
            ->pricedFor($audience === 'stylists')
            ->readyForSale();
    }

    /**
     * @param  list<int>  $hairTypeIds
     * @param  list<int>  $needIds
     */
    protected function rankedProducts(array $hairTypeIds, array $needIds, ?string $budgetRange, string $audience): Collection
    {
        $query = Product::query()
            ->with(['brand', 'category', 'translations', 'images', 'sale', 'hairTypes', 'hairConcerns']);

        // Admins can exclude a product from the quiz, and an out-of-stock or
        // unpriced recommendation cannot be bought, so none of them is offered.
        $query->where('quiz_mode', '!=', 'hidden')
            ->where('quantity', '>', 0);
        $this->restrictToAudience($query, $audience);

        if ($budgetRange !== null) {
            [$min, $max] = $this->parseBudgetRange($budgetRange);
            if ($min !== null) {
                $query->where('price', '>=', $min);
            }
            if ($max !== null) {
                $query->where('price', '<=', $max);
            }
        }

        $typeGroups = HairType::query()->pluck('group', 'id');
        $chosenTypesByGroup = collect($hairTypeIds)->groupBy(fn ($id) => $typeGroups[$id] ?? 'pattern');
        $gatedNeeds = HairConcern::query()->where('gated', true)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $scored = $query->get()->map(function (Product $product) use ($hairTypeIds, $needIds, $chosenTypesByGroup, $typeGroups, $gatedNeeds) {
            $step = self::stepOf($product);
            if ($step === null) {
                return null;
            }

            // Hair type fit, per group.
            $productTypesByGroup = $product->hairTypes->pluck('id')->groupBy(fn ($id) => $typeGroups[$id] ?? 'pattern');
            foreach ($productTypesByGroup as $group => $ids) {
                $chosen = $chosenTypesByGroup->get($group);
                if ($chosen !== null && $ids->intersect($chosen)->isEmpty()) {
                    return null;
                }
            }
            $typeMatches = $product->hairTypes->pluck('id')->intersect($hairTypeIds)->count();

            // Needs; gated needs only for people who asked for them.
            $productNeeds = $product->hairConcerns->pluck('id')->map(fn ($id) => (int) $id);
            $productGated = $productNeeds->intersect($gatedNeeds);
            if ($productGated->isNotEmpty() && $productGated->intersect($needIds)->isEmpty()) {
                return null;
            }
            $matched = $productNeeds->intersect($needIds)->values();

            $score = $matched->sum(fn ($id) => in_array($id, $gatedNeeds, true) ? self::GATED_NEED_POINTS : self::NEED_POINTS)
                + $typeMatches * self::TYPE_POINTS;
            if ($matched->isNotEmpty()) {
                // Focused products beat "does everything a little" ones on a tie.
                $score += round(2 * $matched->count() / max(1, $productNeeds->count()), 2);
            }
            if ($product->quiz_mode === 'featured') {
                $score += self::FEATURED_BOOST;
            }

            $product->setAttribute('recommendation_score', $score);
            $product->setAttribute('quiz_step_effective', $step);
            $product->setAttribute('matched_need_ids', $matched->all());
            $product->setAttribute('matched_type_ids', $product->hairTypes->pluck('id')->intersect($hairTypeIds)->map(fn ($id) => (int) $id)->values()->all());

            return $product;
        })->filter();

        // One result per product line: the best-scoring size, then the size
        // closest to a retail bottle; a colour line shows one shade.
        return $scored
            ->groupBy(fn (Product $p) => $p->product_family_id ? 'family:' . $p->product_family_id : 'name:' . $p->brand_id . ':' . self::baseName($p->name))
            ->map(fn (Collection $sizes) => $sizes->sort(fn (Product $a, Product $b) => [$b->recommendation_score, self::sizeDistance($a)] <=> [$a->recommendation_score, self::sizeDistance($b)])->first())
            ->sort(fn (Product $a, Product $b) => [$b->recommendation_score, $a->name] <=> [$a->recommendation_score, $b->name])
            ->values();
    }

    /**
     * Best product per requested step. A step only gets a product that answers
     * at least one need, except shampoo and conditioner/mask, which fall back to
     * the best product that suits the hair type.
     *
     * @param  list<string>  $steps
     * @return list<array{step: string, product: Product}>
     */
    protected function buildRoutine(Collection $ranked, array $steps): array
    {
        $routine = [];
        $used = [];

        foreach ($steps as $step) {
            $fits = $ranked->filter(fn (Product $p) => in_array($p->quiz_step_effective, self::ROUTINE_STEPS[$step], true) && ! isset($used[$p->id]));
            $pick = $fits->first(fn (Product $p) => $p->matched_need_ids !== [])
                ?? (in_array($step, ['shampoo', 'condition'], true) ? $fits->first() : null);

            if ($pick !== null) {
                $used[$pick->id] = true;
                $routine[] = ['step' => $step, 'product' => $pick];
            }
        }

        return $routine;
    }

    /** "Real Argan 1000ml" and "Real Argan 350ml" are one product line. */
    public static function baseName(string $name): string
    {
        $name = preg_replace('/\b\d+\s*x\s*\d+\s*(ml|gr?)\b/i', '', $name);
        $name = preg_replace('/\b\d+([.,]\d+)?\s*(ml|l|gr|g|kg)\b/i', '', (string) $name);

        return strtolower(trim(preg_replace('/[\s\-–]+/', ' ', (string) $name)));
    }

    private static function sizeDistance(Product $product): int
    {
        if (! preg_match('/\b(\d+(?:[.,]\d+)?)\s*(ml|l|gr|g|kg)\b/i', $product->name, $m)) {
            return 0;
        }
        $amount = (float) str_replace(',', '.', $m[1]);
        $ml = in_array(strtolower($m[2]), ['l', 'kg'], true) ? $amount * 1000 : $amount;

        return (int) abs($ml - self::PREFERRED_ML);
    }

    /* -------------------------------------------------
     * BUNDLES
     * ------------------------------------------------- */

    /**
     * Static bundles that contain a product made for these hair types or needs.
     *
     * @param  list<int>  $hairTypeIds
     * @param  list<int>  $needIds
     */
    protected function matchingBundles(array $hairTypeIds, array $needIds, string $audience): Collection
    {
        if ($hairTypeIds === [] && $needIds === []) {
            return collect();
        }

        return Bundle::query()
            ->where('is_dynamic', false)
            ->whereHas('products', function ($q) use ($hairTypeIds, $needIds, $audience) {
                $this->restrictToAudience($q, $audience);

                $q->where(function ($sub) use ($hairTypeIds, $needIds) {
                    $sub->whereHas('hairTypes', fn ($ht) => $ht->whereIn('hair_types.id', $hairTypeIds ?: [0]));
                    if ($needIds !== []) {
                        $sub->orWhereHas('hairConcerns', fn ($hc) => $hc->whereIn('hair_concerns.id', $needIds));
                    }
                });
            })
            ->with([
                'products' => fn ($q) => $this->restrictToAudience($q->getQuery(), $audience),
                'products.images',
                'products.brand',
                'products.category',
            ])
            ->get();
    }

    /* -------------------------------------------------
     * HELPERS
     * ------------------------------------------------- */

    /**
     * Parse a budget range string like "10-50" into [min, max].
     *
     * @return array{0: float|null, 1: float|null}
     */
    protected function parseBudgetRange(string $range): array
    {
        $parts = explode('-', $range, 2);

        $min = isset($parts[0]) && is_numeric($parts[0]) ? (float) $parts[0] : null;
        $max = isset($parts[1]) && is_numeric($parts[1]) ? (float) $parts[1] : null;

        return [$min, $max];
    }
}
