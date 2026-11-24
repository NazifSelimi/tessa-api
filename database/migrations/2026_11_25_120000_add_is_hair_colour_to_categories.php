<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner rule Q11 (#25): an admin ticks which categories are hair colour.
 * "Restock colours" in Quick order shows products of the ticked categories.
 * Nothing is ticked at first; until then a name search is used.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('categories', 'is_hair_colour')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->boolean('is_hair_colour')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('categories', 'is_hair_colour')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropColumn('is_hair_colour');
            });
        }
    }
};
