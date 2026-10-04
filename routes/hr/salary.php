<?php

// SDM — Gaji (Sprint 10 SDM-3, decision #3, §3.2). Loaded inside the `hr.`
// group (auth + module:hr = CEO|HR) in routes/web.php. HR requests a
// base-salary change, the CEO approves or rejects. No edit and never a
// DELETE route — a decided change is final. Finance still pays salaries
// on its Penggajian page.

use App\Http\Controllers\HR\SalaryController;
use Illuminate\Support\Facades\Route;

Route::get('salary', [SalaryController::class, 'index'])->name('salary.index');

Route::middleware(['role:HR', 'throttle:60,1'])->group(function () {
    Route::post('salary-changes', [SalaryController::class, 'store'])->name('salary-changes.store');
});

Route::middleware(['role:CEO', 'throttle:60,1'])->group(function () {
    Route::post('salary-changes/{salary_change}/approve', [SalaryController::class, 'approve'])->name('salary-changes.approve');
    Route::post('salary-changes/{salary_change}/reject', [SalaryController::class, 'reject'])->name('salary-changes.reject');
});
