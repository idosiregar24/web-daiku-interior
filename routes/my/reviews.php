<?php

// SDM "Milik Saya" — Evaluasi (Sprint 10 SDM-5, decision #6). Loaded inside
// the `my.` group (auth + employee.self) in routes/web.php; the controller
// 404s any review that is not the signed-in employee's own final one.

use App\Http\Controllers\HR\MyPerformanceReviewController;
use Illuminate\Support\Facades\Route;

Route::get('reviews/{performance_review}/pdf', [MyPerformanceReviewController::class, 'pdf'])->name('reviews.pdf');

Route::post('reviews/{performance_review}/acknowledge', [MyPerformanceReviewController::class, 'acknowledge'])
    ->middleware('throttle:60,1')
    ->name('reviews.acknowledge');
