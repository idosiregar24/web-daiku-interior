<?php

// SDM — Kedisiplinan (Sprint 10 SDM-2, §3.1). Loaded inside the `hr.` group
// (auth + module:hr = CEO|HR) in routes/web.php. CEO reads; HR records and
// cancels. Append-only: no edit and never a DELETE route — a mistake is
// cancelled with a PEMBATALAN entry (`void`).

use App\Http\Controllers\HR\DisciplineController;
use Illuminate\Support\Facades\Route;

Route::get('discipline', [DisciplineController::class, 'index'])->name('discipline.index');
Route::get('discipline/export', [DisciplineController::class, 'export'])->name('discipline.export');

Route::middleware(['role:HR', 'throttle:60,1'])->group(function () {
    Route::post('discipline', [DisciplineController::class, 'store'])->name('discipline.store');
    Route::post('discipline/{disciplinary_record}/void', [DisciplineController::class, 'void'])->name('discipline.void');
});
