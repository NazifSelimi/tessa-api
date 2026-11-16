<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hair quiz v2.
 *
 * - hair_types get a slug and a group: 'pattern' (straight … coily) or
 *   'thickness' (fine / normal / thick). A product tagged in a group only
 *   suits those values of that group; no tags in a group = suits all.
 * - hair_concerns ("needs") get a slug, a group (lengths / scalp / colour /
 *   style), a sort order and `gated`: a gated need (toners, colour masks) is
 *   only recommended to people who asked for it.
 * - products.quiz_step says which routine step a product fills (shampoo,
 *   conditioner, mask, leave_in, scalp, style); null = taken from the category.
 */
return new class extends Migration
{
    private const TYPES = [
        1 => ['straight', 'pattern'], 2 => ['wavy', 'pattern'], 3 => ['curly', 'pattern'], 4 => ['coily', 'pattern'],
        5 => ['fine', 'thickness'], 6 => ['thick', 'thickness'], 7 => ['normal', 'thickness'],
    ];

    /** id => [slug, group, gated, name, description] */
    private const NEEDS = [
        1 => ['dryness', 'lengths', false, 'Dryness', 'Hair feels rough or straw-like'],
        2 => ['frizz', 'lengths', false, 'Frizz', 'Flyaways and lack of smoothness'],
        3 => ['breakage', 'lengths', false, 'Breakage & split ends', 'Split ends, breakage from heat or chemicals'],
        4 => ['hair_loss', 'scalp', false, 'Hair loss & thinning', 'Hair falls out or grows weak'],
        5 => ['oiliness', 'scalp', false, 'Oily scalp', 'Greasy roots, hair gets flat fast'],
        6 => ['dandruff', 'scalp', false, 'Dandruff & itchy scalp', 'Flaky, itchy scalp'],
        7 => ['color_protection', 'colour', false, 'Colour protection', 'Keep colour vibrant and lasting'],
        8 => ['volume', 'style', false, 'Volume', 'Flat, limp hair that needs lift'],
        9 => ['brassy_yellow', 'colour', true, 'Yellow tones', 'Blonde, grey or white hair turning yellow'],
        10 => ['brassy_orange', 'colour', true, 'Orange tones', 'Brown or dark blonde hair turning brassy orange'],
        11 => ['brassy_red', 'colour', true, 'Unwanted red tones', 'Brown hair showing red reflections'],
        12 => ['curl_definition', 'style', false, 'Curl definition', 'Defined, bouncy curls and waves'],
        13 => ['shine', 'lengths', false, 'Dull hair', 'Lacks shine and softness'],
        14 => ['heat_protection', 'style', false, 'Heat protection', 'Uses hair dryer, straightener or curling iron'],
        15 => ['bond_repair', 'lengths', false, 'Bleached or chemically treated', 'Weakened by bleach, colour or perm'],
        16 => ['color_refresh', 'colour', true, 'Refresh my colour', 'Boost or tint colour between salon visits'],
        17 => ['hold', 'style', false, 'Hold', 'Style that stays in place'],
        18 => ['texture', 'style', false, 'Texture & matte finish', 'Wax or paste for short hair and texture'],
    ];

    public function up(): void
    {
        Schema::table('hair_types', function (Blueprint $table) {
            $table->string('slug', 40)->nullable()->unique()->after('name');
            $table->string('group', 20)->default('pattern')->after('slug');
        });

        Schema::table('hair_concerns', function (Blueprint $table) {
            $table->string('slug', 40)->nullable()->unique()->after('name');
            $table->string('group', 20)->default('lengths')->after('slug');
            $table->boolean('gated')->default(false)->after('group');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('gated');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('quiz_step', 20)->nullable()->after('quiz_mode');
        });

        // Only fill the lookup rows when this is the real catalogue (ids 1–7
        // exist); the empty test database creates its own rows.
        if (! DB::table('hair_types')->where('id', 1)->exists()) {
            return;
        }

        $now = now();
        foreach (self::TYPES as $id => [$slug, $group]) {
            DB::table('hair_types')->where('id', $id)->update(['slug' => $slug, 'group' => $group]);
        }
        foreach (self::NEEDS as $id => [$slug, $group, $gated, $name, $description]) {
            DB::table('hair_concerns')->upsert([[
                'id' => $id, 'name' => $name, 'description' => $description, 'slug' => $slug,
                'group' => $group, 'gated' => $gated, 'sort_order' => $id, 'created_at' => $now, 'updated_at' => $now,
            ]], ['id'], ['name', 'description', 'slug', 'group', 'gated', 'sort_order', 'updated_at']);
        }
    }

    public function down(): void
    {
        DB::table('product_hair_concern')->where('hair_concern_id', '>', 7)->delete();
        DB::table('hair_concerns')->where('id', '>', 7)->delete();

        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('quiz_step'));
        Schema::table('hair_concerns', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'group', 'gated', 'sort_order']);
        });
        Schema::table('hair_types', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'group']);
        });
    }
};
