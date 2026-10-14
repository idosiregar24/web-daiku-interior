<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 23 K13 — "Buka Proyek" moves from the CEO to the PM who
     * approved that RAB Proyek. `opener_id` is that PM, stored when the
     * opening is queued (QueueProjectOpening) so the queue, the pop-up and
     * ProjectOpeningPolicy stay one cheap column check; null = any PM (the
     * RAB was approved by an Asisten PM, or before this flow).
     *
     * The waiting status `MENUNGGU_CEO` becomes `MENUNGGU_PM`; waiting rows
     * get the PM from the PM-stage approval of the client-approved version.
     */
    public function up(): void
    {
        Schema::table('project_openings', function (Blueprint $table) {
            $table->foreignId('opener_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
        });

        $waiting = DB::table('project_openings')
            ->join('quotations', 'quotations.id', '=', 'project_openings.quotation_id')
            ->where('project_openings.status', 'MENUNGGU_CEO')
            ->get(['project_openings.id', 'project_openings.quotation_id', 'quotations.version']);

        foreach ($waiting as $opening) {
            $approverId = DB::table('quotation_approvals')
                ->where('quotation_id', $opening->quotation_id)
                ->where('version', $opening->version)
                ->where('approver_role', 'PM')
                ->where('status', 'APPROVED')
                ->orderByDesc('id')
                ->value('approver_id');

            // Only a user holding the PM role opens; an Asisten PM's approval leaves it to every PM.
            $isPm = $approverId !== null && DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_id', $approverId)
                ->where('model_has_roles.model_type', 'App\\Models\\User')
                ->where('roles.name', 'PM')
                ->exists();

            DB::table('project_openings')->where('id', $opening->id)->update([
                'status' => 'MENUNGGU_PM',
                'opener_id' => $isPm ? $approverId : null,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('project_openings')->where('status', 'MENUNGGU_PM')->update(['status' => 'MENUNGGU_CEO']);

        Schema::table('project_openings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opener_id');
        });
    }
};
