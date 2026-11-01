<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A colour line's brand catalogue: page images (public disk paths) and the
     * shade spots on each page, as fractions of the page:
     * {"title": "...", "pages": [{"image": "...", "width": 1600, "height": 3146,
     *   "spots": [{"code": "7.03", "x": 0.1, "y": 0.2, "w": 0.1, "h": 0.03}]}]}
     */
    public function up(): void
    {
        Schema::table('product_families', function (Blueprint $table) {
            $table->json('catalogue')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('product_families', function (Blueprint $table) {
            $table->dropColumn('catalogue');
        });
    }
};
