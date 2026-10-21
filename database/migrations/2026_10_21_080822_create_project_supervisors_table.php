<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 29 Sub 06 (A1) — a project may have several Pengawas (QA/QC), all
 * equal: `projects.supervisor_id` becomes the `project_supervisors` pivot.
 * The existing Pengawas is copied over; down() puts the first one back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_supervisors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
            $table->index('user_id');
        });

        // Read first: SQLite rebuilds `projects` to drop the column, which
        // cascades onto its child rows — the pivot is filled afterwards.
        $rows = DB::table('projects')->whereNotNull('supervisor_id')->orderBy('id')->get(['id', 'supervisor_id']);

        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supervisor_id');
        });

        $now = now();
        $rows->each(fn ($project) => DB::table('project_supervisors')->insert([
            'project_id' => $project->id,
            'user_id' => $project->supervisor_id,
            'created_at' => $now,
            'updated_at' => $now,
        ]));
    }

    public function down(): void
    {
        // Read first (see up()): the first Pengawas of each project.
        $first = DB::table('project_supervisors')->orderBy('id')->get(['project_id', 'user_id'])->unique('project_id');

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('supervisor_id')->nullable()->after('team_lead_name')->constrained('users')->nullOnDelete();
        });

        $first->each(fn ($row) => DB::table('projects')->where('id', $row->project_id)->update(['supervisor_id' => $row->user_id]));

        Schema::dropIfExists('project_supervisors');
    }
};
