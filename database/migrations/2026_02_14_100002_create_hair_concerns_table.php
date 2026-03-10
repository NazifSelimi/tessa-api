<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hair_concerns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('product_hair_concern', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hair_concern_id')->constrained()->cascadeOnDelete();
            $table->primary(['product_id', 'hair_concern_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_hair_concern');
        Schema::dropIfExists('hair_concerns');
    }
};
