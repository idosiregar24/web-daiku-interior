<?php

namespace App\Http\Controllers\Tasks;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\StoreDailyTaskFormRequest;
use App\Models\DailyTaskForm;
use App\Models\Task;
use App\Services\DailyTaskFormService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PRD §4.5 "Daily Form Wajib" / §7.1 "Daily Task Form" row (CEO/PM read,
 * Field Staff create+read own). See DailyTaskFormService's docblock for
 * how this relates to TaskController::updateStatus().
 */
class DailyTaskFormController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $date = $request->string('date')->value() ?: now('Asia/Jakarta')->toDateString();

        $isFieldStaff = $user->hasRole('FIELD_STAFF') && ! $user->hasAnyRole(['CEO', 'PM', 'SUPERADMIN']);

        $forms = DailyTaskForm::query()
            ->with(['task:id,title,project_id', 'task.project:id,name', 'staff:id,name'])
            ->forDate($date)
            ->when($isFieldStaff, fn ($query) => $query->where('staff_id', $user->id))
            ->latest('submitted_at')
            ->get();

        // Field Staff also needs "which of my active tasks still need
        // today's form" — the actual fill-in list PRD §4.5 describes.
        $pendingTasks = collect();

        if ($isFieldStaff) {
            $submittedTaskIds = $forms->pluck('task_id');

            $pendingTasks = Task::query()
                ->where('assignee_id', $user->id)
                ->where('status', '!=', TaskStatus::Done->value)
                ->whereNotIn('id', $submittedTaskIds)
                ->with('project:id,name')
                ->get();
        }

        return Inertia::render('DailyForm/Index', [
            'forms' => $forms,
            'pendingTasks' => $pendingTasks,
            'date' => $date,
            'isFieldStaff' => $isFieldStaff,
        ]);
    }

    public function store(StoreDailyTaskFormRequest $request, Task $task, DailyTaskFormService $service): RedirectResponse
    {
        $service->store($task, $request->validated(), $request->user());

        return back()->with('success', 'Form harian berhasil disubmit.');
    }
}
