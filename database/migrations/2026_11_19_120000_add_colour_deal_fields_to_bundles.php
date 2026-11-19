<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Pick any colours" offers (promotion_type mix_match): the colour line the
 * shades come from and the reward steps, e.g. 10 colours → free oxidant,
 * 30 colours → 3 free colours + oxidant. The bundle's products are the gift
 * choices (oxidants / activators).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            $table->foreignId('product_family_id')->nullable()->after('promotion_type')->constrained('product_families')->nullOnDelete();
            $table->json('tiers')->nullable()->after('product_family_id');
        });
    }

    public function down(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_family_id');
            $table->dropColumn('tiers');
        });
    }
};
