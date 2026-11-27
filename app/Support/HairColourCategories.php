<?php

namespace App\Support;

use App\Models\Category;
use Illuminate\Support\Facades\Schema;

/**
 * Which categories hold hair colour, for "Restock colours" in Quick order
 * (owner rule Q11, #25 and NEW-5).
 *
 * Categories an admin ticked as "Is hair colour" in Admin → Categories. While
 * none is ticked, a name search in the shop's languages is used instead:
 * colour words (color, colour, боја, бои, ngjyr…), but not care or protection
 * products ("Color Protection", colour masks, bleach / de-colour).
 */
final class HairColourCategories
{
    private const COLOUR = '/colou?r|боја|бои|ngjyr/iu';

    private const NOT_COLOUR = '/protect|care|mask|bleach|de[\s-]?colou?r|заштит|нега|маска|mbrojt|kujdes|mask[eë]/iu';

    /** @return array<int, int> */
    public static function ids(): array
    {
        if (Schema::hasColumn('categories', 'is_hair_colour')) {
            $ticked = Category::query()->where('is_hair_colour', true)->pluck('id')->all();
            if ($ticked) {
                return array_map('intval', $ticked);
            }
        }

        return self::byName()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** True while no category is ticked and the name search decides. */
    public static function usesFallback(): bool
    {
        return ! Schema::hasColumn('categories', 'is_hair_colour')
            || ! Category::query()->where('is_hair_colour', true)->exists();
    }

    /** Categories the name search finds. */
    public static function byName()
    {
        return Category::query()->orderBy('name')->get(['id', 'name'])
            ->filter(fn (Category $category) => self::looksLikeColour((string) $category->name))
            ->values();
    }

    public static function looksLikeColour(string $name): bool
    {
        return preg_match(self::COLOUR, $name) === 1 && preg_match(self::NOT_COLOUR, $name) !== 1;
    }
}
