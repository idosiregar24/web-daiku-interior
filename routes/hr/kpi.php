<?php

// SDM — monthly KPI (Sprint 10 SDM-4, §3.3). Loaded inside the `hr.` group
// (auth + module:hr = CEO|HR) in routes/web.php: the CEO reads, HR manages
// (§4). No DELETE routes — templates are deactivated, periods closed,
// scores locked once their month is CLOSED.

use App\Http\Controllers\HR\KpiController;
use Illuminate\Support\Facades\Route;

Route::get('kpi', [KpiController::class, 'index'])->name('kpi.index');
Route::get('kpi/templates', [KpiController::class, 'templates'])->name('kpi.templates.index');

Route::middleware(['role:HR', 'throttle:60,1'])->group(function () {
    Route::put('kpi/templates/{position}', [KpiController::class, 'saveTemplate'])->name('kpi.templates.save');
    Route::patch('kpi/templates/{position}/toggle', [KpiController::class, 'toggleTemplate'])->name('kpi.templates.toggle');

    Route::post('kpi/periods', [KpiController::class, 'storePeriod'])->name('kpi.periods.store');
    Route::post('kpi/periods/{kpi_period}/compute', [KpiController::class, 'compute'])->name('kpi.periods.compute');
    Route::post('kpi/periods/{kpi_period}/close', [KpiController::class, 'close'])->name('kpi.periods.close');

    Route::patch('kpi/scores/{kpi_score}', [KpiController::class, 'updateScore'])->name('kpi.scores.update');
});
