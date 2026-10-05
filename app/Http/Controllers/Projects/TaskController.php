<?php

namespace App\Http\Controllers\Projects;

use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreTaskRequest;
use App\Http\Requests\Projects\UpdateTaskRequest;
use App\Http\Requests\Projects\UpdateTaskStatusRequest;
use App\Models\Material;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    /** PRD §4.5 "Task List … (hari ini & minggu ini)" + overdue — see Task::scopeByDue(). */
    private const DUE_FILTERS = ['today', 'week', 'overdue'];

    /**
     * PRD §7.1 "Task – Create/Edit"/"Task – Update Status": CEO/PM see
     * everything, Field Staff only their own assigned tasks (PRD §4.5
     * "Task List: Tukang melihat daftar task yang di-assign ke mereka" —
     * more specific than the matrix's bare "-" on that row, same
     * precedent as CRM Lead's write-access prose override).
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $canAssign = $user->hasAnyRole(['PM', 'SUPERADMIN']);
        $due = in_array($request->query('due'), self::DUE_FILTERS, true) ? $request->query('due') : null;

        $tasks = Task::query()
            ->with(['project:id,name,status', 'milestone:id,name', 'assignee:id,name'])
            ->when(
                $user->hasRole('FIELD_STAFF') && ! $user->hasAnyRole(['CEO', 'PM', 'SUPERADMIN']),
                fn ($query) => $query->where('assignee_id', $user->id),
            )
            ->byStatus($request->string('status')->value() ?: null)
            ->byAssignee($request->integer('assignee_id') ?: null)
            ->byDue($due)
            ->when($request->filled('milestone_id'), fn ($query) => $query->where('milestone_id', $request->integer('milestone_id')))
            // A DONE task whose wage is paid is locked (TaskService::update()).
            ->when($canAssign, fn ($query) => $query->withExists('wagePayment as is_wage_paid'))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Tasks/Index', [
            'tasks' => $tasks,
            'filters' => $request->only(['status', 'assignee_id', 'milestone_id', 'due']),
            // `is_active` — the filter still lists deactivated tukang, the task form only active ones.
            'fieldStaff' => $canAssign ? User::role('FIELD_STAFF')->orderBy('name')->get(['id', 'name', 'is_active']) : [],
            'milestones' => $canAssign
                ? Milestone::query()->with('project:id,name')->orderBy('name')->get(['id', 'name', 'project_id', 'status'])
                : [],
            'canAssign' => $canAssign,
            // Sprint 11 Sub 4 — a Tukang asks for goods from their task list
            // (name + qty + note); the request goes to the project's PM first.
            'materialRequestProjects' => $user->hasRole('FIELD_STAFF')
                ? Project::query()
                    ->whereIn('status', [ProjectStatus::Active->value, ProjectStatus::OnHold->value])
                    ->whereHas('tasks', fn ($query) => $query->where('assignee_id', $user->id))
                    ->orderBy('name')
                    ->get(['id', 'name'])
                : [],
            // §5.5 Lapis 4 — "Mungkin maksud Anda" while the Tukang types the item name.
            'catalogHints' => $user->hasRole('FIELD_STAFF') ? Material::active()->orderBy('name')->get(['id', 'code', 'name', 'unit_id']) : [],
        ]);
    }

    public function store(StoreTaskRequest $request, Project $project, TaskService $service): RedirectResponse
    {
        // Sprint 12 #22 — also the Asisten PM of this project.
        $this->authorize('manageWork', $project);
        $service->create($project, $request->validated(), $request->user());

        return back()->with('success', 'Task berhasil ditambahkan.');
    }

    /**
     * Sprint 9 "Edit Task" — PM only (route `role:PM` + TaskPolicy::update());
     * a Field Staff gets 403 here, their side is updateStatus() (CLAUDE.md
     * golden rule #6). Business rules in TaskService::update().
     */
    public function update(UpdateTaskRequest $request, Task $task, TaskService $service): RedirectResponse
    {
        $this->authorize('update', $task);

        $service->update($task, $request->validated(), $request->user());

        return back()->with('success', 'Task berhasil diperbarui.');
    }

    /** Sprint 9 "Hapus Task" — only a task without history, see TaskService::delete(). */
    public function destroy(Request $request, Task $task, TaskService $service): RedirectResponse
    {
        $this->authorize('delete', $task);

        $service->delete($task, $request->user());

        return back()->with('success', 'Task berhasil dihapus.');
    }

    public function updateStatus(UpdateTaskStatusRequest $request, Task $task, TaskService $service): RedirectResponse
    {
        $this->authorize('updateStatus', $task);

        $service->updateStatus($task, $request->validated(), $request->user());

        return back()->with('success', 'Status task diperbarui.');
    }
}
