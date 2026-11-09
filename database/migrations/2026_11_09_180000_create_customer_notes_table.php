<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The admin's private notes and tags about a customer (admin Customers page).
        // customer_key: "user-12" for an account, "email-ana@x.mk" or "phone-70123456" for a guest buyer.
        Schema::create('customer_notes', function (Blueprint $table) {
            $table->id();
            $table->string('customer_key', 191)->unique();
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->json('tags')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notes');
    }
};
