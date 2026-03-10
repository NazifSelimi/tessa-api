<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Seeds the hair_types and hair_concerns lookup tables used by the
 * recommendation engine. The IDs here are referenced by the frontend
 * Hair Survey page – keep them in sync.
 */
class HairDataSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        // ── Hair Types (ids 1–7) ────────────────────────────────
        DB::table('hair_types')->upsert([
            ['id' => 1, 'name' => 'Straight',  'description' => 'Little to no curl pattern',       'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Wavy',      'description' => 'Loose, S-shaped waves',           'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'name' => 'Curly',     'description' => 'Defined spiral curls',            'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'name' => 'Coily',     'description' => 'Tight coils or zig-zag pattern',  'created_at' => $now, 'updated_at' => $now],
            ['id' => 5, 'name' => 'Fine',      'description' => 'Thin individual strands',         'created_at' => $now, 'updated_at' => $now],
            ['id' => 6, 'name' => 'Thick',     'description' => 'Wide individual strands',         'created_at' => $now, 'updated_at' => $now],
            ['id' => 7, 'name' => 'Normal',    'description' => 'Medium strand width',             'created_at' => $now, 'updated_at' => $now],
        ], ['id'], ['name', 'description', 'updated_at']);

        // ── Hair Concerns (ids 1–7) ─────────────────────────────
        DB::table('hair_concerns')->upsert([
            ['id' => 1, 'name' => 'Dryness',           'description' => 'Hair feels rough or straw-like',           'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Frizz',             'description' => 'Flyaways and lack of smoothness',          'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'name' => 'Breakage',          'description' => 'Split ends, breakage from heat or chemicals', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'name' => 'Thinning',          'description' => 'Hair loss or reduced volume',              'created_at' => $now, 'updated_at' => $now],
            ['id' => 5, 'name' => 'Oiliness',          'description' => 'Greasy roots, flat hair',                  'created_at' => $now, 'updated_at' => $now],
            ['id' => 6, 'name' => 'Dandruff',          'description' => 'Flaky, itchy scalp',                       'created_at' => $now, 'updated_at' => $now],
            ['id' => 7, 'name' => 'Color Protection',  'description' => 'Keep color vibrant and lasting',           'created_at' => $now, 'updated_at' => $now],
        ], ['id'], ['name', 'description', 'updated_at']);
    }
}
