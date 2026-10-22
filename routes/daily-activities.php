<?php

// Sprint 29 Sub 13 — "Aktivitas Harian" & "Periksa Aktivitas" (decisions
// I1–I2, outside the PRD). Loaded inside the `daily-activities.` group
// (auth) in routes/web.php — deliberately NOT under `module:hr`: every
// account except a Tukang writes its own entries, and PMs read the check
// page without opening the rest of SDM. Names are `daily-activities.*`,
// not `hr.*` (every hr.* route sits behind module:hr — SdmAccessTest).

use App\Http\Controllers\HR\DailyActivityController;
use App\Http\Controllers\HR\DailyActivityReviewController;
use Illuminate\Support\Facades\Route;

// Own entries — every role but FIELD_STAFF (SUPERADMIN through the role
// bypass); DailyActivityPolicy adds "own entry, same day".
Route::middleware('role:CEO|MARKETING|DESIGNER|ESTIMATOR|PM|QA|FINANCE|LOGISTICS|HR|ASISTEN_PM|KEPALA_DESAIN|ADMIN_PROYEK')->group(function () {
    Route::get('/', [DailyActivityController::class, 'index'])->name('index');

    Route::middleware('throttle:60,1')->group(function () {
        Route::post('/', [DailyActivityController::class, 'store'])->name('store');
        Route::put('{daily_activity}', [DailyActivityController::class, 'update'])->name('update');
        Route::delete('{daily_activity}', [DailyActivityController::class, 'destroy'])->name('destroy');
    });
});

// The check: HR decides, the CEO and PMs read. Append-only — no edit or
// delete route; checking again adds a row and the newest counts.
Route::get('periksa', [DailyActivityReviewController::class, 'index'])
    ->middleware('role:HR|CEO|PM')
    ->name('review');

Route::middleware(['role:HR', 'throttle:60,1'])->group(function () {
    Route::post('periksa', [DailyActivityReviewController::class, 'store'])->name('review.store');
    Route::post('periksa/semua', [DailyActivityReviewController::class, 'bulk'])->name('review.bulk');
});
