<?php

namespace App\Console\Commands;

use App\Models\Bundle;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Creates the bundle offers listed in a JSON file (database/data/*.json).
 * Offers whose name already exists are left as they are, so it can run twice.
 * An offer with a product that has no photo or is hidden from its audience is
 * saved as a draft, for an admin to fix and switch on. Names under
 * "deactivate" are switched to draft (not deleted).
 */
class ImportBundleOffers extends Command
{
    protected $signature = 'offers:import {file=database/data/stylist-colour-offers-2026-11.json} {--dry-run : Only show what would be created}';

    protected $description = 'Create bundle offers from a JSON list (skips names that already exist)';

    public function handle(): int
    {
        $path = base_path($this->argument('file'));
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($data) || empty($data['offers'])) {
            $this->error("No offers in {$path}");

            return self::FAILURE;
        }

        // "pool": products a "pick any" offer counts (Silver Star shampoo or mask); "products": its gifts or bundle items.
        foreach ($data['offers'] as &$offer) {
            $offer['products'] = $offer['products'] ?? [];
            $offer['pool'] = $offer['pool'] ?? [];
            $stepGifts = collect($offer['tiers'] ?? [])->flatMap(fn ($tier) => $tier['gift_ids'] ?? [])->unique()
                ->reject(fn ($id) => in_array($id, array_column($offer['products'], 'product_id')));
            // Step gifts are gift choices too, so the shop card can show them.
            foreach ($stepGifts as $id) {
                $offer['products'][] = ['product_id' => $id, 'quantity' => 1];
            }
        }
        unset($offer);

        $ids = collect($data['offers'])->flatMap(fn ($offer) => array_merge(array_column($offer['products'], 'product_id'), $offer['pool']))->unique();
        $products = Product::query()->whereIn('id', $ids)->withCount('images')->get()->keyBy('id');
        $missing = $ids->diff($products->keys());
        if ($missing->isNotEmpty()) {
            $this->error('Unknown product ids: ' . $missing->implode(', '));

            return self::FAILURE;
        }

        $audience = $data['audience'] ?? 'all';
        $visibleColumn = $audience === 'stylist' ? 'visible_to_stylists' : 'visible_to_customers';
        $created = $drafts = $skipped = 0;

        DB::transaction(function () use ($data, $products, $audience, $visibleColumn, &$created, &$drafts, &$skipped) {
            foreach ($data['offers'] as $offer) {
                if (Bundle::query()->where('name', $offer['name'])->exists()) {
                    $skipped++;
                    $this->line("skip  {$offer['name']} (exists)");
                    continue;
                }

                $problems = collect($offer['products'])->pluck('product_id')->merge($offer['pool'])->map(function ($id) use ($products, $visibleColumn) {
                    $product = $products[$id];
                    if (! $product->images_count) return "#{$product->id} no photo";
                    if (! $product->{$visibleColumn}) return "#{$product->id} hidden";

                    return null;
                })->filter();
                $live = $problems->isEmpty() && empty($offer['draft']);
                $live ? $created++ : $drafts++;
                $this->line(($live ? 'live  ' : 'draft ') . $offer['name'] . ($live ? '' : ' (' . ($problems->isEmpty() ? 'set as draft in the file' : $problems->implode(', ')) . ')'));

                if ($this->option('dry-run')) continue;

                $type = $offer['promotion_type'] ?? $data['promotion_type'] ?? 'percentage';
                $bundle = Bundle::create([
                    'name' => $offer['name'],
                    'description' => $offer['description'] ?? null,
                    'audience' => $offer['audience'] ?? $audience,
                    'promotion_type' => $type,
                    // "Pick any colours": the colour line and reward steps; products are the gift choices.
                    'product_family_id' => $type === Bundle::MIX_MATCH ? ($offer['product_family_id'] ?? null) : null,
                    'product_family_ids' => $type === Bundle::MIX_MATCH ? ($offer['product_family_ids'] ?? null) : null,
                    'bundle_price' => $type === 'fixed_price' ? $offer['bundle_price'] : null,
                    'tiers' => $type === Bundle::MIX_MATCH ? ($offer['tiers'] ?? null) : null,
                    'discount_percentage' => $offer['discount_percentage'] ?? $data['discount_percentage'] ?? null,
                    'is_active' => $live,
                    'is_featured' => (bool) ($offer['is_featured'] ?? false),
                    'starts_at' => now(),
                    'ends_at' => $data['ends_at'] ?? null,
                ]);
                $bundle->products()->sync(collect($offer['products'])->mapWithKeys(fn ($line) => [
                    $line['product_id'] => $type === Bundle::MIX_MATCH
                        ? ['quantity' => 1, 'is_bonus' => true, 'bonus_quantity' => 1]
                        : [
                            'quantity' => $line['quantity'],
                            // Free units never exceed the units in the offer, as in the admin form.
                            'is_bonus' => min((int) ($line['bonus_quantity'] ?? 0), (int) $line['quantity']) > 0,
                            'bonus_quantity' => min((int) ($line['bonus_quantity'] ?? 0), (int) $line['quantity']),
                        ],
                ])->union(collect($offer['pool'])->mapWithKeys(fn ($id) => [
                    $id => ['quantity' => 1, 'is_bonus' => false, 'bonus_quantity' => 0],
                ]))->all());
            }
        });

        // Offers this list replaces become drafts (kept, so they can be switched back on).
        $hidden = 0;
        foreach ($data['deactivate'] ?? [] as $name) {
            $query = Bundle::query()->where('name', $name)->where('is_active', true);
            $hidden += $this->option('dry-run') ? $query->count() : $query->update(['is_active' => false]);
        }

        $this->info(($this->option('dry-run') ? 'Dry run: ' : '') . "{$created} live, {$drafts} drafts, {$skipped} already there, {$hidden} older offers set to draft.");

        return self::SUCCESS;
    }
}
