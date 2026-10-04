<?php

// SDM — employees + Divisi & Jabatan (Sprint 10 SDM-1). Loaded inside the
// `hr.` group (auth + module:hr = CEO|HR) in routes/web.php. Reads are open
// to the whole module; writes are HR only. No employee destroy route —
// employees are deactivated, never deleted.

use App\Http\Controllers\HR\EmployeeController;
use App\Http\Controllers\HR\OrganizationStructureController;
use Illuminate\Support\Facades\Route;

Route::get('employees', [EmployeeController::class, 'index'])->name('employees.index');
Route::get('employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');

Route::get('structure', [OrganizationStructureController::class, 'index'])->name('structure.index');

Route::middleware(['role:HR', 'throttle:60,1'])->group(function () {
    Route::post('employees', [EmployeeController::class, 'store'])->name('employees.store');
    Route::put('employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');

    Route::post('divisions', [OrganizationStructureController::class, 'storeDivision'])->name('divisions.store');
    Route::put('divisions/{division}', [OrganizationStructureController::class, 'updateDivision'])->name('divisions.update');
    Route::delete('divisions/{division}', [OrganizationStructureController::class, 'destroyDivision'])->name('divisions.destroy');

    Route::post('positions', [OrganizationStructureController::class, 'storePosition'])->name('positions.store');
    Route::put('positions/{position}', [OrganizationStructureController::class, 'updatePosition'])->name('positions.update');
    Route::delete('positions/{position}', [OrganizationStructureController::class, 'destroyPosition'])->name('positions.destroy');
});
