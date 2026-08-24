<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            $table->string('audience')->default('all')->after('description');
            $table->string('promotion_type')->default('percentage')->after('audience');
            $table->decimal('bundle_price', 10, 2)->nullable()->after('discount_percentage');
            $table->boolean('is_active')->default(true)->after('bundle_price');
            $table->boolean('is_featured')->default(false)->after('is_active');
            $table->timestamp('starts_at')->nullable()->after('is_featured');
            $table->timestamp('ends_at')->nullable()->after('starts_at');
        });

        Schema::table('bundle_products', function (Blueprint $table) {
            $table->boolean('is_bonus')->default(false)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('bundle_products', function (Blueprint $table) {
            $table->dropColumn('is_bonus');
        });

        Schema::table('bundles', function (Blueprint $table) {
            $table->dropColumn([
                'audience',
                'promotion_type',
                'bundle_price',
                'is_active',
                'is_featured',
                'starts_at',
                'ends_at',
            ]);
        });
    }
};
