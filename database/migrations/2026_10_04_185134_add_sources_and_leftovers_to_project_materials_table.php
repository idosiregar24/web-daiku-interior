<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 11 Sub 3 (§5.3) — a project material line gets a source
     * (GUDANG | PEMBELIAN | CUSTOM), its own unit, a receive → use →
     * settle-the-leftover lifecycle, and what the project is charged.
     * The request/review columns are created now so the schema stays
     * stable; their workflow arrives in Sub 4.
     *
     * - `material_id` becomes nullable (CUSTOM lines have no catalog item);
     *   the old unique(project, material) becomes unique(project, material,
     *   source) — a project may take an item from stock *and* buy more.
     * - Every quantity becomes DECIMAL(12,2) (decision #3).
     * - `cost_total` accumulates the charge: issued qty × warehouse price,
     *   bought qty × actual price. Returns never lower it (decision #9).
     * - `finance_transaction_id` is prepared for the pending Finance link
     *   (T2), nullable.
     *
     * Backfill: every existing line was planned from the catalog and its
     * `qty_used` only ever grew through stock-out, so it becomes a GUDANG
     * line whose received qty equals its used qty (leftover 0 — no
     * existing project is suddenly blocked from completing), charged at
     * the material's current warehouse price.
     *
     * The FK on `material_id` is dropped and re-added around the column
     * change: MySQL refuses to modify a column inside a live foreign key.
     */
    public function up(): void
    {
        Schema::table('project_materials', function (Blueprint $table) {
            $table->string('source', 20)->default('GUDANG')->after('project_id');
            $table->string('custom_name', 150)->nullable()->after('material_id');
            $table->string('custom_spec')->nullable()->after('custom_name');
            $table->foreignId('unit_id')->nullable()->after('custom_spec')->constrained('units')->restrictOnDelete();
            $table->decimal('unit_price', 15, 2)->nullable()->after('unit_id');
            $table->foreignId('vendor_id')->nullable()->after('unit_price')->constrained('vendors')->restrictOnDelete();

            // Request / review (workflow in Sub 4).
            $table->string('request_status', 20)->default('DISETUJUI');
            $table->text('request_reason')->nullable();
            $table->string('photo_link', 500)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_decision', 20)->nullable(); // PAKAI_KATALOG | DAFTAR_KATALOG | CUSTOM | TOLAK
            $table->text('reject_reason')->nullable();
            $table->json('requested_snapshot')->nullable();

            $table->decimal('qty_received', 12, 2)->default(0);
            $table->decimal('qty_returned', 12, 2)->default(0);
            $table->decimal('qty_wasted', 12, 2)->default(0);
            $table->decimal('qty_handed_over', 12, 2)->default(0);
            $table->decimal('cost_total', 15, 2)->default(0);
            $table->text('waste_reason')->nullable();
            $table->text('handover_note')->nullable();
            $table->foreignId('finance_transaction_id')->nullable()->constrained()->nullOnDelete();

            $table->index(['request_status', 'created_at']);
            // Added before the old unique is dropped: MySQL needs an index
            // starting with project_id for that FK at every moment.
            $table->unique(['project_id', 'material_id', 'source']);
        });

        Schema::table('project_materials', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'material_id']);
            $table->dropForeign(['material_id']);
        });

        Schema::table('project_materials', function (Blueprint $table) {
            $table->foreignId('material_id')->nullable()->change();
            $table->decimal('qty_planned', 12, 2)->default(0)->change();
            $table->decimal('qty_used', 12, 2)->default(0)->change();
            $table->foreign('material_id')->references('id')->on('materials')->restrictOnDelete();
        });

        $rows = DB::table('project_materials')
            ->join('materials', 'materials.id', '=', 'project_materials.material_id')
            ->get(['project_materials.id', 'project_materials.qty_used', 'materials.unit_id', 'materials.cost_price']);

        foreach ($rows as $row) {
            DB::table('project_materials')->where('id', $row->id)->update([
                'unit_id' => $row->unit_id,
                'qty_received' => $row->qty_used,
                'cost_total' => round((float) $row->qty_used * (float) $row->cost_price, 2),
            ]);
        }

        Schema::table('project_materials', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable(false)->change();
        });

        Schema::table('materials', function (Blueprint $table) {
            $table->decimal('stock', 12, 2)->default(0)->change();
            $table->decimal('min_stock', 12, 2)->default(0)->change();
        });
    }

    /**
     * Reversible only while no CUSTOM line and no second line per
     * (project, material) exists — those have no place in the old shape,
     * so they're removed. Fractional quantities are truncated.
     */
    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->unsignedInteger('stock')->default(0)->change();
            $table->unsignedInteger('min_stock')->default(0)->change();
        });

        DB::table('project_materials')->whereNull('material_id')->delete();
        DB::table('project_materials')->where('source', '!=', 'GUDANG')->delete();

        Schema::table('project_materials', function (Blueprint $table) {
            $table->dropForeign(['material_id']);
        });

        Schema::table('project_materials', function (Blueprint $table) {
            $table->foreignId('material_id')->nullable(false)->change();
            $table->unsignedInteger('qty_planned')->default(0)->change();
            $table->unsignedInteger('qty_used')->default(0)->change();
            $table->foreign('material_id')->references('id')->on('materials')->restrictOnDelete();
            $table->unique(['project_id', 'material_id']);
        });

        Schema::table('project_materials', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'material_id', 'source']);
            $table->dropIndex(['request_status', 'created_at']);
            $table->dropConstrainedForeignId('unit_id');
            $table->dropConstrainedForeignId('vendor_id');
            $table->dropConstrainedForeignId('requested_by');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('finance_transaction_id');
            $table->dropColumn([
                'source', 'custom_name', 'custom_spec', 'unit_price',
                'request_status', 'request_reason', 'photo_link', 'reviewed_at', 'review_decision', 'reject_reason', 'requested_snapshot',
                'qty_received', 'qty_returned', 'qty_wasted', 'qty_handed_over', 'cost_total', 'waste_reason', 'handover_note',
            ]);
        });
    }
};
