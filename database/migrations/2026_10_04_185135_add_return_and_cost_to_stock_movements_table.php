<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 11 Sub 3 — the stock ledger learns RETURN (a project's
     * leftover going back into stock; `project_id` = the project it came
     * from), links each OUT/RETURN to its project material line, and
     * snapshots `unit_cost`: for OUT that's the warehouse price the
     * project is charged (decision #5), copied so a later catalog price
     * change never rewrites a recorded cost. Quantities become
     * DECIMAL(12,2). `type` is already a plain string, so RETURN needs no
     * schema change there.
     *
     * Backfill: existing OUT rows get their project line (the one GUDANG
     * line per project+material) and the material's current price.
     */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('project_material_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
            $table->decimal('unit_cost', 15, 2)->nullable()->after('stock_after');
            $table->decimal('qty', 12, 2)->change();
            $table->decimal('stock_after', 12, 2)->change();
        });

        $outs = DB::table('stock_movements')
            ->join('materials', 'materials.id', '=', 'stock_movements.material_id')
            ->where('stock_movements.type', 'OUT')
            ->get(['stock_movements.id', 'stock_movements.project_id', 'stock_movements.material_id', 'materials.cost_price']);

        foreach ($outs as $out) {
            DB::table('stock_movements')->where('id', $out->id)->update([
                'unit_cost' => $out->cost_price,
                'project_material_id' => DB::table('project_materials')
                    ->where('project_id', $out->project_id)
                    ->where('material_id', $out->material_id)
                    ->where('source', 'GUDANG')
                    ->value('id'),
            ]);
        }
    }

    /** RETURN rows have no meaning in the old ledger and are removed; fractions are truncated. */
    public function down(): void
    {
        DB::table('stock_movements')->where('type', 'RETURN')->delete();

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_material_id');
            $table->dropColumn('unit_cost');
            $table->unsignedInteger('qty')->change();
            $table->unsignedInteger('stock_after')->change();
        });
    }
};
