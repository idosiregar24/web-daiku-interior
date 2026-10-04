<?php

namespace App\Http\Controllers\HR;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PerformanceReview;
use App\Services\PerformanceReviewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;

/**
 * SDM "Milik Saya" (Sprint 10 decision #6) — an employee's own reviews.
 * `employee.self` puts the signed-in user's employee row in the request;
 * a review that is not theirs, or not final yet (APPROVED/ACKNOWLEDGED),
 * is a 404, so its existence is not revealed either.
 */
class MyPerformanceReviewController extends Controller
{
    public function acknowledge(Request $request, PerformanceReview $performanceReview, PerformanceReviewService $service): RedirectResponse
    {
        $employee = $this->ownFinal($request, $performanceReview);
        $service->acknowledge($performanceReview, $employee, $request->user());

        return back()->with('success', 'Terima kasih, evaluasi sudah dikonfirmasi.');
    }

    public function pdf(Request $request, PerformanceReview $performanceReview, PerformanceReviewService $service): HttpResponse
    {
        $employee = $this->ownFinal($request, $performanceReview);

        return PerformanceReviewController::renderPdf($performanceReview, $employee, $service, true);
    }

    private function ownFinal(Request $request, PerformanceReview $review): Employee
    {
        $employee = $request->attributes->get('employee');

        abort_unless(
            $employee instanceof Employee
                && $review->employee_id === $employee->id
                && PerformanceReview::query()->final()->whereKey($review->id)->exists(),
            404,
        );

        return $employee;
    }
}
