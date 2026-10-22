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
use App\Models\Termin;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Policies\ProjectPolicy;
use App\Services\FinanceAllocationService;
use App\Services\ProductionService;
use App\Services\ProjectBudgetService;
use App\Services\ProjectExecutionService;
use App\Services\ProjectMaterialService;
use App\Services\ProjectService;
use App\Services\SupplierDebtService;
use App\Services\WorkItemQaService;
use App\Services\WorkItemService;
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
    public function index(Request $request, ProjectService $projectService): Response
    {
        $user = $request->user();
        // Sprint 27 P6a — a Pengawas (QA/QC) starts on the projects it supervises
        // (`supervised=0` shows every project, as before — access is unchanged).
        $canFilterSupervised = $user->hasRole('QA');
        $supervised = $canFilterSupervised && $request->input('supervised', '1') !== '0';

        $projects = Project::query()
            ->with(['pm:id,name', 'lead:id,client_name'])
            // Sprint 24 D11 / Sprint 29 Sub 10 — the same progress as the project page, no query per row.
            ->withActivityCounts()
            ->byStatus($request->string('status')->value() ?: null)
            ->byPm($request->integer('pm_id') ?: null)
            ->byPhase($request->string('phase')->value() ?: null)
            ->when($supervised, fn ($query) => $query->supervisedBy($user))
            ->visibleTo($user)
            ->latest()
            ->paginate(15)
            ->withQueryString()
            ->through(function (Project $project) {
                $progress = $project->listedProgress();

                return $project->setAttribute('actual_progress', $progress['progress'])->setAttribute('progress_basis', $progress['basis'])
                    ->makeHidden(['work_rab_total', 'work_rab_progress']);
            });

        // Approved RAB Proyek waiting for "Buka Proyek" — Sprint 23 K13: the
        // approving PM opens (ProjectOpeningPolicy, per row `can_open`); the
        // CEO and the other PMs follow along read-only.
        $seesOpenings = $user->hasAnyRole(['CEO', 'PM', 'SUPERADMIN']);
        $openings = $seesOpenings
            ? ProjectOpening::query()
                ->waiting()
                ->with(['lead:id,client_name', 'quotation:id,total_amount,version,client_approved_at', 'opener:id,name,is_active'])
                ->oldest()
                ->get()
                ->each(fn (ProjectOpening $opening) => $opening->setAttribute('can_open', $user->can('open', $opening)))
            : collect();

        return Inertia::render('Projects/Index', [
            'projects' => $projects,
            'pendingOpenings' => $openings->values(),
            // The form's pickers — only for someone who may open one of them.
            'openingForm' => $openings->contains('can_open', true) ? $projectService->openingFormOptions() : null,
            'filters' => [
                ...$request->only(['status', 'pm_id', 'phase']),
                ...($canFilterSupervised ? ['supervised' => $supervised ? '1' : '0'] : []),
            ],
            'canFilterSupervised' => $canFilterSupervised,
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
        ProjectExecutionService $executionService,
        ProductionService $productionService,
        WorkItemQaService $itemQaService,
    ): Response {
        $this->authorize('view', $project);

        $user = $request->user();
        $taskVisibility = $policy->taskVisibility($user, $project);
        // Sprint 24 D6 — the project's Pengawas (QA/QC) plans its daily targets.
        $supervises = $project->isSupervisedBy($user);
        // ASISTEN_PM (Sprint 12 Sub 1) reads every tab the PM reads; its write
        // actions arrive in Sub 4 (RAB) and Sub 11 (its assigned projects).
        // Sprint 12 #30: Marketing follows milestones and progress too.
        $canViewMilestones = $user->hasAnyRole(['CEO', 'MARKETING', 'ESTIMATOR', 'PM', 'ASISTEN_PM', 'QA', 'SUPERADMIN', 'ADMIN_PROYEK']);
        // Sprint 29 Sub 07 — the Admin Proyek inputs on every project (never its money: termins, documents, budget stay off).
        $administers = $project->isAdministeredBy($user);
        // Sprint 12 #22: the Asisten PM of this project works like its PM
        // (milestones, tasks, progress) — not on allocation / realisation.
        $assists = $user->hasRole('ASISTEN_PM') && $project->isManagedBy($user);
        $canManageMilestones = $user->hasAnyRole(['CEO', 'PM', 'SUPERADMIN']) || $assists || $administers;
        $canManageTasks = $user->hasAnyRole(['PM', 'SUPERADMIN']) || $assists || $supervises || $administers;
        // Sprint 9 "Edit Proyek": CEO any project, PM their own
        // (ProjectPolicy::update()); COMPLETED/CANCELLED are read-only for
        // everyone, so the action isn't offered at all there.
        $canEditProject = $user->can('update', $project) && ! $project->isClosed();
        $canChangePm = $canEditProject && $user->hasAnyRole(['CEO', 'SUPERADMIN']);
        // PRD §7.1 "Progress Log" row: CEO/DES/PM/QA/FIN read, PM CRUD.
        $canViewProgressLogs = $user->hasAnyRole(['CEO', 'MARKETING', 'DESIGNER', 'PM', 'ASISTEN_PM', 'QA', 'FINANCE', 'SUPERADMIN', 'ADMIN_PROYEK']);
        $canManageProgressLogs = $user->hasAnyRole(['PM', 'SUPERADMIN']) || $assists || $administers;
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
        $canViewMaterials = $user->hasAnyRole(['CEO', 'ESTIMATOR', 'PM', 'ASISTEN_PM', 'LOGISTICS', 'SUPERADMIN', 'ADMIN_PROYEK']) || $supervises;
        // Sprint 11 §5.6: a PM plans/records only on their own project.
        $isRunning = in_array($project->status, [ProjectStatus::Active, ProjectStatus::OnHold], true);
        $canPlanMaterials = $isRunning && $user->can('planMaterials', $project);
        $canManageMaterials = $user->can('manageMaterials', $project) && $project->status !== ProjectStatus::Completed;
        $isLogistics = $user->hasAnyRole(['LOGISTICS', 'SUPERADMIN']);
        // Sprint 29 Sub 11 (E1) — the Pengawas / Admin Proyek never get warehouse stock or prices
        // (the server picks the source); their catalog lines go through Logistik (Rencana Bahan).
        $seesStock = $user->hasAnyRole(['CEO', 'PM', 'ASISTEN_PM', 'LOGISTICS', 'ESTIMATOR', 'FINANCE', 'SUPERADMIN']);
        $plansThroughLogistics = $isRunning && $canPlanMaterials && app(ProjectMaterialService::class)->plansThroughLogistics($project, $user);
        // Sprint 13 #3 — Tab QA: the milestone QA forms (status, findings),
        // never task data (security-standards §2). The form page itself is
        // `qa-forms.show` (role:CEO|PM|QA) — the Asisten PM reads it here.
        $canViewQa = $user->hasAnyRole(['CEO', 'PM', 'ASISTEN_PM', 'QA', 'SUPERADMIN', 'ADMIN_PROYEK']);
        // Tab Lembur — this project's overtime requests; decided on the
        // Lembur page (overtime.index, role:CEO|PM|FINANCE).
        $canViewOvertime = $user->hasAnyRole(['CEO', 'PM', 'ASISTEN_PM', 'FINANCE', 'SUPERADMIN']);

        // Sprint 23 K15 — Kepala Tim (user or typed name) and Pengawas on the overview.
        $project->load(['pm:id,name', 'assistantPm:id,name', 'lead:id,client_name', 'teamLead:id,name', 'supervisors:id,name']);

        // Sprint 29 Sub 01 (K1) — every prop that queries is a closure: a save that
        // reloads only what it changed (`only`, lib/projectReload.ts) computes
        // nothing else. The material catalog is optional — loaded once when a
        // material form first needs it (useProjectCatalog()).
        return Inertia::render('Projects/Show', [
            'project' => function () use ($project) {
                // Sprint 29 Sub 10 (B7) — progres berbobot or actual activity, and which one.
                $progress = $project->progressFigure();

                return $project->setAttribute('actual_progress', $progress['progress'])->setAttribute('progress_basis', $progress['basis']);
            },
            // Sprint 24 — the "Eksekusi" tab (phase, Gambar Kerja, TM, Pengawas, production, QC).
            'execution' => fn () => ProjectExecutionService::canView($user, $project)
                ? $executionService->overview($project, $user, $request->query('week'))
                : null,
            'canEditProject' => $canEditProject,
            'canChangePm' => $canChangePm,
            'projectManagers' => fn () => $canChangePm
                ? User::role('PM')->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            // D2 — the CEO or the project's PM may change the Asisten PM in Edit Proyek.
            'assistantPms' => fn () => $canEditProject
                ? User::role('ASISTEN_PM')->where('is_active', true)->orderBy('name')->get(['id', 'name'])
                : [],
            // Sprint 23 K15 — Kepala Tim / Pengawas pickers for Edit Proyek.
            'teamOptions' => fn () => $canEditProject ? $projectService->teamOptions() : null,
            // Once any termin received money the contract value is fixed (ProjectService::update()).
            'hasTerminPayments' => fn () => $canEditProject && $project->hasTerminPayments(),
            'statusNote' => fn () => $projectService->statusNote($project),
            'milestones' => fn () => $canViewMilestones
                ? $project->milestones()->with(['qaForm:id,milestone_id,status,rejection_count', 'workItems:id'])->get()
                : [],
            // Sprint 35 — the RAB items a timeline can hold (the Timeline form's checklist).
            'timelineItems' => fn () => $canManageMilestones && $project->workItems()->exists()
                ? app(WorkItemService::class)->timelineOptions($project)
                : [],
            'canViewMilestones' => $canViewMilestones,
            'canManageMilestones' => $canManageMilestones,
            'canManageTasks' => $canManageTasks,
            // Scoped per ProjectPolicy::taskVisibility() — QA and roles with
            // no task row in PRD §7.1 get none; Field Staff only their own.
            'tasks' => fn () => $taskVisibility === 'none'
                ? []
                : $project->tasks()
                    ->when($taskVisibility === 'own', fn ($query) => $query->where('assignee_id', $user->id))
                    ->with(['assignee:id,name', 'milestone:id,name,start_date,target_date'])
                    // A DONE task whose wage is paid is locked (TaskService::update()).
                    ->when($canManageTasks, fn ($query) => $query->withExists('wagePayment as is_wage_paid'))
                    ->latest()
                    ->get(),
            'canViewTasks' => $taskVisibility !== 'none',
            // Sprint 29 Sub 08 (B8) — wages are per RAB item: the daily target form has no "Upah".
            'usesWorkItems' => fn () => $project->workItems()->exists(),
            // `is_active` lets the task form offer only active tukang while
            // still showing a deactivated current assignee.
            'fieldStaff' => fn () => $canManageTasks ? User::role('FIELD_STAFF')->orderBy('name')->get(['id', 'name', 'is_active']) : [],
            'progressLogs' => fn () => $canViewProgressLogs
                ? $project->progressLogs()->with('logger:id,name')->get()
                : [],
            'canViewProgressLogs' => $canViewProgressLogs,
            // Sprint 25 — tab "Activity Target": daily target vs actual, numbers only.
            'activityTarget' => fn () => $canViewProgressLogs ? $productionService->activityTarget($project) : null,
            'qaForms' => fn () => $canViewQa
                ? $project->qaForms()
                    ->with(['milestone:id,name,order,status', 'reviewer:id,name'])
                    ->get(['id', 'project_id', 'milestone_id', 'reviewer_id', 'status', 'rejection_count', 'notes', 'reviewed_at', 'created_at'])
                    ->sortBy(fn ($form) => $form->milestone?->order)
                    ->values()
                : [],
            'canViewQa' => $canViewQa,
            // Sprint 29 Sub 12 — Timeline & QC › QC Item (QA per item RAB) instead of QC Timeline.
            'itemQa' => fn () => $canViewQa && $project->usesItemQa() ? $itemQaService->overview($project, $user) : null,
            'canOpenQaForm' => $user->hasAnyRole(['CEO', 'PM', 'QA', 'SUPERADMIN']),
            'overtimeRequests' => fn () => $canViewOvertime
                ? $project->overtimeRequests()->with('staff:id,name')->latest('work_date')->latest('id')->get()
                : [],
            'canViewOvertime' => $canViewOvertime,
            'canOpenOvertimeList' => $user->hasAnyRole(['CEO', 'PM', 'FINANCE', 'SUPERADMIN']),
            'canManageProgressLogs' => $canManageProgressLogs,
            'termins' => fn () => $canViewTermins
                // Sprint 28 K8 — "Pelunasan" / "Termin 2 dari 3 · 30%" (Termin::paymentLabel()), same words as Finance & the invoice.
                ? $project->termins()->withRabPosition()
                    ->with(['milestone:id,name', 'bankAccount:id,label', 'invoice:id,number,status,type,amount,reject_reason'])
                    ->orderBy('termin_number')->get()
                    ->each(fn (Termin $termin) => $termin->setRelation('project', $project)->append('payment_label'))
                : [],
            'canViewTermins' => $canViewTermins,
            'canIssueTerminInvoices' => $canIssueTerminInvoices,
            'canViewFinanceSummary' => $canViewFinanceSummary,
            'canMarkTerminPaid' => $canMarkTerminPaid,
            // Sprint 17 Sub 07 — "Tandai Klien Sudah Bayar" on termin & document invoice rows.
            'canSubmitInvoiceProof' => $user->hasAnyRole(['MARKETING', 'FINANCE', 'SUPERADMIN']),
            'documents' => fn () => $canViewDocuments ? [
                'quotation' => $project->quotation()->first(['id', 'type', 'version', 'total_amount', 'client_approved_at', 'status']),
                // Sprint 12 #29 — RAB Tambahan of this project, any status.
                'addenda' => $project->addenda()->get(['id', 'type', 'version', 'status', 'total_amount', 'request_note', 'client_approved_at', 'created_at']),
                'invoices' => Invoice::query()
                    ->where(fn ($query) => $query->where('project_id', $project->id)->orWhere('lead_id', $project->lead_id))
                    ->orderBy('issued_at')
                    ->get(['id', 'number', 'type', 'amount', 'due_date', 'status', 'issued_at', 'paid_date', 'reject_reason']),
            ] : null,
            'budget' => fn () => $canViewBudget ? $budgetService->overview($project) : null,
            'canManageBudget' => $canViewBudget && $user->can('manageBudget', $project) && ! $project->isClosed(),
            'canRequestAddendum' => $project->quotation_id !== null && ! $project->isClosed() && $user->can('requestAddendum', $project),
            // Sprint 12 #28 — the CEO decides held realisations from the tab too.
            'canDecideOverrun' => $canViewBudget && $user->hasAnyRole(['CEO', 'SUPERADMIN']),
            'budgetVendors' => fn () => $canViewBudget && $user->can('manageBudget', $project) ? Vendor::options() : [],
            'allocationBreakdown' => fn () => $canViewFinanceSummary ? $allocationService->breakdownFor($project) : [],
            'supplierDebts' => fn () => $canViewFinanceSummary ? $supplierDebtService->outstandingForProject($project) : [],
            'projectMaterials' => fn () => $canViewMaterials
                ? $project->projectMaterials()
                    ->with([$seesStock ? 'material:id,name,unit_id,stock,cost_price' : 'material:id,name,unit_id', 'unit:id,code,name', 'vendor:id,name', 'requester:id,name'])
                    ->orderBy('source')
                    ->orderBy('id')
                    ->get()
                    ->each(fn (ProjectMaterial $line) => $seesStock ? $line : $line->makeHidden(['unit_price', 'cost_total']))
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
                // Sprint 29 Sub 11 — stock & cost columns (E1); the Rencana Bahan: custom lines and
                // "Ajukan ke Logistik" (E2/E3); Logistik decides it on Pemesanan Bahan.
                'seeCost' => $seesStock,
                'planThroughLogistics' => $plansThroughLogistics,
                'openOrders' => $user->hasAnyRole(['CEO', 'PM', 'ASISTEN_PM', 'LOGISTICS', 'SUPERADMIN']),
            ],
            'units' => fn () => $isRunning && $canViewMaterials ? Unit::options() : [],
            'materialOptions' => Inertia::optional(fn () => $canPlanMaterials
                ? Material::active()->orderBy('name')->get($seesStock ? ['id', 'code', 'name', 'unit_id', 'stock', 'cost_price'] : ['id', 'code', 'name', 'unit_id'])
                : []),
            // Return-to-stock of a CUSTOM line maps it onto a catalog item (Logistics only).
            'catalogOptions' => Inertia::optional(fn () => $isLogistics && $canViewMaterials ? Material::active()->orderBy('name')->get(['id', 'code', 'name', 'unit_id']) : []),
            'vendors' => fn () => $canManageMaterials || $canPlanMaterials ? Vendor::options() : [],
            // Registering a custom leftover as a catalog item on return (Logistics, §5.5).
            'materialCategories' => fn () => $isLogistics && $canViewMaterials ? MaterialCategory::options() : [],
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
