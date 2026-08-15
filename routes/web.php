<?php

use App\Http\Controllers\Auth\UserController;
use App\Http\Controllers\CRM\LeadController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Design\DesignController;
use App\Http\Controllers\MasterData\BankAccountController;
use App\Http\Controllers\MasterData\BranchController;
use App\Http\Controllers\MasterData\LeadCategoryController;
use App\Http\Controllers\MasterData\LeadSourceController;
use App\Http\Controllers\MasterData\MasterDataController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Projects\MilestoneController;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\Settings\SiteSettingController;
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
});

// CRM — PRD §4.1 / §7.1. Read access matches the RBAC matrix's CRM–Lead
// row; write access follows §4.1's explicit "Marketing dan CEO" rule
// (broader than the matrix's MARKETING-only CRUD cell — see
// .claude/plan/README.md for why the more specific prose rule wins).
// updateStatus/confirmDeal follow the same Marketing+CEO write rule —
// status transitions and deal confirmation are part of "edit lead", not a
// separately-scoped action.
Route::middleware('auth')->prefix('crm')->name('crm.')->group(function () {
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

// Design — PRD §4.2 / §7.1 "Design Brief" row (DES has CRUD). Only the
// entry point that opens a design brief from a DEAL_DESAIN lead is wired
// this sprint (.claude/plan/sprint-02.md Week 3) — the rest of the module
// (status transitions, brief form UI) is Week 4.
Route::post('crm/leads/{lead}/design', [DesignController::class, 'store'])
    ->middleware(['auth', 'role:DESIGNER'])
    ->name('crm.leads.design.store');

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
