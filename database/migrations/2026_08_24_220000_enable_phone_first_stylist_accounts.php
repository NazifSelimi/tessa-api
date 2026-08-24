<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone_login', 32)->nullable()->unique()->after('phone');
            $table->string('email')->nullable()->change();
        });

        Schema::table('stylist_invitations', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->after('activated_at');
        });
    }

    public function down(): void
    {
        Schema::table('stylist_invitations', function (Blueprint $table) {
            $table->dropColumn('revoked_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone_login']);
            $table->dropColumn('phone_login');
            $table->string('email')->nullable(false)->change();
        });
    }
};
