<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('product_collections')) {
            Schema::create('product_collections', function (Blueprint $table) {
                $table->id();
                $table->string('slug')->unique();
                $table->string('name');
                $table->string('title');
                $table->text('description')->nullable();
                $table->unsignedInteger('sort_priority')->default(0);
                $table->boolean('is_active')->default(true);
                $table->json('default_routine_roles')->nullable();
                $table->json('supported_category_names')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('product_collection_product')) {
            Schema::create('product_collection_product', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_collection_id')->constrained('product_collections')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
                $table->string('mapping_status')->default('confirmed');
                $table->string('source')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['product_collection_id', 'product_id'], 'product_collection_product_unique');
                $table->index(['mapping_status', 'product_id'], 'product_collection_product_status_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_collection_product');
        Schema::dropIfExists('product_collections');
    }
};
