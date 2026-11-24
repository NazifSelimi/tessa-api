<?php

namespace App\Console\Commands;

use App\Support\HairColourCategories;
use Illuminate\Console\Command;

/**
 * Read-only: shows which categories "Restock colours" uses (ticked ones, or
 * what the name search finds while none is ticked), so an admin can tick the
 * right ones in Admin → Categories. Changes nothing.
 */
class ListHairColourCategories extends Command
{
    protected $signature = 'categories:hair-colour';

    protected $description = 'List the categories Restock colours uses (read-only)';

    public function handle(): int
    {
        $fallback = HairColourCategories::usesFallback();
        $this->line($fallback
            ? 'No category is ticked as hair colour yet. The name search finds:'
            : 'Categories ticked as hair colour:');

        $ids = HairColourCategories::ids();
        $rows = \App\Models\Category::query()->whereKey($ids)->withCount('products')->orderBy('name')->get()
            ->map(fn ($category) => [$category->id, $category->name, $category->products_count])->all();
        $this->table(['id', 'name', 'products'], $rows);

        return self::SUCCESS;
    }
}
