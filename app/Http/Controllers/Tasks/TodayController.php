<?php

namespace App\Http\Controllers\Tasks;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Models\DailyTaskForm;
use App\Models\Task;
use App\Support\DailyFormSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint 13 H2/H3/H11 — "Hari Ini", the Tukang's first screen: their open
 * tasks as cards with today's daily-form mark (✔/✖), and the warning
 * before the penalty run. On a day without penalty (Sunday, see
 * DailyFormSchedule) it lists next week's tasks instead.
 *
 * Only the Tukang's own tasks, same base as the Form Harian fill-in list
 * (Task::awaitingDailyForm()) — so "n belum diisi" here equals the
 * "Perlu Tindakan" count. Saving goes through the existing endpoints
 * (daily-forms.store, else tasks.updateStatus) — no write here.
 */
class TodayController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $now = DailyFormSchedule::now();
        $isWorkDay = DailyFormSchedule::isWorkDay($now);
        $columns = ['id', 'project_id', 'milestone_id', 'title', 'description', 'status', 'kendala', 'note', 'due_date', 'priority'];

        $open = fn () => Task::query()
            ->where('assignee_id', $user->id)
            ->where('status', '!=', TaskStatus::Done->value)
            ->with(['project:id,name', 'milestone:id,name']);

        if ($isWorkDay) {
            $filled = DailyTaskForm::query()
                ->where('staff_id', $user->id)
                ->forDate($now->toDateString())
                ->pluck('task_id')
                ->flip();

            $tasks = $open()
                ->orderByRaw('due_date IS NULL')
                ->orderBy('due_date')
                ->orderBy('id')
                ->get($columns)
                ->map(fn (Task $task) => [...$task->toArray(), 'has_form_today' => $filled->has($task->id)]);
        }

        // Sunday: Monday–Saturday of the coming week, one group per day client-side.
        $nextWeek = $isWorkDay ? null : $this->nextWorkWeek($now);

        return Inertia::render('Today/Index', [
            'isWorkDay' => $isWorkDay,
            'penaltyAt' => DailyFormSchedule::penaltyAt(),
            // The form can still be sent today (working day, before the cutoff).
            'canSubmitDailyForm' => $isWorkDay && ! DailyFormSchedule::isPastCutoff($now),
            'today' => $now->toDateString(),
            'tasks' => $tasks ?? [],
            'upcoming' => $nextWeek
                ? $open()
                    ->whereDate('due_date', '>=', $nextWeek[0]->toDateString())
                    ->whereDate('due_date', '<=', $nextWeek[1]->toDateString())
                    ->orderBy('due_date')
                    ->orderBy('id')
                    ->get($columns)
                : [],
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} first and last working day of the coming week */
    private function nextWorkWeek(Carbon $now): array
    {
        $start = $now->copy()->addDay()->startOfDay();
        while (! DailyFormSchedule::isWorkDay($start)) {
            $start->addDay();
        }

        $end = $start->copy();
        while (DailyFormSchedule::isWorkDay($end->copy()->addDay())) {
            $end->addDay();
        }

        return [$start, $end];
    }
}
