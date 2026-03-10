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
        Schema::create('distributor_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stylist_id')->constrained('users')->cascadeOnDelete();
            $table->string('code')->unique();
            $table->integer('discount_percentage')->default(10);
            $table->integer('usage_count')->default(0);
            $table->decimal('total_revenue', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            $table->index('stylist_id');
            $table->index('code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('distributor_codes');
    }
};
