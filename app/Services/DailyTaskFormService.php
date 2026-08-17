<?php

namespace App\Services;

use App\Models\DailyTaskForm;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PRD §4.5 "Daily Form Wajib" — the mandatory once-a-day check-in per
 * task, distinct from the ad-hoc "Update Status" action Tasks/Index.tsx
 * already offers (TaskController::updateStatus()). Submitting a daily
 * form both logs the DailyTaskForm row *and* applies the same status/
 * kendala/note update to the live Task via TaskService — the form's
 * `status_update` field is exactly that update, not a separate fact.
 */
class DailyTaskFormService
{
    public function __construct(private TaskService $taskService) {}

    /**
     * @param  array{status: string, kendala?: ?string, notes?: ?string}  $data
     */
    public function store(Task $task, array $data, User $actor): DailyTaskForm
    {
        if ($task->assignee_id !== $actor->id) {
            throw ValidationException::withMessages([
                'task_id' => 'Task ini bukan milik Anda.',
            ]);
        }

        $today = now('Asia/Jakarta');

        if ($today->hour >= 21) {
            throw ValidationException::withMessages([
                'work_date' => 'Form harian tidak bisa disubmit setelah jam 21:00 WIB.',
            ]);
        }

        $workDate = $today->toDateString();

        // whereDate(), not where() — the `work_date` column is cast to
        // 'date' on the model, which Eloquent serializes with a midnight
        // time component ("2026-08-17 00:00:00"); a plain where() against
        // a bare "2026-08-17" string would never match, silently letting
        // duplicates fall through to the DB's unique constraint instead
        // of this friendly validation error.
        if (DailyTaskForm::where('task_id', $task->id)->whereDate('work_date', $workDate)->exists()) {
            throw ValidationException::withMessages([
                'task_id' => 'Form harian untuk task ini sudah diisi hari ini.',
            ]);
        }

        return DB::transaction(function () use ($task, $data, $actor, $workDate, $today) {
            $this->taskService->updateStatus($task, [
                'status' => $data['status'],
                'kendala' => $data['kendala'] ?? null,
                'note' => $data['notes'] ?? null,
            ], $actor);

            return DailyTaskForm::create([
                'task_id' => $task->id,
                'staff_id' => $actor->id,
                'work_date' => $workDate,
                'status_update' => $data['status'],
                'kendala' => $data['kendala'] ?? null,
                'notes' => $data['notes'] ?? null,
                'submitted_at' => $today,
            ]);
        });
    }
}
