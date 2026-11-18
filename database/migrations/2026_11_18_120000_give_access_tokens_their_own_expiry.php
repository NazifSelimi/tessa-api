<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Tokens used to expire through one global 24-hour rule. That rule is gone,
 * so tokens without their own expiry keep their old end (created + 1 day);
 * tokens still in use then get renewed by App\Support\AccessTokens.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('personal_access_tokens')
            ->whereNull('expires_at')
            ->update(['expires_at' => DB::raw('DATE_ADD(created_at, INTERVAL 1 DAY)')]);
    }

    public function down(): void
    {
        // Nothing to undo: with the old 24-hour rule these expiries are equivalent.
    }
};
