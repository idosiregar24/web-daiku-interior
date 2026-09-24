<?php

namespace App\Http\Controllers\Projects;

use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Models\BankAccount;
use App\Models\Lead;
use App\Models\Material;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
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

    public function show(Request $request, Project $project, ProjectPolicy $policy): Response
    {
        $this->authorize('view', $project);

        $user = $request->user();
        $taskVisibility = $policy->taskVisibility($user);
        $canViewMilestones = $user->hasAnyRole(['CEO', 'ESTIMATOR', 'PM', 'QA', 'SUPERADMIN']);
        $canManageMilestones = $user->hasAnyRole(['CEO', 'PM', 'SUPERADMIN']);
        $canManageTasks = $user->hasAnyRole(['PM', 'SUPERADMIN']);
        // PRD §7.1 "Progress Log" row: CEO/DES/PM/QA/FIN read, PM CRUD.
        $canViewProgressLogs = $user->hasAnyRole(['CEO', 'DESIGNER', 'PM', 'QA', 'FINANCE', 'SUPERADMIN']);
        $canManageProgressLogs = $user->hasAnyRole(['PM', 'SUPERADMIN']);
        // PRD §7.1 "Finance – Termin" row: CEO/FIN read, PM create-only —
        // PM sees what they scheduled through this project-scoped prop
        // rather than the Finance-only global list (finance.termins.index).
        $canViewTermins = $user->hasAnyRole(['CEO', 'PM', 'FINANCE', 'SUPERADMIN']);
        $canCreateTermins = $user->hasAnyRole(['PM', 'SUPERADMIN']);
        $canMarkTerminPaid = $user->hasAnyRole(['FINANCE', 'SUPERADMIN']);
        // PRD §7.1 "Project Material": CEO/PM/LOG read, EST/PM/LOG create
        // (see routes/web.php), PM/LOG update, LOG delete. Estimator also
        // reads — create-only access without seeing what's already
        // planned would just produce duplicate requests.
        $canViewMaterials = $user->hasAnyRole(['CEO', 'ESTIMATOR', 'PM', 'LOGISTICS', 'SUPERADMIN']);
        $canPlanMaterials = $user->hasAnyRole(['ESTIMATOR', 'PM', 'LOGISTICS', 'SUPERADMIN']);

        $project->load(['pm:id,name', 'lead:id,client_name']);

        return Inertia::render('Projects/Show', [
            'project' => $project,
            'milestones' => $canViewMilestones
                ? $project->milestones()->with('qaForm:id,milestone_id,status,rejection_count')->get()
                : [],
            'canViewMilestones' => $canViewMilestones,
            'canManageMilestones' => $canManageMilestones,
            'canManageTasks' => $canManageTasks,
            // Scoped per ProjectPolicy::taskVisibility() — QA and roles with
            // no task row in PRD §7.1 get none; Field Staff only their own.
            'tasks' => $taskVisibility === 'none'
                ? []
                : $project->tasks()
                    ->when($taskVisibility === 'own', fn ($query) => $query->where('assignee_id', $user->id))
                    ->with(['assignee:id,name', 'milestone:id,name'])
                    ->latest()
                    ->get(),
            'canViewTasks' => $taskVisibility !== 'none',
            'fieldStaff' => $canManageTasks ? User::role('FIELD_STAFF')->orderBy('name')->get(['id', 'name']) : [],
            'progressLogs' => $canViewProgressLogs
                ? $project->progressLogs()->with('logger:id,name')->get()
                : [],
            'canViewProgressLogs' => $canViewProgressLogs,
            'canManageProgressLogs' => $canManageProgressLogs,
            'termins' => $canViewTermins
                ? $project->termins()->with(['milestone:id,name', 'bankAccount:id,label'])->get()
                : [],
            'canViewTermins' => $canViewTermins,
            'canCreateTermins' => $canCreateTermins,
            'canMarkTerminPaid' => $canMarkTerminPaid,
            'bankAccounts' => $canCreateTermins ? BankAccount::where('is_active', true)->orderBy('label')->get(['id', 'label']) : [],
            'projectMaterials' => $canViewMaterials
                ? $project->projectMaterials()->with('material:id,name,unit,stock,cost_price,sell_price,min_stock')->get()
                : [],
            'canViewMaterials' => $canViewMaterials,
            'materialPermissions' => [
                'create' => $canPlanMaterials,
                'update' => $user->hasAnyRole(['PM', 'LOGISTICS', 'SUPERADMIN']),
                'delete' => $user->hasAnyRole(['LOGISTICS', 'SUPERADMIN']),
            ],
            'materialOptions' => $canPlanMaterials ? Material::orderBy('name')->get(['id', 'name', 'unit', 'stock']) : [],
        ]);
    }

    public function store(StoreProjectRequest $request, ProjectService $service): RedirectResponse
    {
        $lead = Lead::findOrFail($request->validated('lead_id'));

        $project = $service->createFromLead($lead, $request->validated());

        return redirect()->route('projects.show', $project)->with('success', 'Proyek berhasil dibuat.');
    }
}
