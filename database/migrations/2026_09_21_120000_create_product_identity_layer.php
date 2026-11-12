<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_lines')) {
            Schema::create('product_lines', function (Blueprint $table) {
                $table->id();
                $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
                $table->string('name');
                $table->string('slug');
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['brand_id', 'slug'], 'product_lines_brand_slug_unique');
            });
        }

        if (! Schema::hasTable('product_families')) {
            Schema::create('product_families', function (Blueprint $table) {
                $table->id();
                $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
                $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
                $table->foreignId('product_line_id')->nullable()->constrained('product_lines')->nullOnDelete();
                $table->string('slug');
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['brand_id', 'slug'], 'product_families_brand_slug_unique');
            });
        }

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (! Schema::hasColumn('products', 'product_family_id')) {
                    $table->foreignId('product_family_id')->nullable()->constrained('product_families')->nullOnDelete();
                }

                foreach ([
                    'sku',
                    'ean',
                    'manufacturer_code',
                    'variant_name',
                    'shade_code',
                    'shade_name',
                    'swatch_hex',
                    'size_value',
                    'size_unit',
                    'package_label',
                ] as $column) {
                    if (! Schema::hasColumn('products', $column)) {
                        $table->string($column)->nullable();
                    }
                }

                foreach (['shade_sort_order', 'sort_order'] as $column) {
                    if (! Schema::hasColumn('products', $column)) {
                        $table->integer($column)->nullable();
                    }
                }

                if (! Schema::hasColumn('products', 'is_active')) {
                    $table->boolean('is_active')->nullable();
                }
            });
        }

        if (Schema::hasTable('items')) {
            Schema::table('items', function (Blueprint $table) {
                foreach ([
                    'product_name_snapshot',
                    'brand_name_snapshot',
                    'sku_snapshot',
                    'ean_snapshot',
                    'manufacturer_code_snapshot',
                    'variant_name_snapshot',
                    'shade_code_snapshot',
                    'shade_name_snapshot',
                    'package_label_snapshot',
                ] as $column) {
                    if (! Schema::hasColumn('items', $column)) {
                        $table->string($column)->nullable();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('items')) {
            $snapshotColumns = array_values(array_filter([
                'product_name_snapshot',
                'brand_name_snapshot',
                'sku_snapshot',
                'ean_snapshot',
                'manufacturer_code_snapshot',
                'variant_name_snapshot',
                'shade_code_snapshot',
                'shade_name_snapshot',
                'package_label_snapshot',
            ], fn (string $column) => Schema::hasColumn('items', $column)));

            if ($snapshotColumns !== []) {
                Schema::table('items', fn (Blueprint $table) => $table->dropColumn($snapshotColumns));
            }
        }

        if (Schema::hasTable('products')) {
            $hasFamily = Schema::hasColumn('products', 'product_family_id');
            $productColumns = array_values(array_filter([
                'sku',
                'ean',
                'manufacturer_code',
                'variant_name',
                'shade_code',
                'shade_name',
                'shade_sort_order',
                'swatch_hex',
                'size_value',
                'size_unit',
                'package_label',
                'sort_order',
                'is_active',
            ], fn (string $column) => Schema::hasColumn('products', $column)));

            if ($hasFamily || $productColumns !== []) {
                Schema::table('products', function (Blueprint $table) use ($hasFamily, $productColumns) {
                    if ($hasFamily) {
                        $table->dropForeign(['product_family_id']);
                        $table->dropColumn('product_family_id');
                    }

                    if ($productColumns !== []) {
                        $table->dropColumn($productColumns);
                    }
                });
            }
        }

        Schema::dropIfExists('product_families');
        Schema::dropIfExists('product_lines');
    }
};
