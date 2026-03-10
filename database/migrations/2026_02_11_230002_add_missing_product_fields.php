<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Add missing product fields
            $table->text('description')->nullable()->after('name');
            $table->string('slug')->unique()->nullable()->after('name');
            $table->string('sku')->unique()->nullable()->after('description');
            $table->string('image')->nullable()->after('stylist_price');
            $table->decimal('compare_at_price', 10, 2)->nullable()->after('price');
            $table->boolean('featured')->default(false)->after('quantity');
            $table->json('tags')->nullable()->after('featured');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'description',
                'slug',
                'sku',
                'image',
                'compare_at_price',
                'featured',
                'tags',
            ]);
        });
    }
};
