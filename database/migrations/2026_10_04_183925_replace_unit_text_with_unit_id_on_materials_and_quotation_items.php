<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Known units: code => [name, aliases]. Mirrors App\Models\Unit::DEFAULTS
     * (copied, not referenced — a migration must keep meaning the same
     * thing after the model changes).
     */
    private const KNOWN = [
        'pcs' => ['Pieces', ['pc', 'piece', 'pieces', 'buah', 'bh', 'biji']],
        'unit' => ['Unit', ['unt']],
        'set' => ['Set', ['stel']],
        'dus' => ['Dus', ['box', 'kardus', 'karton', 'doos']],
        'lbr' => ['Lembar', ['lembar', 'lmbr', 'lb', 'sheet']],
        'btg' => ['Batang', ['batang']],
        'm' => ['Meter', ['meter', 'mtr', 'm1']],
        'm2' => ['Meter Persegi', ['m²', 'meter persegi', 'mtr2', 'sqm']],
        'm/lari' => ['Meter Lari', ['m lari', 'meter lari', "m'"]],
        'kg' => ['Kilogram', ['kilo', 'kilogram']],
        'sak' => ['Sak', ['zak']],
        'titik' => ['Titik', ['ttk', 'point']],
        'ls' => ['Lumpsum', ['lumpsum', 'lump sum']],
    ];

    /**
     * Sprint 11 Sub 1 — `materials.unit` and `quotation_items.unit` (free
     * text) become `unit_id` FKs into the new Master Satuan. Every distinct
     * legacy text is normalized (trim, lower case, collapsed spaces, common
     * aliases: "Lembar"/"lembar"/"lbr" → `lbr`) and mapped to a units row,
     * created if missing, so no row loses its unit. The text columns are
     * then dropped. `quotation_items.qty` becomes DECIMAL(12,2) — decision
     * #3, fractional quantities (2,5 m).
     *
     * `quotation_revisions.items` snapshots are deliberately untouched:
     * history stays as it was written.
     *
     * Query builder only, so it runs the same on MySQL and SQLite (tests).
     */
    public function up(): void
    {
        foreach (['materials', 'quotation_items'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('unit_id')->nullable()->after('unit')->constrained('units')->restrictOnDelete();
            });

            $this->backfill($table);

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('unit_id')->nullable(false)->change();
                $blueprint->dropColumn('unit');
            });
        }

        Schema::table('quotation_items', function (Blueprint $table) {
            $table->decimal('qty', 12, 2)->default(1)->change();
        });
    }

    /** Restores the text columns from each row's unit code; the units rows themselves stay. */
    public function down(): void
    {
        Schema::table('quotation_items', function (Blueprint $table) {
            $table->unsignedInteger('qty')->default(1)->change();
        });

        foreach (['materials', 'quotation_items'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('unit', 20)->default('')->after('unit_id');
            });

            foreach (DB::table('units')->get(['id', 'code']) as $unit) {
                DB::table($table)->where('unit_id', $unit->id)->update(['unit' => $unit->code]);
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('unit_id');
            });
        }
    }

    private function backfill(string $table): void
    {
        $texts = DB::table($table)->distinct()->pluck('unit');

        foreach ($texts as $text) {
            DB::table($table)->where('unit', $text)->update(['unit_id' => $this->unitIdFor((string) $text)]);
        }
    }

    private function unitIdFor(string $text): int
    {
        [$code, $name] = $this->normalize($text);

        $id = DB::table('units')->where('code', $code)->value('id');

        if ($id !== null) {
            return (int) $id;
        }

        $knownCodes = array_keys(self::KNOWN);
        $position = array_search($code, $knownCodes, true);

        return (int) DB::table('units')->insertGetId([
            'code' => $code,
            'name' => $name,
            'is_active' => true,
            'sort_order' => $position === false ? 100 : $position + 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{0: string, 1: string} [code, name] */
    private function normalize(string $text): array
    {
        $clean = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        $key = mb_strtolower($clean);

        if ($key === '') {
            return ['unit', 'Unit'];
        }

        foreach (self::KNOWN as $code => [$name, $aliases]) {
            if ($key === $code || in_array($key, $aliases, true)) {
                return [$code, $name];
            }
        }

        return [Str::limit($key, 20, ''), Str::limit(Str::title($clean), 50, '')];
    }
};
