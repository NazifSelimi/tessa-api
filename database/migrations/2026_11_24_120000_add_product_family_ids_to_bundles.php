<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Pick any" offers that count several colour lines together (e.g. salon
 * offer: 30 tubes of Fanola, Oro Therapy, RR Line or No Yellow colour = free
 * brush). product_family_id stays the main line; these are the extra ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            $table->json('product_family_ids')->nullable()->after('product_family_id');
        });
    }

    public function down(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            $table->dropColumn('product_family_ids');
        });
    }
};
