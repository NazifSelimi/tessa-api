<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stylist_invitations', function (Blueprint $table) {
            $table->id();
            $table->string('source_reference')->nullable()->unique();
            $table->string('display_name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('business_name')->nullable();
            $table->string('business_address')->nullable();
            $table->string('business_city')->nullable();
            $table->string('business_phone')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('activated_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['activated_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stylist_invitations');
    }
};
