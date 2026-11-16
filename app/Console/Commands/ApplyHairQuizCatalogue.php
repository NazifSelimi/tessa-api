<?php

namespace App\Console\Commands;

use App\Models\HairConcern;
use App\Models\HairType;
use App\Models\Product;
use App\Services\RecommendationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Writes database/data/hair-quiz-catalogue.json into the products: routine
 * step, hair type tags and needs (replacing the old tags of listed products),
 * and hides the listed salon-only items from the quiz. An admin's Featured or
 * Hidden choice on a listed product is kept. Unknown product ids are reported and skipped.
 */
class ApplyHairQuizCatalogue extends Command
{
    protected $signature = 'quiz:apply-catalogue {--dry-run : Only report what would change}';

    protected $description = 'Apply the hair quiz product classification (steps, hair types, needs)';

    public function handle(): int
    {
        $file = database_path('data/hair-quiz-catalogue.json');
        $catalogue = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        $types = HairType::query()->whereNotNull('slug')->pluck('id', 'slug');
        $needs = HairConcern::query()->whereNotNull('slug')->pluck('id', 'slug');
        $dry = (bool) $this->option('dry-run');
        $missing = [];
        $changed = 0;

        DB::transaction(function () use ($catalogue, $types, $needs, $dry, &$missing, &$changed) {
            foreach ($catalogue['products'] as $id => $entry) {
                $product = Product::query()->find((int) $id);
                if (! $product) {
                    $missing[] = $id;
                    continue;
                }
                if (! in_array($entry['step'], RecommendationService::STEPS, true)) {
                    throw new \RuntimeException("Unknown step {$entry['step']} for product {$id}");
                }
                $typeIds = array_map(fn ($slug) => $types[$slug] ?? throw new \RuntimeException("Unknown hair type {$slug}"), $entry['types']);
                $needIds = array_map(fn ($slug) => $needs[$slug] ?? throw new \RuntimeException("Unknown need {$slug}"), $entry['needs']);
                $changed++;
                if ($dry) {
                    continue;
                }
                $product->quiz_step = $entry['step'];
                $product->save();
                $product->hairTypes()->sync($typeIds);
                $product->hairConcerns()->sync($needIds);
            }

            foreach (array_keys($catalogue['hidden'] ?? []) as $id) {
                $product = Product::query()->find((int) $id);
                if (! $product) {
                    $missing[] = $id;
                    continue;
                }
                $changed++;
                if (! $dry) {
                    $product->quiz_mode = 'hidden';
                    $product->save();
                }
            }
        });

        $this->info(($dry ? 'Would update ' : 'Updated ') . $changed . ' products.');
        if ($missing !== []) {
            $this->warn('Not found (skipped): ' . implode(', ', $missing));
        }

        return self::SUCCESS;
    }
}
