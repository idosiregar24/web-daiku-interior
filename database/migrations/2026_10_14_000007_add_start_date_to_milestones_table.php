<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 23 K17 — a Timeline (the UI name of a milestone, K16) runs
     * from `start_date` to `target_date` ("Tanggal Selesai"). Required for
     * every new timeline (Form Requests; MilestoneService fills it when a
     * caller has none), so the column stays nullable only for the rows
     * written before this migration — which it backfills: the previous
     * timeline's end + 1 day within the same project (by `order`, then
     * end date, then id), or the project's start date for the first one.
     * Never after the timeline's own end date (start ≤ end always holds).
     * Overdue still reads `target_date` only.
     */
    public function up(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->date('start_date')->nullable()->after('name');
        });

        // In PHP, not SQL: the same code on MySQL and SQLite (tests).
        $projectStarts = DB::table('projects')->pluck('start_date', 'id');

        DB::table('milestones')
            ->orderBy('project_id')->orderBy('order')->orderBy('target_date')->orderBy('id')
            ->get(['id', 'project_id', 'target_date'])
            ->groupBy('project_id')
            ->each(function ($milestones, $projectId) use ($projectStarts) {
                $previousEnd = null;

                foreach ($milestones as $milestone) {
                    $end = Carbon::parse($milestone->target_date)->startOfDay();
                    $projectStart = $projectStarts[$projectId] ?? null;
                    $start = match (true) {
                        $previousEnd !== null => $previousEnd->copy()->addDay(),
                        $projectStart !== null => Carbon::parse($projectStart)->startOfDay(),
                        default => $end->copy(),
                    };

                    DB::table('milestones')->where('id', $milestone->id)->update([
                        'start_date' => $start->min($end)->toDateString(),
                    ]);

                    $previousEnd = $end;
                }
            });
    }

    public function down(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->dropColumn('start_date');
        });
    }
};
