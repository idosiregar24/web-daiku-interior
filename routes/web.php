<?php

use App\Http\Controllers\Analytics\AnalyticsController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\UserController;
use App\Http\Controllers\CRM\LeadController;
use App\Http\Controllers\CRM\LeadFollowUpController;
use App\Http\Controllers\CRM\LeadSurveyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Design\DesignController;
use App\Http\Controllers\Design\DesignDashboardController;
use App\Http\Controllers\Finance\AssetInstallmentController;
use App\Http\Controllers\Finance\FamilyGatheringFundController;
use App\Http\Controllers\Finance\FinanceAllocationConfigController;
use App\Http\Controllers\Finance\FinanceTransactionController;
use App\Http\Controllers\Finance\FundTransferController;
use App\Http\Controllers\Finance\PayrollController;
use App\Http\Controllers\Finance\PenaltyController;
use App\Http\Controllers\Finance\StaffLoanController;
use App\Http\Controllers\Finance\SupplierDebtController;
use App\Http\Controllers\Finance\TerminController;
use App\Http\Controllers\HR\HrDashboardController;
use App\Http\Controllers\HR\MyHrController;
use App\Http\Controllers\Logistics\AssetController;
use App\Http\Controllers\Logistics\MaterialController;
use App\Http\Controllers\Logistics\MaterialRequestController;
use App\Http\Controllers\Logistics\ProjectMaterialController;
use App\Http\Controllers\Logistics\StockMovementController;
use App\Http\Controllers\MasterData\BankAccountController;
use App\Http\Controllers\MasterData\BranchController;
use App\Http\Controllers\MasterData\LeadCategoryController;
use App\Http\Controllers\MasterData\LeadSourceController;
use App\Http\Controllers\MasterData\MasterDataController;
use App\Http\Controllers\MasterData\MaterialCategoryController;
use App\Http\Controllers\MasterData\MaterialSynonymController;
use App\Http\Controllers\MasterData\UnitController;
use App\Http\Controllers\MasterData\VendorController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Overtime\OvertimeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Projects\MilestoneController;
use App\Http\Controllers\Projects\ProgressLogController;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\Projects\ProjectDashboardController;
use App\Http\Controllers\Projects\TaskController;
use App\Http\Controllers\QA\QaDashboardController;
use App\Http\Controllers\QA\QaFormController;
use App\Http\Controllers\Quotation\QuotationController;
use App\Http\Controllers\Quotation\QuotationDashboardController;
use App\Http\Controllers\Settings\BrandingAssetController;
use App\Http\Controllers\Settings\SiteSettingController;
use App\Http\Controllers\Tasks\DailyTaskFormController;
use App\Models\SiteSetting;
use App\Services\RoleRedirectService;
use Illuminate\Support\Facades\Route;

// Internal enterprise system — no public marketing page, so `/` just
// routes straight into the app instead of Breeze's default Welcome
// template (which had no purpose here and was never removed).
Route::get('/', function (RoleRedirectService $roleRedirect) {
    if (! auth()->check()) {
        return redirect()->route('login');
    }

    return redirect()->route($roleRedirect->routeNameFor(auth()->user()));
});

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // PRD §7.1 "Notification (own)" row — every role, own rows only
    // (ownership enforced in NotificationController, not a role check).
    Route::get('/notifications', [NotificationController::class, 'index'])
        ->name('notifications.index');
    Route::middleware('throttle:60,1')->group(function () {
        Route::patch('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])
            ->name('notifications.markAllAsRead');
        Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])
            ->name('notifications.markAsRead');
    });
});

// CRM — PRD §4.1 / §7.1. Read access matches the RBAC matrix's CRM–Lead
// row; write access follows §4.1's explicit "Marketing dan CEO" rule
// (broader than the matrix's MARKETING-only CRUD cell — see
// .claude/plan/README.md for why the more specific prose rule wins).
// updateStatus/confirmDeal follow the same Marketing+CEO write rule —
// status transitions and deal confirmation are part of "edit lead", not a
// separately-scoped action.
Route::middleware('auth')->prefix('crm')->name('crm.')->group(function () {
    Route::get('dashboard', [LeadController::class, 'dashboard'])
        ->middleware('role:CEO|MARKETING')
        ->name('dashboard');

    Route::get('leads', [LeadController::class, 'index'])
        ->middleware('role:CEO|MARKETING|DESIGNER|ESTIMATOR|PM')
        ->name('leads.index');

    Route::get('leads/{lead}', [LeadController::class, 'show'])
        ->middleware('role:CEO|MARKETING|DESIGNER|ESTIMATOR|PM')
        ->name('leads.show');

    Route::post('leads', [LeadController::class, 'store'])
        ->middleware('role:CEO|MARKETING')
        ->name('leads.store');

    Route::put('leads/{lead}', [LeadController::class, 'update'])
        ->middleware('role:CEO|MARKETING')
        ->name('leads.update');

    Route::patch('leads/{lead}/status', [LeadController::class, 'updateStatus'])
        ->middleware('role:CEO|MARKETING')
        ->name('leads.updateStatus');

    Route::post('leads/{lead}/confirm-deal', [LeadController::class, 'confirmDeal'])
        ->middleware('role:CEO|MARKETING')
        ->name('leads.confirmDeal');

    // Sprint 12 Sub 2 — "Ajukan Desain/Survey", follow-ups FU-n and site
    // surveys (decisions #2–#5). Same Marketing + CEO write rule as above.
    Route::middleware(['role:CEO|MARKETING', 'throttle:60,1'])->group(function () {
        Route::post('leads/{lead}/submit-request', [LeadController::class, 'submitRequest'])->name('leads.submitRequest');
        Route::post('leads/{lead}/follow-ups', [LeadFollowUpController::class, 'store'])->name('follow-ups.store');
        Route::post('follow-ups/{follow_up}/complete', [LeadFollowUpController::class, 'complete'])->name('follow-ups.complete');
        Route::post('leads/{lead}/surveys', [LeadSurveyController::class, 'store'])->name('surveys.store');
        Route::put('surveys/{survey}', [LeadSurveyController::class, 'update'])->name('surveys.update');
        Route::post('surveys/{survey}/complete', [LeadSurveyController::class, 'complete'])->name('surveys.complete');
        Route::post('surveys/{survey}/cancel', [LeadSurveyController::class, 'cancel'])->name('surveys.cancel');
    });
});

// Design — PRD §4.2 / §7.1 "Design Brief" row (DES has CRUD, everyone
// else on the row R). "Design – Client ACC" is its own row with different
// access (MKT + DES both U, not CEO/PM) — PRD explicitly gives Marketing
// a say here since they're the ones talking to the client.
Route::post('crm/leads/{lead}/design', [DesignController::class, 'store'])
    ->middleware(['auth', 'role:DESIGNER'])
    ->name('crm.leads.design.store');

Route::middleware('auth')->prefix('design')->name('design.')->group(function () {
    Route::get('/', [DesignController::class, 'index'])
        ->middleware('role:CEO|MARKETING|DESIGNER|ESTIMATOR|PM|QA')
        ->name('index');

    // KPI Desain (PRD §4.2 KPI per PIC + omset desain) — "Analytics – Per
    // Divisi" (§7.1 `P`), CEO + Designer. Before `{design}` so it isn't
    // swallowed by the wildcard.
    Route::get('dashboard', [DesignDashboardController::class, 'index'])
        ->middleware('role:CEO|DESIGNER')
        ->name('dashboard');

    Route::get('{design}', [DesignController::class, 'show'])
        ->middleware('role:CEO|MARKETING|DESIGNER|ESTIMATOR|PM|QA')
        ->name('show');

    Route::put('{design}', [DesignController::class, 'update'])
        ->middleware('role:DESIGNER')
        ->name('update');

    Route::post('{design}/client-acc', [DesignController::class, 'clientAcc'])
        ->middleware('role:MARKETING|DESIGNER')
        ->name('clientAcc');
});

// Quotation — PRD §4.3 / §7.1 "Quotation" row: broad read, Estimator-only
// CRUD (RAB builder). "Quotation Approval" is its own matrix row — CEO and
// PM both `U` only, sequential (QuotationService enforces the order via
// its state machine, see that class's docblock).
Route::middleware('auth')->prefix('quotations')->name('quotations.')->group(function () {
    Route::get('/', [QuotationController::class, 'index'])
        ->middleware('role:CEO|MARKETING|DESIGNER|ESTIMATOR|PM|ASISTEN_PM|FINANCE')
        ->name('index');

    // Dashboard Quotation (Estimator's "Analytics – Per Divisi", §7.1 `P`)
    // — CEO + Estimator. Before `{quotation}` (wildcard).
    Route::get('dashboard', [QuotationDashboardController::class, 'index'])
        ->middleware('role:CEO|ESTIMATOR')
        ->name('dashboard');

    Route::get('{quotation}', [QuotationController::class, 'show'])
        ->middleware('role:CEO|MARKETING|DESIGNER|ESTIMATOR|PM|ASISTEN_PM|FINANCE')
        ->name('show');

    Route::get('{quotation}/pdf', [QuotationController::class, 'exportPdf'])
        ->middleware('role:CEO|MARKETING|DESIGNER|ESTIMATOR|PM|ASISTEN_PM|FINANCE')
        ->name('pdf');

    // Sprint 12 #11 — the RAB in the Estimator's Excel layout.
    Route::get('{quotation}/excel', [QuotationController::class, 'exportExcel'])
        ->middleware('role:CEO|MARKETING|DESIGNER|ESTIMATOR|PM|ASISTEN_PM|FINANCE')
        ->name('excel');

    Route::put('{quotation}/items', [QuotationController::class, 'updateItems'])
        ->middleware('role:ESTIMATOR')
        ->name('items.update');

    // Sprint 12 #7 / #12 — pick up a RAB Marketing asked for; the DP/termin scheme.
    Route::post('{quotation}/start', [QuotationController::class, 'startDraft'])
        ->middleware('role:ESTIMATOR')
        ->name('start');

    Route::put('{quotation}/payment-terms', [QuotationController::class, 'updatePaymentTerms'])
        ->middleware('role:ESTIMATOR')
        ->name('paymentTerms.update');

    Route::post('{quotation}/submit', [QuotationController::class, 'submit'])
        ->middleware('role:ESTIMATOR')
        ->name('submit');

    // Sprint 12 #7–#10 (replaces PRD §7.1's CEO → PM "Quotation Approval"):
    // PM / Asisten PM review every item first, the CEO second (RAB Proyek
    // only) — QuotationService::reviewStage() picks the stage from the status.
    Route::post('{quotation}/review', [QuotationController::class, 'review'])
        ->middleware('role:PM|ASISTEN_PM|CEO')
        ->name('review');

    Route::post('{quotation}/send-to-marketing', [QuotationController::class, 'sendToMarketing'])
        ->middleware('role:ESTIMATOR')
        ->name('sendToMarketing');

    Route::post('{quotation}/send-to-client', [QuotationController::class, 'sendToClient'])
        ->middleware('role:CEO|MARKETING')
        ->name('sendToClient');

    Route::post('{quotation}/cancel', [QuotationController::class, 'cancel'])
        ->middleware('role:CEO|MARKETING')
        ->name('cancel');

    // PRD §6.2 "SENT TO CLIENT → REJECTED (klien) → DRAFT (revisi)" — the
    // client's decision is recorded by the people talking to the client:
    // the same CEO/Marketing actors as `crm.leads.confirmDeal` (its
    // acceptance counterpart).
    Route::post('{quotation}/client-reject', [QuotationController::class, 'clientReject'])
        ->middleware('role:CEO|MARKETING')
        ->name('clientReject');
});

// Projects — PRD §4.4 / §7.1 "Project (overview)" row: broad read access,
// PM (+ CEO, admin oversight) for create. Field Staff read is scoped to
// their own assigned tasks inside ProjectController@index (matrix's R*).
Route::middleware('auth')->prefix('projects')->name('projects.')->group(function () {
    Route::get('/', [ProjectController::class, 'index'])
        ->middleware('role:CEO|MARKETING|DESIGNER|ESTIMATOR|PM|ASISTEN_PM|QA|FINANCE|LOGISTICS|FIELD_STAFF')
        ->name('index');

    // Monitor Proyek — PRD §4.4 "Overdue Monitor: Dashboard khusus PM".
    // CEO (all PMs) + PM (own projects, scoped in DivisionDashboardService)
    // + ASISTEN_PM (Sprint 12 — read-only, its landing page).
    // Before `/{project}` (wildcard).
    Route::get('/dashboard', [ProjectDashboardController::class, 'index'])
        ->middleware('role:CEO|PM|ASISTEN_PM')
        ->name('dashboard');

    Route::get('/{project}', [ProjectController::class, 'show'])
        ->middleware('role:CEO|MARKETING|DESIGNER|ESTIMATOR|PM|ASISTEN_PM|QA|FINANCE|LOGISTICS|FIELD_STAFF')
        ->name('show');

    Route::post('/', [ProjectController::class, 'store'])
        ->middleware('role:CEO|PM')
        ->name('store');

    // Sprint 9 "Edit Proyek": CEO any project, PM only their own
    // (ProjectPolicy::update()); PM re-assignment CEO-only (PRD §4.4).
    Route::put('/{project}', [ProjectController::class, 'update'])
        ->middleware('role:CEO|PM')
        ->name('update');
});

// Milestones — PRD §7.1 "Milestone" row: PM has CRUD (+ CEO oversight,
// matching the Project store precedent above). Nested under project for
// store, flat for update/destroy since Milestone already carries its
// project_id.
Route::middleware(['auth', 'role:CEO|PM'])->group(function () {
    Route::post('projects/{project}/milestones', [MilestoneController::class, 'store'])
        ->name('milestones.store');

    Route::put('milestones/{milestone}', [MilestoneController::class, 'update'])
        ->name('milestones.update');

    Route::delete('milestones/{milestone}', [MilestoneController::class, 'destroy'])
        ->name('milestones.destroy');

    Route::post('milestones/{milestone}/mark-done', [MilestoneController::class, 'markDone'])
        ->name('milestones.markDone');
});

// Progress Log — PRD §7.1 "Progress Log" row: CEO/DES/PM/QA/FIN read (see
// ProjectController::show()'s `progressLogs` prop, no separate index
// route), PM CRUD (create only implemented — logs are append-only).
Route::post('projects/{project}/progress-logs', [ProgressLogController::class, 'store'])
    ->middleware(['auth', 'role:PM'])
    ->name('progress-logs.store');

// Dashboard QA (QA's "Analytics – Per Divisi", §7.1 `P`) — CEO + QA, no
// task data (PRD §4.6). Registered before the group's `{qa_form}` wildcard.
Route::get('qa-forms/dashboard', [QaDashboardController::class, 'index'])
    ->middleware(['auth', 'role:CEO|QA'])
    ->name('qa-forms.dashboard');

// QA — PRD §4.6 / §7.1 "QA Form" row: CEO/PM read, QA CRUD (review only —
// rows themselves are system-created, see QaFormService's docblock).
Route::middleware(['auth', 'role:CEO|PM|QA'])->prefix('qa-forms')->name('qa-forms.')->group(function () {
    Route::get('/', [QaFormController::class, 'index'])->name('index');
    Route::get('{qa_form}', [QaFormController::class, 'show'])->name('show');
});

Route::put('qa-forms/{qa_form}', [QaFormController::class, 'update'])
    ->middleware(['auth', 'role:QA'])
    ->name('qa-forms.update');

// Tasks — PRD §7.1 "Task – Create/Edit" (PM CRUD, CEO R-only) / "Task –
// Update Status" (PM U, Field Staff U† own task only — enforced by
// TaskPolicy, see AppServiceProvider's SUPERADMIN Gate::before bypass).
Route::middleware(['auth', 'role:CEO|PM|FIELD_STAFF'])->prefix('tasks')->name('tasks.')->group(function () {
    Route::get('/', [TaskController::class, 'index'])->name('index');
});

Route::post('projects/{project}/tasks', [TaskController::class, 'store'])
    ->middleware(['auth', 'role:PM'])
    ->name('tasks.store');

Route::patch('tasks/{task}/status', [TaskController::class, 'updateStatus'])
    ->middleware(['auth', 'role:PM|FIELD_STAFF', 'throttle:60,1'])
    ->name('tasks.updateStatus');

// Sprint 9 — full edit / delete: PM only ("Task – Create/Edit" CRUD).
// Field Staff get 403 (task immutability, CLAUDE.md golden rule #6).
Route::middleware(['auth', 'role:PM'])->group(function () {
    Route::put('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
});

// Daily Task Form — PRD §4.5 / §7.1 "Daily Task Form" row (CEO/PM read,
// Field Staff create+read own — see DailyTaskFormController::index()).
Route::middleware(['auth', 'role:CEO|PM|FIELD_STAFF'])->prefix('daily-forms')->name('daily-forms.')->group(function () {
    Route::get('/', [DailyTaskFormController::class, 'index'])->name('index');
});

Route::post('tasks/{task}/daily-form', [DailyTaskFormController::class, 'store'])
    ->middleware(['auth', 'role:FIELD_STAFF', 'throttle:60,1'])
    ->name('daily-forms.store');

// Family Gathering Fund — PRD §4.7/§6.5 / §7.1 "Finance – Family Fund"
// row (CEO read, Finance CRUD). Income rows are only ever written by
// PenaltyService's automated job; the manual action here is Finance
// recording an EXPENSE ("Penggunaan Dana" — PRD §4.7 business rule).
Route::middleware(['auth', 'role:CEO|FINANCE'])->prefix('family-fund')->name('family-fund.')->group(function () {
    Route::get('/', [FamilyGatheringFundController::class, 'index'])->name('index');
});

Route::post('family-fund/expense', [FamilyGatheringFundController::class, 'recordExpense'])
    ->middleware(['auth', 'role:FINANCE'])
    ->name('family-fund.recordExpense');

// Penalty — PRD §7.1 "Penalty – View" row: CEO/PM/Finance read all, Field
// Staff read own only (`R*`). Penalties themselves are only written by the
// automated job; Finance records a tukang's manual payment (Sprint 9
// decision #10 — a PEMASUKAN PENALTY_COLLECT). No edit/destroy (PRD §9.4).
Route::get('penalties', [PenaltyController::class, 'index'])
    ->middleware(['auth', 'role:CEO|PM|FINANCE|FIELD_STAFF'])
    ->name('penalties.index');

Route::post('penalties/payments', [PenaltyController::class, 'recordPayment'])
    ->middleware(['auth', 'role:FINANCE', 'throttle:60,1'])
    ->name('penalties.recordPayment');

// Termin — PRD §4.4/§4.7/§6.4 / §7.1 "Finance – Termin" row: CEO/FIN read,
// PM create-only (nested under project — see ProjectController::show()'s
// `termins` prop), Finance read+update (mark paid).
Route::post('projects/{project}/termins', [TerminController::class, 'store'])
    ->middleware(['auth', 'role:PM'])
    ->name('projects.termins.store');

Route::middleware('auth')->prefix('finance')->name('finance.')->group(function () {
    Route::get('termins', [TerminController::class, 'index'])
        ->middleware('role:CEO|FINANCE')
        ->name('termins.index');

    Route::get('termins/{termin}/pdf', [TerminController::class, 'exportPdf'])
        ->middleware('role:CEO|PM|FINANCE')
        ->name('termins.pdf');

    Route::post('termins/{termin}/mark-paid', [TerminController::class, 'markPaid'])
        ->middleware('role:FINANCE')
        ->name('termins.markPaid');

    // Partial payment (DP / pelunasan) — daiku_schema.sql `termins.dp_amount`/
    // `pelunasan`/`sisa_piutang`. Same "Finance RU" cell as markPaid.
    Route::post('termins/{termin}/payments', [TerminController::class, 'recordPayment'])
        ->middleware('role:FINANCE')
        ->name('termins.recordPayment');

    // Staff Loans & Supplier Debts — PRD §4.7. No own row in §7.1, so they
    // follow "Finance – Transaction": CEO/PM read, Finance create/update.
    // Deliberately no edit/destroy routes — finance records are append-only
    // (PRD §9.4); corrections go through a new payment record.
    Route::get('staff-loans', [StaffLoanController::class, 'index'])
        ->middleware('role:CEO|PM|FINANCE')
        ->name('staffLoans.index');

    Route::get('staff-loans/create', [StaffLoanController::class, 'create'])
        ->middleware('role:FINANCE')
        ->name('staffLoans.create');

    Route::post('staff-loans', [StaffLoanController::class, 'store'])
        ->middleware('role:FINANCE')
        ->name('staffLoans.store');

    Route::get('staff-loans/{staffLoan}', [StaffLoanController::class, 'show'])
        ->middleware('role:CEO|PM|FINANCE')
        ->name('staffLoans.show');

    Route::post('staff-loans/{staffLoan}/payments', [StaffLoanController::class, 'storePayment'])
        ->middleware('role:FINANCE')
        ->name('staffLoans.storePayment');

    Route::get('supplier-debts', [SupplierDebtController::class, 'index'])
        ->middleware('role:CEO|PM|FINANCE')
        ->name('supplierDebts.index');

    Route::get('supplier-debts/create', [SupplierDebtController::class, 'create'])
        ->middleware('role:FINANCE')
        ->name('supplierDebts.create');

    Route::post('supplier-debts', [SupplierDebtController::class, 'store'])
        ->middleware('role:FINANCE')
        ->name('supplierDebts.store');

    Route::get('supplier-debts/{supplierDebt}', [SupplierDebtController::class, 'show'])
        ->middleware('role:CEO|PM|FINANCE')
        ->name('supplierDebts.show');

    Route::post('supplier-debts/{supplierDebt}/payments', [SupplierDebtController::class, 'storePayment'])
        ->middleware('role:FINANCE')
        ->name('supplierDebts.storePayment');

    // Asset installments (Aset & Cicilan) — PRD §4.7, Sprint 9 decision #8.
    // Read like §7.1 "Asset Inventory" (CEO/PM/FIN read, LOG CRUD); the plan
    // is set by Logistics on the asset form (logistics.assets.*), but every
    // payment is a finance transaction (PENGELUARAN / ANGSURAN), so only
    // Finance records it. Append-only ledger — no edit/destroy routes.
    Route::get('asset-installments', [AssetInstallmentController::class, 'index'])
        ->middleware('role:CEO|PM|FINANCE|LOGISTICS')
        ->name('assetInstallments.index');

    Route::get('asset-installments/{asset}', [AssetInstallmentController::class, 'show'])
        ->middleware('role:CEO|PM|FINANCE|LOGISTICS')
        ->name('assetInstallments.show');

    Route::post('asset-installments/{asset}/payments', [AssetInstallmentController::class, 'storePayment'])
        ->middleware('role:FINANCE')
        ->name('assetInstallments.storePayment');

    // Payroll (Gaji Karyawan Tetap) — PRD §4.7, Sprint 9 decision #7.
    // Salaries are confidential: CEO reads, Finance pays; every other role
    // (PM included) gets 403. The employee list itself is managed by HR
    // since Sprint 10 (routes/hr/employees.php). Salary payments are
    // append-only — deliberately no destroy routes.
    Route::get('payroll', [PayrollController::class, 'index'])
        ->middleware('role:CEO|FINANCE')
        ->name('payroll.index');

    Route::post('payroll/payments', [PayrollController::class, 'pay'])
        ->middleware('role:FINANCE')
        ->name('payroll.pay');

    // Allocation percentages — PRD §4.7 "dikonfigurasi di
    // finance_allocation_configs dan bisa diubah CEO/Finance". Rows are
    // deactivated (is_active), never deleted.
    Route::middleware('role:CEO|FINANCE')->group(function () {
        Route::get('allocations', [FinanceAllocationConfigController::class, 'index'])
            ->name('allocations.index');
        Route::post('allocations', [FinanceAllocationConfigController::class, 'store'])
            ->name('allocations.store');
        Route::put('allocations/{allocation}', [FinanceAllocationConfigController::class, 'update'])
            ->name('allocations.update');
    });

    // Finance Transaction — PRD §7.1 "Finance – Transaction" row:
    // CEO/PM read, Finance CRUD.
    Route::get('transactions', [FinanceTransactionController::class, 'index'])
        ->middleware('role:CEO|PM|FINANCE')
        ->name('transactions.index');

    Route::post('transactions', [FinanceTransactionController::class, 'store'])
        ->middleware('role:FINANCE')
        ->name('transactions.store');

    Route::get('transactions/export', [FinanceTransactionController::class, 'exportExcel'])
        ->middleware('role:CEO|PM|FINANCE')
        ->name('transactions.export');

    // Pindah Dana between the company's own accounts — two PINDAH_DANA
    // legs (FundTransferService). Same "Finance – Transaction" row:
    // Finance creates, CEO/PM read the legs above. Create-only.
    Route::post('transfers', [FundTransferController::class, 'store'])
        ->middleware(['role:FINANCE', 'throttle:60,1'])
        ->name('transfers.store');

    Route::get('dashboard', [FinanceTransactionController::class, 'dashboard'])
        ->middleware('role:CEO|PM|FINANCE')
        ->name('dashboard');

    Route::get('staff-payments', [FinanceTransactionController::class, 'staffPayments'])
        ->middleware('role:CEO|PM|FINANCE')
        ->name('staffPayments.index');

    Route::post('staff-payments/{task}', [FinanceTransactionController::class, 'payStaff'])
        ->middleware('role:FINANCE')
        ->name('staffPayments.pay');
});

// Overtime — PRD §4.5/§6.6 / §7.1 "Overtime Request" row: PM and Finance
// both RU (sequential approval, PM then Finance), Field Staff CR (own).
Route::middleware(['auth', 'role:CEO|PM|FINANCE|FIELD_STAFF'])->prefix('overtime')->name('overtime.')->group(function () {
    Route::get('/', [OvertimeController::class, 'index'])->name('index');
});

Route::middleware(['auth', 'role:FIELD_STAFF', 'throttle:60,1'])->group(function () {
    Route::post('overtime', [OvertimeController::class, 'store'])->name('overtime.store');
});

Route::middleware(['auth', 'role:PM'])->group(function () {
    Route::post('overtime/{overtime_request}/pm-approve', [OvertimeController::class, 'pmApprove'])->name('overtime.pmApprove');
    Route::post('overtime/{overtime_request}/pm-reject', [OvertimeController::class, 'pmReject'])->name('overtime.pmReject');
});

Route::middleware(['auth', 'role:FINANCE'])->group(function () {
    Route::post('overtime/{overtime_request}/finance-approve', [OvertimeController::class, 'financeApprove'])->name('overtime.financeApprove');
    Route::post('overtime/{overtime_request}/finance-reject', [OvertimeController::class, 'financeReject'])->name('overtime.financeReject');
});

// Logistics — PRD §4.8 / §7.1. "Material – Master": CEO/EST/PM read (EST
// needs prices for quotations), LOG CRUD. "Material – Stok": CEO/PM read
// the ledger, LOG records receipts/usage. "Asset Inventory": CEO/PM/FIN
// read, LOG CRUD.
Route::middleware('auth')->prefix('logistics')->name('logistics.')->group(function () {
    Route::get('materials', [MaterialController::class, 'index'])
        ->middleware('role:CEO|ESTIMATOR|PM|LOGISTICS')
        ->name('materials.index');
    Route::get('materials/export', [MaterialController::class, 'exportExcel'])
        ->middleware('role:CEO|ESTIMATOR|PM|LOGISTICS')
        ->name('materials.export');

    Route::get('stock-movements', [StockMovementController::class, 'index'])
        ->middleware('role:CEO|PM|LOGISTICS')
        ->name('stock-movements.index');

    Route::get('assets', [AssetController::class, 'index'])
        ->middleware('role:CEO|PM|FINANCE|LOGISTICS')
        ->name('assets.index');
    Route::get('assets/export', [AssetController::class, 'exportExcel'])
        ->middleware('role:CEO|PM|FINANCE|LOGISTICS')
        ->name('assets.export');

    // Sprint 11 Sub 5 (§5.5) — similar-item lookup while typing, the Cek
    // Duplikat page and merging. Logistics only ("satu pintu", Lapis 5).
    Route::middleware('role:LOGISTICS')->group(function () {
        Route::get('materials/similar', [MaterialController::class, 'similar'])->name('materials.similar');
        Route::get('materials/duplicates', [MaterialController::class, 'duplicates'])->name('materials.duplicates');
    });

    Route::middleware(['role:LOGISTICS', 'throttle:60,1'])->group(function () {
        Route::post('materials/{material}/merge', [MaterialController::class, 'merge'])->name('materials.merge');
        Route::post('materials', [MaterialController::class, 'store'])->name('materials.store');
        Route::put('materials/{material}', [MaterialController::class, 'update'])->name('materials.update');
        Route::delete('materials/{material}', [MaterialController::class, 'destroy'])->name('materials.destroy');
        Route::post('materials/{material}/stock-in', [StockMovementController::class, 'stockIn'])->name('materials.stockIn');
        Route::post('materials/{material}/stock-out', [StockMovementController::class, 'stockOut'])->name('materials.stockOut');

        Route::post('assets', [AssetController::class, 'store'])->name('assets.store');
        Route::put('assets/{asset}', [AssetController::class, 'update'])->name('assets.update');
        Route::delete('assets/{asset}', [AssetController::class, 'destroy'])->name('assets.destroy');
    });
});

// Project Material — PRD §7.1 row: EST C, PM RU, LOG CRUD. PM may also
// create: §4.8's prose "PM/Estimator mencatat kebutuhan material per
// proyek" is the more specific rule (same precedent as CRM – Lead write
// access, see .claude/plan/README.md). Read happens on the project's own
// Material tab (ProjectController::show()).
Route::post('projects/{project}/materials', [ProjectMaterialController::class, 'store'])
    ->middleware(['auth', 'role:ESTIMATOR|PM|LOGISTICS'])
    ->name('projects.materials.store');
Route::put('project-materials/{project_material}', [ProjectMaterialController::class, 'update'])
    ->middleware(['auth', 'role:PM|LOGISTICS'])
    ->name('project-materials.update');
Route::delete('project-materials/{project_material}', [ProjectMaterialController::class, 'destroy'])
    ->middleware(['auth', 'role:LOGISTICS'])
    ->name('project-materials.destroy');

// Sprint 11 Sub 4 — "Pengajuan Barang" outside the catalog (decision #13).
// Estimator / the project's PM / a Tukang with a task there raise it
// (ProjectMaterialPolicy::request()); the PM approves a Tukang's first;
// Logistics decides. CEO reads the queue.
Route::middleware('auth')->group(function () {
    Route::get('logistics/material-requests', [MaterialRequestController::class, 'index'])
        ->middleware('role:CEO|PM|LOGISTICS|ESTIMATOR|FIELD_STAFF')
        ->name('logistics.material-requests.index');

    Route::middleware('throttle:60,1')->group(function () {
        Route::post('projects/{project}/material-requests', [MaterialRequestController::class, 'store'])
            ->middleware('role:ESTIMATOR|PM|FIELD_STAFF')
            ->name('projects.material-requests.store');
        Route::post('project-materials/{project_material}/pm-decision', [MaterialRequestController::class, 'pmDecision'])
            ->middleware('role:PM')
            ->name('project-materials.pmDecision');
        Route::post('logistics/material-requests/{project_material}/review', [MaterialRequestController::class, 'review'])
            ->middleware('role:LOGISTICS')
            ->name('logistics.material-requests.review');
    });
});

// Sprint 11 Sub 3 (§5.6) — a line's lifecycle. Issuing from and returning
// to the warehouse: Logistics only. Purchase, usage, waste and hand-over:
// Logistics, or the PM on their own project (ProjectPolicy::manageMaterials()).
Route::middleware(['auth', 'throttle:60,1'])->prefix('project-materials/{project_material}')->name('project-materials.')->group(function () {
    Route::post('issue', [ProjectMaterialController::class, 'issue'])->middleware('role:LOGISTICS')->name('issue');
    Route::post('return', [ProjectMaterialController::class, 'returnToWarehouse'])->middleware('role:LOGISTICS')->name('return');
    Route::middleware('role:PM|LOGISTICS')->group(function () {
        Route::post('purchase', [ProjectMaterialController::class, 'purchase'])->name('purchase');
        Route::post('usage', [ProjectMaterialController::class, 'usage'])->name('usage');
        Route::post('waste', [ProjectMaterialController::class, 'waste'])->name('waste');
        Route::post('hand-over', [ProjectMaterialController::class, 'handOver'])->name('handOver');
    });
});

// Analytics – Executive — PRD §4.10 / §7.1: CEO only, FULL. Division
// roles get partial dashboards elsewhere (crm.dashboard, finance.dashboard,
// logistics.materials.index summary), never this company-wide view.
Route::middleware(['auth', 'role:CEO'])->prefix('analytics')->name('analytics.')->group(function () {
    Route::get('/', [AnalyticsController::class, 'index'])->name('index');
    Route::post('targets', [AnalyticsController::class, 'storeTarget'])
        ->middleware('throttle:60,1')
        ->name('targets.store');
});

// Audit Trail — PRD §9.4, CEO read-only. No update/destroy route exists
// for audit rows, for anyone (CLAUDE.md golden rule #7).
Route::get('audit-logs', [AuditLogController::class, 'index'])
    ->middleware(['auth', 'role:CEO'])
    ->name('audit-logs.index');

// User Management — CEO-only (assigning roles is an admin-level action;
// not itemized in the PRD §7.1 matrix, so scoped to the role that already
// has FULL access to Analytics/everything else).
Route::middleware(['auth', 'role:CEO'])->prefix('users')->name('users.')->group(function () {
    Route::get('/', [UserController::class, 'index'])->name('index');
    Route::get('/create', [UserController::class, 'create'])->name('create');
    Route::post('/', [UserController::class, 'store'])->name('store');
    Route::get('/{user}/edit', [UserController::class, 'edit'])->name('edit');
    Route::put('/{user}', [UserController::class, 'update'])->name('update');
});

// Master Data — SUPERADMIN-only (technical admin role, not in PRD §7.1 —
// see database/seeders/RoleSeeder.php). Reference/lookup tables other
// modules will point to by ID: Branches, Lead Sources, Lead Categories,
// Bank Accounts.
Route::middleware(['auth', 'role:SUPERADMIN'])->prefix('master-data')->name('master-data.')->group(function () {
    Route::get('/', [MasterDataController::class, 'index'])->name('index');

    Route::post('branches', [BranchController::class, 'store'])->name('branches.store');
    Route::put('branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
    Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->name('branches.destroy');

    Route::post('lead-sources', [LeadSourceController::class, 'store'])->name('lead-sources.store');
    Route::put('lead-sources/{lead_source}', [LeadSourceController::class, 'update'])->name('lead-sources.update');
    Route::delete('lead-sources/{lead_source}', [LeadSourceController::class, 'destroy'])->name('lead-sources.destroy');

    Route::post('lead-categories', [LeadCategoryController::class, 'store'])->name('lead-categories.store');
    Route::put('lead-categories/{lead_category}', [LeadCategoryController::class, 'update'])->name('lead-categories.update');
    Route::delete('lead-categories/{lead_category}', [LeadCategoryController::class, 'destroy'])->name('lead-categories.destroy');

    Route::post('bank-accounts', [BankAccountController::class, 'store'])->name('bank-accounts.store');
    Route::put('bank-accounts/{bank_account}', [BankAccountController::class, 'update'])->name('bank-accounts.update');
    Route::delete('bank-accounts/{bank_account}', [BankAccountController::class, 'destroy'])->name('bank-accounts.destroy');

    // Sprint 11 Sub 1 — Master Satuan; a unit in use is deactivated, never deleted.
    Route::post('units', [UnitController::class, 'store'])->name('units.store');
    Route::put('units/{unit}', [UnitController::class, 'update'])->name('units.update');
    Route::delete('units/{unit}', [UnitController::class, 'destroy'])->name('units.destroy');

    // Sprint 11 Sub 5 — material categories (in use → deactivate only) and
    // name synonyms (every change rebuilds the catalog's match keys).
    Route::post('material-categories', [MaterialCategoryController::class, 'store'])->name('material-categories.store');
    Route::put('material-categories/{material_category}', [MaterialCategoryController::class, 'update'])->name('material-categories.update');
    Route::delete('material-categories/{material_category}', [MaterialCategoryController::class, 'destroy'])->name('material-categories.destroy');
    Route::post('material-synonyms', [MaterialSynonymController::class, 'store'])->name('material-synonyms.store');
    Route::put('material-synonyms/{material_synonym}', [MaterialSynonymController::class, 'update'])->name('material-synonyms.update');
    Route::delete('material-synonyms/{material_synonym}', [MaterialSynonymController::class, 'destroy'])->name('material-synonyms.destroy');
});

// Master Vendor — Sprint 11 Sub 2: CEO + SUPERADMIN (god-mode), unlike the
// rest of Data Master. Other roles only pick an active vendor in forms.
Route::middleware(['auth', 'role:CEO'])->prefix('master-data/vendors')->name('master-data.vendors.')->group(function () {
    Route::get('/', [VendorController::class, 'index'])->name('index');
    Route::middleware('throttle:60,1')->group(function () {
        Route::post('/', [VendorController::class, 'store'])->name('store');
        Route::put('{vendor}', [VendorController::class, 'update'])->name('update');
        Route::delete('{vendor}', [VendorController::class, 'destroy'])->name('destroy');
    });
});

// SDM / HR — Sprint 10 (outside the PRD, .claude/plan/sprint-10-sdm.md).
// One `module:hr` gate for the whole module (CEO reads, HR manages;
// decision #2 — swapped for per-user module access later); finer
// per-action `role:` rules live in each part's route file. Field staff
// never appear in SDM data (decision #11, Employee::scopeHrEligible()).
Route::middleware(['auth', 'module:hr'])->prefix('hr')->name('hr.')->group(function () {
    Route::get('/', [HrDashboardController::class, 'index'])->name('dashboard');
    require __DIR__.'/hr/employees.php';
    require __DIR__.'/hr/discipline.php';
    require __DIR__.'/hr/salary.php';
    require __DIR__.'/hr/kpi.php';
    require __DIR__.'/hr/reviews.php';
});

// SDM "Milik Saya" — an employee's own KPI, reviews, warnings and salary
// history (decision #6). `employee.self` resolves the signed-in user's
// own employee row and refuses everyone else, field staff included.
Route::middleware(['auth', 'employee.self'])->prefix('saya')->name('my.')->group(function () {
    Route::get('/', [MyHrController::class, 'index'])->name('index');
    require __DIR__.'/my/discipline.php';
    require __DIR__.'/my/salary.php';
    require __DIR__.'/my/kpi.php';
    require __DIR__.'/my/reviews.php';
});

// Site Settings / web customization — CEO + SUPERADMIN only (not itemized
// in PRD §7.1 — added on request). The settings row is a singleton (see
// App\Models\SiteSetting::current()); each brand asset (logo, favicon,
// login image) can be uploaded/replaced and removed.
Route::middleware(['auth', 'role:CEO|SUPERADMIN'])->prefix('settings')->name('settings.')->group(function () {
    Route::get('/', [SiteSettingController::class, 'edit'])->name('edit');
    Route::put('/', [SiteSettingController::class, 'update'])->name('update');
    Route::post('assets/{asset}', [SiteSettingController::class, 'storeAsset'])
        ->whereIn('asset', array_keys(SiteSetting::ASSETS))
        ->name('assets.store');
    Route::delete('assets/{asset}', [SiteSettingController::class, 'destroyAsset'])
        ->whereIn('asset', array_keys(SiteSetting::ASSETS))
        ->name('assets.destroy');
});

// Brand assets are public: the login page and browser tab need them
// before sign-in. Read-only — uploads go through settings.assets.*.
Route::get('branding/{asset}', [BrandingAssetController::class, 'show'])
    ->whereIn('asset', array_keys(SiteSetting::ASSETS))
    ->name('branding.show');

require __DIR__.'/auth.php';
