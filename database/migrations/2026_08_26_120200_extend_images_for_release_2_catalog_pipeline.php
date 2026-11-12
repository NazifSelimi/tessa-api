<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('images')) {
            return;
        }

        Schema::table('images', function (Blueprint $table) {
            if (!Schema::hasColumn('images', 'variant')) {
                $table->string('variant')->default('legacy')->after('sort_order');
            }

            if (!Schema::hasColumn('images', 'background')) {
                $table->string('background')->nullable()->after('variant');
            }

            if (!Schema::hasColumn('images', 'review_status')) {
                $table->string('review_status')->nullable()->after('background');
            }

            if (!Schema::hasColumn('images', 'metadata')) {
                $table->json('metadata')->nullable()->after('review_status');
            }
        });

        DB::table('images')
            ->whereNull('variant')
            ->update(['variant' => 'legacy']);
    }

    public function down(): void
    {
        if (!Schema::hasTable('images')) {
            return;
        }

        Schema::table('images', function (Blueprint $table) {
            $drops = [];

            foreach (['variant', 'background', 'review_status', 'metadata'] as $column) {
                if (Schema::hasColumn('images', $column)) {
                    $drops[] = $column;
                }
            }

            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });
    }
};
