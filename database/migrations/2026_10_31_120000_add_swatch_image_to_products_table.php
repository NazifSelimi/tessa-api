<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Small hair-swatch photo shown in a colour line's shade picker
     * (path on the public disk, e.g. "swatches/123.jpg").
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('swatch_image')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('swatch_image');
        });
    }
};
