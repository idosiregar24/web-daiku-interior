<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 32 — "Tingkatan Karir": the default weight of each position in a
 * Tukang team (File D `Upah Tim Interior DAIKU.xlsx`, sheet "Master
 * Persentasi"). `is_company` = the company's share, kept out of the team's
 * pay. Master data — the rows are inserted here so production gets them too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('career_levels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->decimal('weight', 6, 2);
            $table->boolean('is_company')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('career_levels')->insert(array_map(fn (array $row, int $index) => [
            'name' => $row[0], 'weight' => $row[1], 'is_company' => $row[2], 'sort_order' => $index, 'created_at' => $now, 'updated_at' => $now,
        ], [
            ['Ketua Tim', 24, false],
            ['Tukang 1', 17, false],
            ['Tukang 2', 15, false],
            ['Helper 1', 9, false],
            ['Helper 2', 7, false],
            ['Perusahaan', 5, true],
        ], range(1, 6)));
    }

    public function down(): void
    {
        Schema::dropIfExists('career_levels');
    }
};
