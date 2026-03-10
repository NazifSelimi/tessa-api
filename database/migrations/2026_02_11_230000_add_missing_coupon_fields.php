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
        Schema::table('coupons', function (Blueprint $table) {
            // Add missing fields for coupon functionality
            $table->decimal('min_purchase', 10, 2)->nullable()->after('value');
            $table->decimal('max_discount', 10, 2)->nullable()->after('min_purchase');
            $table->integer('usage_limit')->nullable()->after('max_discount');
            $table->integer('used_count')->default(0)->after('usage_limit');
            $table->date('start_date')->nullable()->after('used_count');
            $table->date('end_date')->nullable()->after('start_date');
            $table->boolean('is_active')->default(true)->after('end_date');
            $table->string('description', 500)->nullable()->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn([
                'min_purchase',
                'max_discount',
                'usage_limit',
                'used_count',
                'start_date',
                'end_date',
                'is_active',
                'description',
            ]);
        });
    }
};
