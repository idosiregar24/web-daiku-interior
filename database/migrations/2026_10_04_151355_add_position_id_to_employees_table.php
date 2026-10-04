<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SDM (Sprint 10, decision #10): `employees.position` (free text) becomes
 * `position_id` → positions. Existing texts are backfilled into positions
 * under a placeholder division "Belum Dikelompokkan" (HR moves them to the
 * right division afterwards), then the text column is dropped.
 */
return new class extends Migration
{
    private const PLACEHOLDER_DIVISION = 'Belum Dikelompokkan';

    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('position_id')->nullable()->after('name')->constrained()->restrictOnDelete();
        });

        $names = DB::table('employees')->distinct()->pluck('position')
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique(fn (string $name) => mb_strtolower($name))
            ->values();

        if ($names->isNotEmpty()) {
            $now = now();
            $divisionId = DB::table('divisions')->where('name', self::PLACEHOLDER_DIVISION)->value('id')
                ?? DB::table('divisions')->insertGetId([
                    'name' => self::PLACEHOLDER_DIVISION,
                    'is_active' => true,
                    'sort_order' => 999,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            foreach ($names as $name) {
                $positionId = DB::table('positions')->insertGetId([
                    'division_id' => $divisionId,
                    'name' => $name,
                    'is_active' => true,
                    'sort_order' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                // Case/whitespace variants of the same text land on one position.
                DB::table('employees')
                    ->whereRaw('LOWER(TRIM(position)) = ?', [mb_strtolower($name)])
                    ->update(['position_id' => $positionId]);
            }
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('position', 100)->default('')->after('name');
        });

        foreach (DB::table('positions')->get(['id', 'name']) as $position) {
            DB::table('employees')->where('position_id', $position->id)->update(['position' => $position->name]);
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('position_id');
        });
    }
};
