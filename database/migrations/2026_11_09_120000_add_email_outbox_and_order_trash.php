<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Emails written by admins to many people at once.
        Schema::create('email_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('subject');
            $table->string('audience', 40);
            $table->string('audience_label')->nullable();
            $table->json('content')->nullable();
            $table->unsignedInteger('recipients_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Every email the shop sends, with its status, so failures can be seen and retried.
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 60)->index();
            $table->string('to_email');
            $table->string('to_name')->nullable();
            $table->string('subject')->nullable();
            $table->string('status', 20)->default('queued')->index(); // queued, retrying, sent, failed
            $table->string('delivery', 20)->nullable(); // smtp, log, redirected
            $table->string('redirected_to')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->longText('payload')->nullable();
            $table->foreignId('order_id')->nullable()->index();
            $table->foreignId('user_id')->nullable()->index();
            $table->foreignId('campaign_id')->nullable()->constrained('email_campaigns')->nullOnDelete();
            $table->foreignId('created_by')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        // People who unsubscribed from emails written by the shop (order emails still arrive).
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('email_opt_out_at')->nullable();
        });

        // Orders go to a Trash first; they can be restored or deleted forever from there.
        Schema::table('orders', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropSoftDeletes());
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('email_opt_out_at'));
        Schema::dropIfExists('email_logs');
        Schema::dropIfExists('email_campaigns');
    }
};
