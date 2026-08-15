<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Models\Lead;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    /**
     * PRD §7.1 "Project (overview)": everyone has at least R, but Field
     * Staff is R* ("hanya data milik user") — scoped here to projects
     * where they have a task assigned, not the full project list.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $projects = Project::query()
            ->with(['pm:id,name'])
            ->byStatus($request->string('status')->value() ?: null)
            ->byPm($request->integer('pm_id') ?: null)
            ->when(
                $user->hasRole('FIELD_STAFF') && ! $user->hasAnyRole(['CEO', 'SUPERADMIN']),
                fn ($query) => $query->whereHas('tasks', fn ($q) => $q->where('assignee_id', $user->id)),
            )
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Projects/Index', [
            'projects' => $projects,
            'filters' => $request->only(['status', 'pm_id']),
            'projectManagers' => User::role('PM')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, Project $project): Response
    {
        $user = $request->user();
        $canViewMilestones = $user->hasAnyRole(['CEO', 'ESTIMATOR', 'PM', 'QA', 'SUPERADMIN']);
        $canManageMilestones = $user->hasAnyRole(['CEO', 'PM', 'SUPERADMIN']);
        $canManageTasks = $user->hasAnyRole(['PM', 'SUPERADMIN']);

        $project->load(['pm:id,name', 'lead:id,client_name']);

        return Inertia::render('Projects/Show', [
            'project' => $project,
            'milestones' => $canViewMilestones
                ? $project->milestones
                : [],
            'canViewMilestones' => $canViewMilestones,
            'canManageMilestones' => $canManageMilestones,
            'canManageTasks' => $canManageTasks,
            'tasks' => $project->tasks()->with(['assignee:id,name', 'milestone:id,name'])->latest()->get(),
            'fieldStaff' => $canManageTasks ? User::role('FIELD_STAFF')->orderBy('name')->get(['id', 'name']) : [],
        ]);
    }

    public function store(StoreProjectRequest $request, ProjectService $service): RedirectResponse
    {
        $lead = Lead::findOrFail($request->validated('lead_id'));

        $project = $service->createFromLead($lead, $request->validated());

        return redirect()->route('projects.show', $project)->with('success', 'Proyek berhasil dibuat.');
    }
}
