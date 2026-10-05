<?php

namespace App\Http\Controllers\Projects;

use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Models\Invoice;
use App\Models\Material;
use App\Models\MaterialCategory;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\ProjectOpening;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Policies\ProjectPolicy;
use App\Services\FinanceAllocationService;
use App\Services\ProjectBudgetService;
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
            ->visibleTo($user)
            ->latest()
            ->paginate(15)
            ->withQueryString();

        // Sprint 12 #19 — approved RAB Proyek waiting for the CEO's "Buka Proyek".
        $seesOpenings = $user->hasAnyRole(['CEO', 'PM', 'SUPERADMIN']);
        $canOpen = $user->hasAnyRole(['CEO', 'SUPERADMIN']);

        return Inertia::render('Projects/Index', [
            'projects' => $projects,
            'pendingOpenings' => $seesOpenings ? ProjectOpening::query()
                ->waiting()
                ->with(['lead:id,client_name', 'quotation:id,total_amount,version,client_approved_at'])
                ->oldest()
                ->get() : [],
            'canOpenProjects' => $canOpen,
            'assistantPms' => $canOpen ? User::role('ASISTEN_PM')->where('is_active', true)->orderBy('name')->get(['id', 'name']) : [],
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
        ProjectBudgetService $budgetService,
    ): Response {
        $this->authorize('view', $project);

        $user = $request->user();
        $taskVisibility = $policy->taskVisibility($user);
        // ASISTEN_PM (Sprint 12 Sub 1) reads every tab the PM reads; its write
        // actions arrive in Sub 4 (RAB) and Sub 11 (its assigned projects).
        // Sprint 12 #30: Marketing follows milestones and progress too.
        $canViewMilestones = $user->hasAnyRole(['CEO', 'MARKETING', 'ESTIMATOR', 'PM', 'ASISTEN_PM', 'QA', 'SUPERADMIN']);
        // Sprint 12 #22: the Asisten PM of this project works like its PM
        // (milestones, tasks, progress) — not on allocation / realisation.
        $assists = $user->hasRole('ASISTEN_PM') && $project->isManagedBy($user);
        $canManageMilestones = $user->hasAnyRole(['CEO', 'PM', 'SUPERADMIN']) || $assists;
        $canManageTasks = $user->hasAnyRole(['PM', 'SUPERADMIN']) || $assists;
        // Sprint 9 "Edit Proyek": CEO any project, PM their own
        // (ProjectPolicy::update()); COMPLETED/CANCELLED are read-only for
        // everyone, so the action isn't offered at all there.
        $canEditProject = $user->can('update', $project) && ! $project->isClosed();
        $canChangePm = $canEditProject && $user->hasAnyRole(['CEO', 'SUPERADMIN']);
        // PRD §7.1 "Progress Log" row: CEO/DES/PM/QA/FIN read, PM CRUD.
        $canViewProgressLogs = $user->hasAnyRole(['CEO', 'MARKETING', 'DESIGNER', 'PM', 'ASISTEN_PM', 'QA', 'FINANCE', 'SUPERADMIN']);
        $canManageProgressLogs = $user->hasAnyRole(['PM', 'SUPERADMIN']) || $assists;
        // PRD §7.1 "Finance – Termin" row: CEO/FIN read, PM create-only —
        // PM sees what they scheduled through this project-scoped prop
        // rather than the Finance-only global list (finance.termins.index).
        // Sprint 12 #30: Marketing sees termins / invoices / payment status
        // and #20 issues the termin invoices; termins are no longer created
        // by hand (they come from the approved scheme, Sub 7).
        $canViewTermins = $user->hasAnyRole(['CEO', 'PM', 'ASISTEN_PM', 'FINANCE', 'MARKETING', 'SUPERADMIN']);
        $canIssueTerminInvoices = $user->hasAnyRole(['MARKETING', 'SUPERADMIN']);
        $canMarkTerminPaid = $user->hasAnyRole(['FINANCE', 'SUPERADMIN']);
        // Budget allocation + outstanding supplier debts (PRD §4.7) follow the
        // "Finance – Transaction" row: CEO/PM/FIN read — never Marketing (#30).
        $canViewFinanceSummary = $user->hasAnyRole(['CEO', 'PM', 'ASISTEN_PM', 'FINANCE', 'SUPERADMIN']);
        // Tab Dokumen — RAB Fix + invoices (Sprint 12 #21).
        $canViewDocuments = $user->hasAnyRole(['CEO', 'PM', 'ASISTEN_PM', 'FINANCE', 'MARKETING', 'SUPERADMIN']);
        // Sprint 12 #23–#26 — Alokasi Dana Proyek: CEO / Finance / PMs read,
        // the project's own PM writes; never sent to Marketing or the Asisten PM.
        $canViewBudget = $user->can('viewBudget', $project);
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
        // Sprint 13 #3 — Tab QA: the milestone QA forms (status, findings),
        // never task data (security-standards §2). The form page itself is
        // `qa-forms.show` (role:CEO|PM|QA) — the Asisten PM reads it here.
        $canViewQa = $user->hasAnyRole(['CEO', 'PM', 'ASISTEN_PM', 'QA', 'SUPERADMIN']);
        // Tab Lembur — this project's overtime requests; decided on the
        // Lembur page (overtime.index, role:CEO|PM|FINANCE).
        $canViewOvertime = $user->hasAnyRole(['CEO', 'PM', 'ASISTEN_PM', 'FINANCE', 'SUPERADMIN']);

        $project->load(['pm:id,name', 'assistantPm:id,name', 'lead:id,client_name']);

        return Inertia::render('Projects/Show', [
            'project' => $project,
            'canEditProject' => $canEditProject,
            'canChangePm' => $canChangePm,
            'projectManagers' => $canChangePm
                ? User::role('PM')->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            // D2 — the CEO or the project's PM may change the Asisten PM in Edit Proyek.
            'assistantPms' => $canEditProject
                ? User::role('ASISTEN_PM')->where('is_active', true)->orderBy('name')->get(['id', 'name'])
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
            'qaForms' => $canViewQa
                ? $project->qaForms()
                    ->with(['milestone:id,name,order,status', 'reviewer:id,name'])
                    ->get(['id', 'project_id', 'milestone_id', 'reviewer_id', 'status', 'rejection_count', 'notes', 'reviewed_at', 'created_at'])
                    ->sortBy(fn ($form) => $form->milestone?->order)
                    ->values()
                : [],
            'canViewQa' => $canViewQa,
            'canOpenQaForm' => $user->hasAnyRole(['CEO', 'PM', 'QA', 'SUPERADMIN']),
            'overtimeRequests' => $canViewOvertime
                ? $project->overtimeRequests()->with('staff:id,name')->latest('work_date')->latest('id')->get()
                : [],
            'canViewOvertime' => $canViewOvertime,
            'canOpenOvertimeList' => $user->hasAnyRole(['CEO', 'PM', 'FINANCE', 'SUPERADMIN']),
            'canManageProgressLogs' => $canManageProgressLogs,
            'termins' => $canViewTermins
                ? $project->termins()->with(['milestone:id,name', 'bankAccount:id,label', 'invoice:id,number,status'])->orderBy('termin_number')->get()
                : [],
            'canViewTermins' => $canViewTermins,
            'canIssueTerminInvoices' => $canIssueTerminInvoices,
            'canViewFinanceSummary' => $canViewFinanceSummary,
            'canMarkTerminPaid' => $canMarkTerminPaid,
            'documents' => $canViewDocuments ? [
                'quotation' => $project->quotation()->first(['id', 'type', 'version', 'total_amount', 'client_approved_at', 'status']),
                // Sprint 12 #29 — RAB Tambahan of this project, any status.
                'addenda' => $project->addenda()->get(['id', 'type', 'version', 'status', 'total_amount', 'request_note', 'client_approved_at', 'created_at']),
                'invoices' => Invoice::query()
                    ->where(fn ($query) => $query->where('project_id', $project->id)->orWhere('lead_id', $project->lead_id))
                    ->orderBy('issued_at')
                    ->get(['id', 'number', 'type', 'amount', 'due_date', 'status', 'issued_at', 'paid_date']),
            ] : null,
            'budget' => $canViewBudget ? $budgetService->overview($project) : null,
            'canManageBudget' => $canViewBudget && $user->can('manageBudget', $project) && ! $project->isClosed(),
            'canRequestAddendum' => $project->quotation_id !== null && ! $project->isClosed() && $user->can('requestAddendum', $project),
            // Sprint 12 #28 — the CEO decides held realisations from the tab too.
            'canDecideOverrun' => $canViewBudget && $user->hasAnyRole(['CEO', 'SUPERADMIN']),
            'budgetVendors' => $canViewBudget && $user->can('manageBudget', $project) ? Vendor::options() : [],
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
                'pmDecide' => $user->hasRole('SUPERADMIN') || $project->isManagedBy($user),
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

    /** Sprint 9 "Edit Proyek" — rules in ProjectService::update(), ownership in ProjectPolicy::update(). */
    public function update(UpdateProjectRequest $request, Project $project, ProjectService $service): RedirectResponse
    {
        $this->authorize('update', $project);

        $service->update($project, $request->validated(), $request->user());

        return back()->with('success', 'Proyek berhasil diperbarui.');
    }
}
