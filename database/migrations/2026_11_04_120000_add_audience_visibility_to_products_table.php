<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admins choose who sees each product: guests (not signed in), customers
     * (signed-in shoppers) and stylists/salons. None ticked = hidden from
     * everyone but admins. Replaces the single stylist_only switch, which is
     * kept in step (true when neither guests nor customers may see it).
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('visible_to_guests')->default(true)->index();
            $table->boolean('visible_to_customers')->default(true)->index();
            $table->boolean('visible_to_stylists')->default(true)->index();
        });

        DB::table('products')->where('stylist_only', true)->update([
            'visible_to_guests' => false,
            'visible_to_customers' => false,
        ]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['visible_to_guests']);
            $table->dropIndex(['visible_to_customers']);
            $table->dropIndex(['visible_to_stylists']);
            $table->dropColumn(['visible_to_guests', 'visible_to_customers', 'visible_to_stylists']);
        });
    }
};
