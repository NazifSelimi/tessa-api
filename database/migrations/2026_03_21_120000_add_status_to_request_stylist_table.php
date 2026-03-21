<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('request_stylist', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->after('message');
            $table->text('rejection_reason')->nullable()->after('status');
        });

        DB::table('request_stylist')->update([
            'status' => 'pending',
        ]);
    }

    public function down(): void
    {
        Schema::table('request_stylist', function (Blueprint $table) {
            $table->dropColumn(['status', 'rejection_reason']);
        });
    }
};
