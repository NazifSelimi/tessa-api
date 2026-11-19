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
 * saved as a draft, for an admin to fix and switch on.
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

        $ids = collect($data['offers'])->flatMap(fn ($offer) => array_column($offer['products'], 'product_id'))->unique();
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

                $problems = collect($offer['products'])->map(function ($line) use ($products, $visibleColumn) {
                    $product = $products[$line['product_id']];
                    if (! $product->images_count) return "#{$product->id} no photo";
                    if (! $product->{$visibleColumn}) return "#{$product->id} hidden";

                    return null;
                })->filter();
                $live = $problems->isEmpty();
                $live ? $created++ : $drafts++;
                $this->line(($live ? 'live  ' : 'draft ') . $offer['name'] . ($live ? '' : ' (' . $problems->implode(', ') . ')'));

                if ($this->option('dry-run')) continue;

                $bundle = Bundle::create([
                    'name' => $offer['name'],
                    'description' => $offer['description'] ?? null,
                    'audience' => $audience,
                    'promotion_type' => $data['promotion_type'] ?? 'percentage',
                    'discount_percentage' => $data['discount_percentage'] ?? null,
                    'is_active' => $live,
                    'is_featured' => false,
                    'starts_at' => now(),
                    'ends_at' => $data['ends_at'] ?? null,
                ]);
                $bundle->products()->sync(collect($offer['products'])->mapWithKeys(fn ($line) => [
                    $line['product_id'] => ['quantity' => $line['quantity'], 'is_bonus' => false, 'bonus_quantity' => 0],
                ])->all());
            }
        });

        $this->info(($this->option('dry-run') ? 'Dry run: ' : '') . "{$created} live, {$drafts} drafts, {$skipped} already there.");

        return self::SUCCESS;
    }
}
