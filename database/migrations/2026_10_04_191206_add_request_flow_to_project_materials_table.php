<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 11 Sub 4 — out-of-catalog requests (decision #13) and the
     * Tukang → PM → Logistics path (Sprint 12 decision #31).
     *
     * - `request_channel`: TIM (PM/Estimator, straight to Logistics) or
     *   TUKANG (field staff, the project's PM approves first).
     * - `submitted_at`: when the request reached Logistics — the clock the
     *   "not reviewed within 1 working day" reminder runs on.
     * - `pm_reviewed_by` / `pm_reviewed_at`: the PM's decision on a Tukang
     *   request. `reminded_at`: last reminder, so a re-run on the same day
     *   doesn't remind twice.
     * - `unit_id` becomes nullable: a Tukang only names the item and qty;
     *   Logistics picks the unit when reviewing. Every approved line still
     *   has one (MaterialRequestService enforces it).
     * - unique(project, material, source) is dropped: a request approved
     *   as "use the catalog item" becomes its own line next to any line
     *   already planned for that item — each keeps its own history.
     *   StockService targets lines explicitly.
     *
     * The FK on `unit_id` is dropped and re-added around the change, as in
     * the Sub 3 migration (MySQL refuses to modify a column inside a live FK).
     */
    public function up(): void
    {
        Schema::table('project_materials', function (Blueprint $table) {
            $table->string('request_channel', 20)->nullable()->after('request_status');
            $table->timestamp('submitted_at')->nullable()->after('request_channel');
            $table->foreignId('pm_reviewed_by')->nullable()->after('requested_by')->constrained('users')->nullOnDelete();
            $table->timestamp('pm_reviewed_at')->nullable()->after('pm_reviewed_by');
            $table->timestamp('reminded_at')->nullable()->after('reviewed_at');

            $table->index(['request_status', 'submitted_at']);
            // Kept for the project_id / material_id FKs before the unique goes.
            $table->index(['project_id', 'material_id']);
        });

        Schema::table('project_materials', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'material_id', 'source']);
            $table->dropForeign(['unit_id']);
        });

        Schema::table('project_materials', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->change();
            $table->foreign('unit_id')->references('id')->on('units')->restrictOnDelete();
        });
    }

    /**
     * Requests that never got a unit, or that duplicate an item+source
     * line, have no place in the old shape and are removed.
     */
    public function down(): void
    {
        DB::table('project_materials')->whereNull('unit_id')->delete();

        $duplicates = DB::table('project_materials')
            ->select('project_id', 'material_id', 'source', DB::raw('MIN(id) as keep_id'))
            ->whereNotNull('material_id')
            ->groupBy('project_id', 'material_id', 'source')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('project_materials')
                ->where('project_id', $duplicate->project_id)
                ->where('material_id', $duplicate->material_id)
                ->where('source', $duplicate->source)
                ->where('id', '!=', $duplicate->keep_id)
                ->delete();
        }

        Schema::table('project_materials', function (Blueprint $table) {
            $table->dropForeign(['unit_id']);
        });

        Schema::table('project_materials', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable(false)->change();
            $table->foreign('unit_id')->references('id')->on('units')->restrictOnDelete();
            $table->unique(['project_id', 'material_id', 'source']);
        });

        Schema::table('project_materials', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'material_id']);
            $table->dropIndex(['request_status', 'submitted_at']);
            $table->dropConstrainedForeignId('pm_reviewed_by');
            $table->dropColumn(['request_channel', 'submitted_at', 'pm_reviewed_at', 'reminded_at']);
        });
    }
};
