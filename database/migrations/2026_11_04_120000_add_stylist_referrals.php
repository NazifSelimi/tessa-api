<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A coupon with a stylist is that stylist's referral code: clients get its
        // discount, the stylist earns commission_percentage as store credit.
        Schema::table('coupons', function (Blueprint $table) {
            $table->foreignId('stylist_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->decimal('commission_percentage', 5, 2)->nullable();
            $table->boolean('first_order_only')->default(false);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('referral_stylist_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('referral_commission', 10, 2)->default(0);
            $table->decimal('store_credit_used', 10, 2)->default(0);
        });

        // Ledger: a user's balance is the sum of their rows. Rows are only ever
        // added (earned/reversed, spent/refunded, admin adjustments).
        Schema::create('store_credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);
            $table->decimal('amount', 10, 2);
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_credit_transactions');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referral_stylist_id');
            $table->dropColumn(['referral_commission', 'store_credit_used']);
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stylist_id');
            $table->dropColumn(['commission_percentage', 'first_order_only']);
        });
    }
};
