<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bundle_products', function (Blueprint $table) {
            $table->unsignedInteger('bonus_quantity')->default(0)->after('quantity');
        });

        DB::table('bundle_products')->where('is_bonus', true)->update([
            'bonus_quantity' => DB::raw('quantity'),
        ]);
    }

    public function down(): void
    {
        Schema::table('bundle_products', function (Blueprint $table) {
            $table->dropColumn('bonus_quantity');
        });
    }
};
