<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin control over the hair quiz: 'auto' (match by hair type/concern),
     * 'featured' (ranked first when it matches) or 'hidden' (never recommended).
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('quiz_mode', 16)->default('auto')->index();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['quiz_mode']);
            $table->dropColumn('quiz_mode');
        });
    }
};
