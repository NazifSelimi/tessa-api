<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Analyses every non-hair-color product description and populates the
 * product_hair_type and product_hair_concern pivot tables.
 *
 * Mapping is keyword-based – each product is checked for textual cues
 * in its English description.
 */
class MapProductHairData extends Command
{
    protected $signature = 'products:map-hair-data {--dry : Preview without writing to DB}';
    protected $description = 'Map products to hair types and concerns based on description keywords';

    /*
     * Hair Types  (must match hair_types seeder IDs)
     * 1 Straight, 2 Wavy, 3 Curly, 4 Coily, 5 Fine, 6 Thick, 7 Normal
     *
     * Hair Concerns  (must match hair_concerns seeder IDs)
     * 1 Dryness, 2 Frizz, 3 Breakage, 4 Thinning, 5 Oiliness, 6 Dandruff, 7 Color Protection
     */

    /** keyword → hair_type_id */
    private array $typeKeywords = [
        1 => ['straight'],
        2 => ['wavy', 'wave', 'waves'],
        3 => ['curly', 'curl', 'curls', 'spiral'],
        4 => ['coily', 'coil', 'coils', 'zig-zag', 'zigzag'],
        5 => ['fine hair', 'thin hair', 'fine and', 'fine,'],
        6 => ['thick hair', 'thick and', 'thick,', 'coarse'],
        7 => ['all hair type', 'all types of hair', 'every hair type', 'normal hair', 'any hair type'],
    ];

    /** keyword → hair_concern_id */
    private array $concernKeywords = [
        1 => ['dry', 'dryness', 'dehydrat', 'moisture', 'hydrat', 'nourish', 'parched'],
        2 => ['frizz', 'frizzy', 'flyaway', 'anti-frizz', 'smoothing', 'smooth'],
        3 => ['breakage', 'brittle', 'damage', 'damaged', 'split end', 'restructur', 'repair', 'reconstruct', 'strengthen'],
        4 => ['thin', 'thinning', 'hair loss', 'hair fall', 'volume', 'volumiz', 'densif', 'thicken', 'fuller'],
        5 => ['oily', 'oiliness', 'greasy', 'sebum', 'excess oil', 'purif'],
        6 => ['dandruff', 'flaky', 'itchy scalp', 'scalp care', 'anti-dandruff'],
        7 => ['colour', 'color', 'coloured', 'colored', 'dyed', 'treated hair', 'colour care',
               'color care', 'color protect', 'colour protect', 'color-treated', 'colour-treated'],
    ];

    /**
     * Categories we should skip entirely (not care products).
     * 1=Hair Color, 4=Activator, 8=Bleach and De Color, 9=Tester, 13=Hydrogen Peroxide
     */
    private array $skipCategories = [1, 4, 8, 9, 13];

    public function handle(): int
    {
        $dry = $this->option('dry');
        $products = Product::whereNotIn('category_id', $this->skipCategories)
            ->select('id', 'name', 'description', 'category_id')
            ->get();

        $this->info("Analysing {$products->count()} products…");

        $typeInserts = [];
        $concernInserts = [];
        $stats = ['typed' => 0, 'concerned' => 0, 'skipped' => 0];

        foreach ($products as $product) {
            $desc = strtolower($product->description ?? '');
            $name = strtolower($product->name ?? '');
            $text = $name . ' ' . $desc;

            if (empty(trim($desc))) {
                $stats['skipped']++;
                $this->line("  SKIP #{$product->id} {$product->name} (no description)");
                continue;
            }

            // ── Match hair types ─────────────────────────────────
            $matchedTypes = $this->matchKeywords($text, $this->typeKeywords);

            // If nothing matched explicitly, assign based on category heuristic:
            //   • Styling (12) → all types (7=Normal)
            //   • Shampoo (2), Conditioner (14), Mask (3) with no specific type → all types
            if (empty($matchedTypes)) {
                $matchedTypes = [7]; // Normal = suitable for all
            }

            // ── Match hair concerns ──────────────────────────────
            $matchedConcerns = $this->matchKeywords($text, $this->concernKeywords);

            // Fallback: assign Dryness (1) for nourishing products with no matched concern
            if (empty($matchedConcerns)) {
                // Check if it's at least a care product (shampoo, conditioner, mask, fluid, lotion, spray, styling)
                if (in_array($product->category_id, [2, 3, 5, 6, 7, 10, 11, 12, 14, 15, 16])) {
                    $matchedConcerns = [1]; // default to Dryness
                }
            }

            if (!empty($matchedTypes)) $stats['typed']++;
            if (!empty($matchedConcerns)) $stats['concerned']++;

            $typeNames = $this->idsToNames($matchedTypes, 'type');
            $concernNames = $this->idsToNames($matchedConcerns, 'concern');

            $this->line(sprintf(
                "  #%d %-50s types:[%s]  concerns:[%s]",
                $product->id,
                mb_substr($product->name, 0, 50),
                implode(',', $typeNames),
                implode(',', $concernNames),
            ));

            foreach ($matchedTypes as $typeId) {
                $typeInserts[] = ['product_id' => $product->id, 'hair_type_id' => $typeId];
            }
            foreach ($matchedConcerns as $concernId) {
                $concernInserts[] = ['product_id' => $product->id, 'hair_concern_id' => $concernId];
            }
        }

        $this->newLine();
        $this->info("Summary: {$stats['typed']} typed, {$stats['concerned']} with concerns, {$stats['skipped']} skipped");
        $this->info("Inserts: " . count($typeInserts) . " hair-type links, " . count($concernInserts) . " concern links");

        if ($dry) {
            $this->warn('DRY RUN — no data written.');
            return self::SUCCESS;
        }

        // Truncate existing pivots and insert fresh
        DB::table('product_hair_type')->truncate();
        DB::table('product_hair_concern')->truncate();

        foreach (array_chunk($typeInserts, 500) as $chunk) {
            DB::table('product_hair_type')->insert($chunk);
        }
        foreach (array_chunk($concernInserts, 500) as $chunk) {
            DB::table('product_hair_concern')->insert($chunk);
        }

        $this->info('✅  Done! Pivot tables populated.');

        return self::SUCCESS;
    }

    /**
     * Return all matching IDs whose keywords appear in the text.
     */
    private function matchKeywords(string $text, array $keywordMap): array
    {
        $matched = [];
        foreach ($keywordMap as $id => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($text, $kw)) {
                    $matched[] = $id;
                    break; // one hit is enough for this id
                }
            }
        }
        return array_unique($matched);
    }

    private function idsToNames(array $ids, string $kind): array
    {
        $typeMap = [1 => 'Straight', 2 => 'Wavy', 3 => 'Curly', 4 => 'Coily', 5 => 'Fine', 6 => 'Thick', 7 => 'Normal'];
        $concernMap = [1 => 'Dryness', 2 => 'Frizz', 3 => 'Breakage', 4 => 'Thinning', 5 => 'Oiliness', 6 => 'Dandruff', 7 => 'Color Protection'];
        $map = $kind === 'type' ? $typeMap : $concernMap;
        return array_map(fn($id) => $map[$id] ?? "?{$id}", $ids);
    }
}
