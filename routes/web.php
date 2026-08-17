<?php

use App\Http\Controllers\Auth\UserController;
use App\Http\Controllers\CRM\LeadController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Design\DesignController;
use App\Http\Controllers\Finance\FamilyGatheringFundController;
use App\Http\Controllers\Finance\FinanceTransactionController;
use App\Http\Controllers\Finance\PenaltyController;
use App\Http\Controllers\Finance\TerminController;
use App\Http\Controllers\MasterData\BankAccountController;
use App\Http\Controllers\MasterData\BranchController;
use App\Http\Controllers\MasterData\LeadCategoryController;
use App\Http\Controllers\MasterData\LeadSourceController;
use App\Http\Controllers\MasterData\MasterDataController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Overtime\OvertimeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Projects\MilestoneController;
use App\Http\Controllers\Projects\ProgressLogController;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\Projects\TaskController;
use App\Http\Controllers\QA\QaFormController;
use App\Http\Controllers\Quotation\QuotationController;
use App\Http\Controllers\Settings\SiteSettingController;
use App\Http\Controllers\Tasks\DailyTaskFormController;
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
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Not itemized in PRD §7.1 (added alongside the minimal notifications
    // module — see the notifications migration's docblock). Ownership
    // enforced in the controller, not a role check — every user reads
    // only their own.
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markAsRead'])
        ->name('notifications.markAsRead');
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
        ->middleware('role:CEO|MARKETING|DESIGNER|ESTIMATOR|PM|FINANCE')
        ->name('index');

    Route::get('{quotation}', [QuotationController::class, 'show'])
        ->middleware('role:CEO|MARKETING|DESIGNER|ESTIMATOR|PM|FINANCE')
        ->name('show');

    Route::get('{quotation}/pdf', [QuotationController::class, 'exportPdf'])
        ->middleware('role:CEO|MARKETING|DESIGNER|ESTIMATOR|PM|FINANCE')
        ->name('pdf');

    Route::put('{quotation}/items', [QuotationController::class, 'updateItems'])
        ->middleware('role:ESTIMATOR')
        ->name('items.update');

    Route::post('{quotation}/submit', [QuotationController::class, 'submit'])
        ->middleware('role:ESTIMATOR')
        ->name('submit');

    Route::post('{quotation}/ceo-decision', [QuotationController::class, 'ceoDecision'])
        ->middleware('role:CEO')
        ->name('ceoDecision');

    Route::post('{quotation}/pm-decision', [QuotationController::class, 'pmDecision'])
        ->middleware('role:PM')
        ->name('pmDecision');
});

// Projects — PRD §4.4 / §7.1 "Project (overview)" row: broad read access,
// PM (+ CEO, admin oversight) for create. Field Staff read is scoped to
// their own assigned tasks inside ProjectController@index (matrix's R*).
Route::middleware('auth')->prefix('projects')->name('projects.')->group(function () {
    Route::get('/', [ProjectController::class, 'index'])
        ->middleware('role:CEO|MARKETING|DESIGNER|ESTIMATOR|PM|QA|FINANCE|LOGISTICS|FIELD_STAFF')
        ->name('index');

    Route::get('/{project}', [ProjectController::class, 'show'])
        ->middleware('role:CEO|MARKETING|DESIGNER|ESTIMATOR|PM|QA|FINANCE|LOGISTICS|FIELD_STAFF')
        ->name('show');

    Route::post('/', [ProjectController::class, 'store'])
        ->middleware('role:CEO|PM')
        ->name('store');
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
    ->middleware(['auth', 'role:PM|FIELD_STAFF'])
    ->name('tasks.updateStatus');

// Daily Task Form — PRD §4.5 / §7.1 "Daily Task Form" row (CEO/PM read,
// Field Staff create+read own — see DailyTaskFormController::index()).
Route::middleware(['auth', 'role:CEO|PM|FIELD_STAFF'])->prefix('daily-forms')->name('daily-forms.')->group(function () {
    Route::get('/', [DailyTaskFormController::class, 'index'])->name('index');
});

Route::post('tasks/{task}/daily-form', [DailyTaskFormController::class, 'store'])
    ->middleware(['auth', 'role:FIELD_STAFF'])
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
// Staff read own only (`R*`). Read-only — see PenaltyController's docblock.
Route::get('penalties', [PenaltyController::class, 'index'])
    ->middleware(['auth', 'role:CEO|PM|FINANCE|FIELD_STAFF'])
    ->name('penalties.index');

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

Route::middleware(['auth', 'role:FIELD_STAFF'])->group(function () {
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
});

// Site Settings — CEO + SUPERADMIN only (not itemized in PRD §7.1 —
// general company/application profile, added on request). Singleton
// resource, no index/create/destroy — see App\Models\SiteSetting::current().
Route::middleware(['auth', 'role:CEO|SUPERADMIN'])->prefix('settings')->name('settings.')->group(function () {
    Route::get('/', [SiteSettingController::class, 'edit'])->name('edit');
    Route::put('/', [SiteSettingController::class, 'update'])->name('update');
});

require __DIR__.'/auth.php';
