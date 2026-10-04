<?php

namespace App\Http\Controllers\Projects;

use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Models\BankAccount;
use App\Models\Lead;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Policies\ProjectPolicy;
use App\Services\FinanceAllocationService;
use App\Services\ProjectService;
use App\Services\SupplierDebtService;
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

    public function show(
        Request $request,
        Project $project,
        ProjectPolicy $policy,
        FinanceAllocationService $allocationService,
        SupplierDebtService $supplierDebtService,
        ProjectService $projectService,
    ): Response {
        $this->authorize('view', $project);

        $user = $request->user();
        $taskVisibility = $policy->taskVisibility($user);
        // ASISTEN_PM (Sprint 12 Sub 1) reads every tab the PM reads; its write
        // actions arrive in Sub 4 (RAB) and Sub 11 (its assigned projects).
        $canViewMilestones = $user->hasAnyRole(['CEO', 'ESTIMATOR', 'PM', 'ASISTEN_PM', 'QA', 'SUPERADMIN']);
        $canManageMilestones = $user->hasAnyRole(['CEO', 'PM', 'SUPERADMIN']);
        $canManageTasks = $user->hasAnyRole(['PM', 'SUPERADMIN']);
        // Sprint 9 "Edit Proyek": CEO any project, PM their own
        // (ProjectPolicy::update()); COMPLETED/CANCELLED are read-only for
        // everyone, so the action isn't offered at all there.
        $canEditProject = $user->can('update', $project) && ! $project->isClosed();
        $canChangePm = $canEditProject && $user->hasAnyRole(['CEO', 'SUPERADMIN']);
        // PRD §7.1 "Progress Log" row: CEO/DES/PM/QA/FIN read, PM CRUD.
        $canViewProgressLogs = $user->hasAnyRole(['CEO', 'DESIGNER', 'PM', 'ASISTEN_PM', 'QA', 'FINANCE', 'SUPERADMIN']);
        $canManageProgressLogs = $user->hasAnyRole(['PM', 'SUPERADMIN']);
        // PRD §7.1 "Finance – Termin" row: CEO/FIN read, PM create-only —
        // PM sees what they scheduled through this project-scoped prop
        // rather than the Finance-only global list (finance.termins.index).
        $canViewTermins = $user->hasAnyRole(['CEO', 'PM', 'ASISTEN_PM', 'FINANCE', 'SUPERADMIN']);
        $canCreateTermins = $user->hasAnyRole(['PM', 'SUPERADMIN']);
        $canMarkTerminPaid = $user->hasAnyRole(['FINANCE', 'SUPERADMIN']);
        // Budget allocation + outstanding supplier debts (PRD §4.7) follow the
        // "Finance – Transaction" row: CEO/PM/FIN read — same set as termins.
        $canViewFinanceSummary = $canViewTermins;
        // PRD §7.1 "Project Material": CEO/PM/LOG read, EST/PM/LOG create
        // (see routes/web.php), PM/LOG update, LOG delete. Estimator also
        // reads — create-only access without seeing what's already
        // planned would just produce duplicate requests.
        $canViewMaterials = $user->hasAnyRole(['CEO', 'ESTIMATOR', 'PM', 'ASISTEN_PM', 'LOGISTICS', 'SUPERADMIN']);
        // Sprint 11 §5.6: a PM plans/records only on their own project.
        $isRunning = in_array($project->status, [ProjectStatus::Active, ProjectStatus::OnHold], true);
        $canPlanMaterials = $isRunning && $user->can('planMaterials', $project);
        $canManageMaterials = $user->can('manageMaterials', $project) && $project->status !== ProjectStatus::Completed;
        $isLogistics = $user->hasAnyRole(['LOGISTICS', 'SUPERADMIN']);

        $project->load(['pm:id,name', 'lead:id,client_name']);

        return Inertia::render('Projects/Show', [
            'project' => $project,
            'canEditProject' => $canEditProject,
            'canChangePm' => $canChangePm,
            'projectManagers' => $canChangePm
                ? User::role('PM')->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            // Once any termin received money the contract value is fixed (ProjectService::update()).
            'hasTerminPayments' => $canEditProject && $project->hasTerminPayments(),
            'statusNote' => $projectService->statusNote($project),
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
                    // A DONE task whose wage is paid is locked (TaskService::update()).
                    ->when($canManageTasks, fn ($query) => $query->withExists('wagePayment as is_wage_paid'))
                    ->latest()
                    ->get(),
            'canViewTasks' => $taskVisibility !== 'none',
            // `is_active` lets the task form offer only active tukang while
            // still showing a deactivated current assignee.
            'fieldStaff' => $canManageTasks ? User::role('FIELD_STAFF')->orderBy('name')->get(['id', 'name', 'is_active']) : [],
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
            'allocationBreakdown' => $canViewFinanceSummary ? $allocationService->breakdownFor($project) : [],
            'supplierDebts' => $canViewFinanceSummary ? $supplierDebtService->outstandingForProject($project) : [],
            'projectMaterials' => $canViewMaterials
                ? $project->projectMaterials()
                    ->with(['material:id,name,unit_id,stock,cost_price', 'unit:id,code,name', 'vendor:id,name', 'requester:id,name'])
                    ->orderBy('source')
                    ->orderBy('id')
                    ->get()
                : [],
            'canViewMaterials' => $canViewMaterials,
            // Sprint 11 Sub 3 — which lifecycle actions the tab offers (re-checked server-side).
            'materialPermissions' => [
                'create' => $canPlanMaterials,
                'update' => $isRunning && $canManageMaterials,
                'delete' => $isLogistics,
                'receive' => $isRunning && $canManageMaterials,
                'issue' => $isRunning && $isLogistics,
                'settle' => $canManageMaterials,
                'return' => $isLogistics && $project->status !== ProjectStatus::Completed,
                // Sub 4 — out-of-catalog requests; Logistics decides on its own queue page.
                'request' => $isRunning && $user->can('request', [ProjectMaterial::class, $project]),
                'pmDecide' => $user->hasRole('SUPERADMIN') || ($user->hasRole('PM') && (int) $project->pm_id === (int) $user->id),
                'review' => $isLogistics,
            ],
            'units' => $isRunning && $canViewMaterials ? Unit::options() : [],
            'materialOptions' => $canPlanMaterials ? Material::active()->orderBy('name')->get(['id', 'code', 'name', 'unit_id', 'stock', 'cost_price']) : [],
            // Return-to-stock of a CUSTOM line maps it onto a catalog item (Logistics only).
            'catalogOptions' => $isLogistics && $canViewMaterials ? Material::active()->orderBy('name')->get(['id', 'code', 'name', 'unit_id']) : [],
            'vendors' => $canManageMaterials || $canPlanMaterials ? Vendor::options() : [],
            // Registering a custom leftover as a catalog item on return (Logistics, §5.5).
            'materialCategories' => $isLogistics && $canViewMaterials ? MaterialCategory::options() : [],
        ]);
    }

    public function store(StoreProjectRequest $request, ProjectService $service): RedirectResponse
    {
        $lead = Lead::findOrFail($request->validated('lead_id'));

        $project = $service->createFromLead($lead, $request->validated());

        return redirect()->route('projects.show', $project)->with('success', 'Proyek berhasil dibuat.');
    }

    /** Sprint 9 "Edit Proyek" — rules in ProjectService::update(), ownership in ProjectPolicy::update(). */
    public function update(UpdateProjectRequest $request, Project $project, ProjectService $service): RedirectResponse
    {
        $this->authorize('update', $project);

        $service->update($project, $request->validated(), $request->user());

        return back()->with('success', 'Proyek berhasil diperbarui.');
    }
}
