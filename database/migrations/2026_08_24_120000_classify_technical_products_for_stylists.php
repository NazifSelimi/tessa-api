<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PROFESSIONAL_CATEGORIES = [
        'Hair Color',
        'Activator',
        'Hydrogen Peroxide',
        'Bleach and De Color',
        'Tester',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasTable('categories')) {
            return;
        }

        $categoryIds = DB::table('categories')
            ->whereIn('name', self::PROFESSIONAL_CATEGORIES)
            ->pluck('id');

        DB::table('products')
            ->whereIn('category_id', $categoryIds)
            ->update(['stylist_only' => true]);
    }

    public function down(): void
    {
        // Audience classification is business data and should not be blindly reversed.
    }
};
