<?php

// SDM — Evaluasi (Sprint 10 SDM-5, §3.4). Loaded inside the `hr.` group
// (auth + module:hr = CEO|HR) in routes/web.php. Reads are open to the
// whole module; HR writes until SUBMITTED; the CEO approves or returns.
// No destroy route — reviews are never deleted.

use App\Http\Controllers\HR\PerformanceReviewController;
use Illuminate\Support\Facades\Route;

Route::get('reviews', [PerformanceReviewController::class, 'index'])->name('reviews.index');
Route::get('reviews/{performance_review}', [PerformanceReviewController::class, 'show'])->name('reviews.show');
Route::get('reviews/{performance_review}/pdf', [PerformanceReviewController::class, 'pdf'])->name('reviews.pdf');

Route::middleware(['role:HR', 'throttle:60,1'])->group(function () {
    Route::post('reviews', [PerformanceReviewController::class, 'store'])->name('reviews.store');
    Route::post('reviews/bulk', [PerformanceReviewController::class, 'bulk'])->name('reviews.bulk');
    Route::put('reviews/{performance_review}', [PerformanceReviewController::class, 'update'])->name('reviews.update');
    Route::post('reviews/{performance_review}/refresh', [PerformanceReviewController::class, 'refresh'])->name('reviews.refresh');
    Route::post('reviews/{performance_review}/submit', [PerformanceReviewController::class, 'submit'])->name('reviews.submit');
});

Route::middleware(['role:CEO', 'throttle:60,1'])->group(function () {
    Route::post('reviews/{performance_review}/approve', [PerformanceReviewController::class, 'approve'])->name('reviews.approve');
    Route::post('reviews/{performance_review}/return', [PerformanceReviewController::class, 'return'])->name('reviews.return');
});
