<?php

namespace Database\Seeders\Demo;

use App\Enums\MilestoneStatus;
use App\Enums\TaskStatus;
use App\Models\DailyTaskForm;
use App\Models\Project;
use App\Models\User;
use App\Services\TaskService;
use App\Support\DailyFormSchedule;
use Illuminate\Database\Seeder;

/**
 * Sprint 13 — something to see on the demo Tukang's phone screens
 * (fieldstaff@daikuinterior.com): three tasks due today on an active
 * project, today's daily form sent for one of them only (so "Hari Ini"
 * shows the warning and both ✔ and ✖ marks), and one task next week
 * (the Sunday view). Tasks go through TaskService like the other demo
 * seeders; the form row is written directly, as DemoDataSeeder does,
 * because DailyTaskFormService refuses after 21:00 and seeding must not
 * depend on the hour it runs.
 */
class TodayDemoSeeder extends Seeder
{
    public function run(TaskService $taskService): void
    {
        $tukang = User::where('email', 'fieldstaff@daikuinterior.com')->first();
        $pm = User::where('email', 'pm@daikuinterior.com')->first();
        $project = $pm ? Project::query()->active()->where('pm_id', $pm->id)->oldest('id')->first() : null;

        if (! $tukang || ! $project) {
            return;
        }

        $milestone = $project->milestones()->where('status', '!=', MilestoneStatus::Completed->value)->first();
        $now = DailyFormSchedule::now();

        $specs = [
            ['title' => 'Pasang rangka plafon gypsum', 'status' => TaskStatus::OnProgress, 'due' => $now],
            ['title' => 'Finishing HPL meja dapur', 'status' => TaskStatus::OnProgress, 'due' => $now],
            ['title' => 'Rapikan instalasi lampu LED', 'status' => TaskStatus::Pending, 'due' => $now],
            ['title' => 'Pasang kaca cermin kamar mandi', 'status' => TaskStatus::Pending, 'due' => $now->copy()->next('Wednesday')],
        ];

        $tasks = collect($specs)->map(function (array $spec) use ($taskService, $project, $milestone, $tukang, $pm) {
            $task = $taskService->create($project, [
                'milestone_id' => $milestone?->id,
                'title' => $spec['title'],
                'assignee_id' => $tukang->id,
                'due_date' => $spec['due']->toDateString(),
                'priority' => 'MEDIUM',
                'rate_per_task' => 150_000,
            ], $pm);

            if ($spec['status'] !== TaskStatus::Pending) {
                $taskService->updateStatus($task, ['status' => $spec['status']->value], $tukang);
            }

            return $task;
        });

        // Today's form for the first task only — the other two stay "belum diisi".
        DailyTaskForm::create([
            'task_id' => $tasks[0]->id,
            'staff_id' => $tukang->id,
            'work_date' => $now->toDateString(),
            'status_update' => TaskStatus::OnProgress->value,
            'notes' => 'Rangka sisi timur selesai, lanjut sisi barat besok.',
            'submitted_at' => $now,
        ]);
    }
}
