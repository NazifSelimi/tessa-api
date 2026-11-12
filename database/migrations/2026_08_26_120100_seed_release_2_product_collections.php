<?php

use App\Support\ProductCollectionMatcher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            !Schema::hasTable('product_collections')
            || !Schema::hasTable('product_collection_product')
            || !Schema::hasTable('products')
            || !Schema::hasTable('brands')
            || !Schema::hasTable('categories')
        ) {
            return;
        }

        $now = now();

        DB::table('product_collections')->upsert(
            collect(ProductCollectionMatcher::definitions())
                ->map(fn (array $definition) => [
                    ...collect($definition)->except([
                        'default_routine_roles',
                        'supported_category_names',
                    ])->all(),
                    'default_routine_roles' => json_encode($definition['default_routine_roles']),
                    'supported_category_names' => json_encode($definition['supported_category_names']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
                ->all(),
            ['slug'],
            ['name', 'title', 'description', 'sort_priority', 'is_active', 'default_routine_roles', 'supported_category_names', 'updated_at']
        );

        $blondeAndToneId = DB::table('product_collections')
            ->where('slug', 'blonde-and-tone')
            ->value('id');

        if (!$blondeAndToneId) {
            return;
        }

        $products = DB::table('products')
            ->join('brands', 'brands.id', '=', 'products.brand_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->select([
                'products.id as product_id',
                'products.name as product_name',
                'brands.name as brand_name',
                'categories.name as category_name',
            ])
            ->orderBy('products.id')
            ->get();

        foreach ($products as $product) {
            $mapping = ProductCollectionMatcher::matchBlondeAndTone(
                $product->brand_name,
                $product->category_name,
                $product->product_name
            );

            if ($mapping === null) {
                continue;
            }

            $exists = DB::table('product_collection_product')
                ->where('product_collection_id', $blondeAndToneId)
                ->where('product_id', $product->product_id)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('product_collection_product')->insert([
                'product_collection_id' => $blondeAndToneId,
                'product_id' => $product->product_id,
                'mapping_status' => $mapping['mapping_status'],
                'source' => $mapping['source'],
                'notes' => $mapping['notes'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Launch collection data is business data and should not be blindly reversed.
    }
};
