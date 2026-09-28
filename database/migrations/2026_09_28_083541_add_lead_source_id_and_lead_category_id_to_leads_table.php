<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wires `leads.source` / `leads.category` to the SuperAdmin-editable
     * Data Master tables (`lead_sources`, `lead_categories`) via nullable
     * FKs, then backfills them from the existing strings by
     * case-insensitive name match. Any string with no matching master row
     * gets one created, so no lead loses its value.
     *
     * The legacy string columns are intentionally KEPT — LeadService keeps
     * them in sync with the master row's `name`, so every existing reader
     * (CRM dashboard, analytics, exports) keeps working. Dropping them is a
     * separate cleanup once the FK data has been verified.
     *
     * Query builder only (no models, no MySQL-only SQL) so this runs the
     * same on MySQL (local/prod) and SQLite (tests).
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('lead_source_id')->nullable()->after('source');
            $table->foreignId('lead_category_id')->nullable()->after('category');

            $table->index('lead_source_id');
            $table->index('lead_category_id');

            $table->foreign('lead_source_id')->references('id')->on('lead_sources')->nullOnDelete();
            $table->foreign('lead_category_id')->references('id')->on('lead_categories')->nullOnDelete();
        });

        $this->backfill('source', 'lead_source_id', 'lead_sources');
        $this->backfill('category', 'lead_category_id', 'lead_categories');
    }

    public function down(): void
    {
        // Master rows created by the backfill are left in place — they
        // are valid reference data and may be referenced elsewhere by now.
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['lead_source_id']);
            $table->dropForeign(['lead_category_id']);
            $table->dropIndex(['lead_source_id']);
            $table->dropIndex(['lead_category_id']);
            $table->dropColumn(['lead_source_id', 'lead_category_id']);
        });
    }

    private function backfill(string $stringColumn, string $fkColumn, string $masterTable): void
    {
        $values = DB::table('leads')
            ->whereNotNull($stringColumn)
            ->where($stringColumn, '!=', '')
            ->distinct()
            ->pluck($stringColumn);

        foreach ($values as $value) {
            $name = trim((string) $value);

            if ($name === '') {
                continue;
            }

            $masterId = DB::table($masterTable)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->value('id');

            if ($masterId === null) {
                $masterId = DB::table($masterTable)->insertGetId([
                    'name' => $name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('leads')
                ->where($stringColumn, $value)
                ->update([$fkColumn => $masterId]);
        }
    }
};
