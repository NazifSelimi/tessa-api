<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\ProductCatalogGuidance;
use Illuminate\Console\Command;

class ClassifyProductCatalog extends Command
{
    protected $signature = 'products:classify-catalog {--dry : Preview without writing changes}';
    protected $description = 'Classify technical products as stylist-only using their category';

    public function handle(): int
    {
        $dry = $this->option('dry');
        $products = Product::with('category')->get();
        $changes = 0;

        foreach ($products as $product) {
            $guidance = ProductCatalogGuidance::forProduct($product->category?->name, $product->name);
            $stylistOnly = $guidance['professionalOnly'];

            if ((bool) $product->stylist_only === $stylistOnly) {
                continue;
            }

            $changes++;
            $this->line(sprintf(
                '#%d %s: stylist_only %s -> %s',
                $product->id,
                $product->name,
                $product->stylist_only ? 'true' : 'false',
                $stylistOnly ? 'true' : 'false',
            ));

            if (! $dry) {
                $product->update(['stylist_only' => $stylistOnly]);
            }
        }

        $this->info(sprintf('%s %d catalog classifications.', $dry ? 'Would update' : 'Updated', $changes));

        return self::SUCCESS;
    }
}
