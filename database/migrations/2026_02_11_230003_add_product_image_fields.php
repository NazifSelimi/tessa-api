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
        Schema::table('images', function (Blueprint $table) {
            // Add missing fields for product images
            $table->unsignedBigInteger('product_id')->nullable()->after('id');
            $table->string('url')->nullable()->after('name');
            $table->string('alt')->nullable()->after('url');
            $table->integer('sort_order')->default(0)->after('alt');
            
            // Add foreign key constraint
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('images', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropColumn(['product_id', 'url', 'alt', 'sort_order']);
        });
    }
};
