<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->unsignedTinyInteger('sort_priority')->default(50)->after('name');
        });

        // Seed initial priorities:
        // 0  = show first (Shampoo, Conditioner, Mask, etc.)
        // 50 = default / medium
        // 90 = show last (Hair Color)
        $priorities = [
            'Shampoo'             => 0,
            'Conditioner'         => 0,
            'Mask'                => 0,
            'Fluid'               => 0,
            'Lotion'              => 0,
            'Spray'               => 0,
            'Styling'             => 0,
            'Sets'                => 25,
            'Color Mask'          => 25,
            'Filler'              => 25,
            'Other'               => 25,
            'Tester'              => 25,
            'Activator'           => 70,
            'Hydrogen Peroxide'   => 70,
            'Bleach and De Color' => 70,
            'Hair Color'          => 90,
        ];

        foreach ($priorities as $name => $priority) {
            \Illuminate\Support\Facades\DB::table('categories')
                ->where('name', $name)
                ->update(['sort_priority' => $priority]);
        }
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('sort_priority');
        });
    }
};
