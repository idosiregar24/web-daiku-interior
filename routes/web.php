<?php

use App\Http\Controllers\Auth\UserController;
use App\Http\Controllers\CRM\LeadController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// CRM — PRD §4.1 / §7.1. Read access matches the RBAC matrix's CRM–Lead
// row; write access follows §4.1's explicit "Marketing dan CEO" rule
// (broader than the matrix's MARKETING-only CRUD cell — see
// .claude/plan/README.md for why the more specific prose rule wins).
Route::middleware('auth')->prefix('crm')->name('crm.')->group(function () {
    Route::get('leads', [LeadController::class, 'index'])
        ->middleware('role:CEO|MARKETING|DESIGNER|ESTIMATOR|PM')
        ->name('leads.index');

    Route::post('leads', [LeadController::class, 'store'])
        ->middleware('role:CEO|MARKETING')
        ->name('leads.store');
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

require __DIR__.'/auth.php';
