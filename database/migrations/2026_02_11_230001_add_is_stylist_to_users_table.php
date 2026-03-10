<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Add is_stylist column if it doesn't exist
            if (!Schema::hasColumn('users', 'is_stylist')) {
                $table->boolean('is_stylist')->default(false)->after('role');
            }
        });

        // Sync is_stylist with role for existing users
        DB::statement('UPDATE users SET is_stylist = (role = 2) WHERE role = 2');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'is_stylist')) {
                $table->dropColumn('is_stylist');
            }
        });
    }
};
