<?php

namespace Database\Factories;

use App\Enums\ReviewStatus;
use App\Models\Employee;
use App\Models\PerformanceReview;
use App\Models\User;
use App\Services\PerformanceReviewService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A bare DRAFT review of the last completed semester. Real reviews are
 * created through PerformanceReviewService (prefill + audit); this is for
 * tests that need a row in a given state.
 *
 * @extends Factory<PerformanceReview>
 */
class PerformanceReviewFactory extends Factory
{
    public function definition(): array
    {
        $previous = PerformanceReviewService::previousSemester();

        return [
            'employee_id' => Employee::factory(),
            'year' => $previous['year'],
            'semester' => $previous['semester'],
            'kpi_average' => null,
            'kpi_months' => 0,
            'discipline_summary' => ['teguran_lisan' => 0, 'sp1' => 0, 'sp2' => 0, 'sp3' => 0, 'catatan' => 0, 'active_sp_at_end' => null],
            'discipline_score' => 100,
            'qualitative' => null,
            'qualitative_score' => null,
            'weights' => PerformanceReviewService::DEFAULT_WEIGHTS,
            'status' => ReviewStatus::Draft->value,
            'reviewer_id' => User::factory(),
        ];
    }

    public function status(ReviewStatus $status): static
    {
        return $this->state(fn () => [
            'status' => $status->value,
            'submitted_at' => $status === ReviewStatus::Draft ? null : now(),
            'approved_at' => in_array($status, [ReviewStatus::Approved, ReviewStatus::Acknowledged], true) ? now() : null,
            'acknowledged_at' => $status === ReviewStatus::Acknowledged ? now() : null,
        ]);
    }
}
