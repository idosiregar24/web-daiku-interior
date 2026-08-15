<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreTaskRequest;
use App\Http\Requests\Projects\UpdateTaskStatusRequest;
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

        $tasks = Task::query()
            ->with(['project:id,name', 'milestone:id,name', 'assignee:id,name'])
            ->when(
                $user->hasRole('FIELD_STAFF') && ! $user->hasAnyRole(['CEO', 'PM', 'SUPERADMIN']),
                fn ($query) => $query->where('assignee_id', $user->id),
            )
            ->byStatus($request->string('status')->value() ?: null)
            ->byAssignee($request->integer('assignee_id') ?: null)
            ->when($request->filled('milestone_id'), fn ($query) => $query->where('milestone_id', $request->integer('milestone_id')))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        $canAssign = $user->hasAnyRole(['PM', 'SUPERADMIN']);

        return Inertia::render('Tasks/Index', [
            'tasks' => $tasks,
            'filters' => $request->only(['status', 'assignee_id', 'milestone_id']),
            'fieldStaff' => $canAssign ? User::role('FIELD_STAFF')->orderBy('name')->get(['id', 'name']) : [],
            'milestones' => $canAssign
                ? Milestone::query()->with('project:id,name')->orderBy('name')->get(['id', 'name', 'project_id'])
                : [],
            'canAssign' => $canAssign,
        ]);
    }

    public function store(StoreTaskRequest $request, Project $project, TaskService $service): RedirectResponse
    {
        $service->create($project, $request->validated(), $request->user());

        return back()->with('success', 'Task berhasil ditambahkan.');
    }

    public function updateStatus(UpdateTaskStatusRequest $request, Task $task, TaskService $service): RedirectResponse
    {
        $this->authorize('updateStatus', $task);

        $service->updateStatus($task, $request->validated(), $request->user());

        return back()->with('success', 'Status task diperbarui.');
    }
}
