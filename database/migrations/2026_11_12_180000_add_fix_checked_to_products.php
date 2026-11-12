<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix products: when an admin marks a product as checked, remember when and
 * which problem flags it had, so it stays off the list until a new problem appears.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('fix_checked_at')->nullable();
            $table->json('fix_checked_codes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['fix_checked_at', 'fix_checked_codes']);
        });
    }
};
