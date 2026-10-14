<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 23 K14/K15 — what the PM fills in at "Buka Proyek":
     *
     * - Kepala Tim: a user (`team_lead_id`) **or** a typed name
     *   (`team_lead_name`, an outsider/vendor) — exactly one of the two for
     *   projects opened from Sprint 23 on (Form Requests); older projects
     *   keep both empty until someone fills them in Edit Proyek.
     * - Pengawas (QA/QC): `supervisor_id`, a user with role QA, optional.
     *   Its work (daily target, weekly report, DP) arrives in Sprint 24.
     * - Tanggal Buka Proyek: `opened_on`, distinct from `start_date`
     *   (start may not be before it). Backfilled for existing projects:
     *   the day their opening was recorded (else the row's created_at),
     *   never after their start date, so the new rule holds for old rows.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('team_lead_id')->nullable()->after('assistant_pm_id')->constrained('users')->nullOnDelete();
            $table->string('team_lead_name')->nullable()->after('team_lead_id');
            $table->foreignId('supervisor_id')->nullable()->after('team_lead_name')->constrained('users')->nullOnDelete();
            $table->date('opened_on')->nullable()->after('supervisor_id');
        });

        // In PHP, not SQL: the same code on MySQL and SQLite (tests).
        $openedAt = DB::table('project_openings')->whereNotNull('project_id')->pluck('opened_at', 'project_id');

        DB::table('projects')->orderBy('id')->select(['id', 'start_date', 'created_at'])->get()
            ->each(function ($project) use ($openedAt) {
                $opened = substr((string) ($openedAt[$project->id] ?? $project->created_at ?? $project->start_date ?? now()), 0, 10);

                if ($project->start_date !== null && substr((string) $project->start_date, 0, 10) < $opened) {
                    $opened = substr((string) $project->start_date, 0, 10);
                }

                DB::table('projects')->where('id', $project->id)->update(['opened_on' => $opened]);
            });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_lead_id');
            $table->dropConstrainedForeignId('supervisor_id');
            $table->dropColumn(['team_lead_name', 'opened_on']);
        });
    }
};
