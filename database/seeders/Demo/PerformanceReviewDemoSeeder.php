<?php

namespace Database\Seeders\Demo;

use App\Models\Employee;
use App\Models\PerformanceReview;
use App\Models\User;
use App\Services\PerformanceReviewService;
use Illuminate\Database\Seeder;

/**
 * SDM (Sprint 10, §3.4) — demo semester reviews for the last completed
 * semester, one in every state, all written through
 * PerformanceReviewService (prefill, audit, notifications). Works whether
 * or not KPI periods exist (the KPI weight is then redistributed).
 * Needs the demo employees from DemoDataSeeder; Yola (HR herself) is left
 * without a review so the profile's "Buat evaluasi" shortcut shows.
 * Idempotent: employees that already have a review are skipped.
 */
class PerformanceReviewDemoSeeder extends Seeder
{
    /**
     * name => [final state, aspects (attitude, teamwork, initiative, responsibility), recommendation, notes].
     * State RETURNED = submitted then returned to DRAFT by the CEO with a note.
     */
    private const PLAN = [
        'Boy' => ['ACKNOWLEDGED', [5, 4, 5, 4], 'BONUS', 'Target lead tercapai, follow-up klien konsisten.'],
        'Icha' => ['APPROVED', [5, 5, 4, 5], 'NAIK_GAJI', 'Desain selalu tepat waktu dan banyak di-ACC klien di revisi pertama.'],
        'Ami' => ['SUBMITTED', [4, 4, 3, 4], 'TIDAK_ADA', 'RAB rapi; turnaround quotation masih bisa dipercepat.'],
        'Ibnu' => ['RETURNED', [3, 4, 3, 3], 'PEMBINAAN', 'Gambar kerja sering perlu revisi.'],
        'Ilham' => ['APPROVED', [4, 4, 4, 5], 'TIDAK_ADA', 'Administrasi keuangan tertib.'],
        'Hesti' => ['DRAFT', [4, null, null, null], null, null],
        'Satria' => ['SUBMITTED', [3, 3, 2, 3], 'PEMBINAAN', 'Selisih stok gudang beberapa kali terjadi.'],
        'Rojab' => ['ACKNOWLEDGED', [4, 5, 4, 4], 'BONUS', 'Mayoritas milestone proyek tepat waktu.'],
    ];

    public function run(): void
    {
        $hr = User::role('HR')->where('is_active', true)->orderBy('id')->first();
        $ceo = User::role('CEO')->where('is_active', true)->orderBy('id')->first();

        if (! $hr || ! $ceo) {
            return;
        }

        $service = app(PerformanceReviewService::class);
        ['year' => $year, 'semester' => $semester] = PerformanceReviewService::previousSemester();

        foreach (self::PLAN as $name => [$state, $aspects, $recommendation, $notes]) {
            $employee = Employee::query()->hrEligible()->active()->where('name', $name)->first();

            if (! $employee || PerformanceReview::where('employee_id', $employee->id)->where('year', $year)->where('semester', $semester)->exists()) {
                continue;
            }

            // Demo join dates can be later than the semester — skip those instead of failing the seed.
            if ($employee->join_date && $employee->join_date->gt(PerformanceReviewService::semesterEnd($year, $semester))) {
                continue;
            }

            $review = $service->create($employee, $year, $semester, $hr);
            $review = $service->update($review, [
                'qualitative' => array_combine(PerformanceReviewService::ASPECTS, $aspects),
                'weights' => PerformanceReviewService::DEFAULT_WEIGHTS,
                'recommendation' => $recommendation,
                'notes' => $notes,
            ], $hr);

            if ($state === 'DRAFT') {
                continue;
            }

            $review = $service->submit($review, $hr);

            match ($state) {
                'RETURNED' => $service->return($review, 'Mohon lengkapi catatan contoh revisi gambar dan tinjau ulang nilai inisiatif.', $ceo),
                'APPROVED' => $service->approve($review, $ceo),
                'ACKNOWLEDGED' => $this->approveAndAcknowledge($service, $review, $employee, $ceo),
                default => null,
            };
        }
    }

    private function approveAndAcknowledge(PerformanceReviewService $service, PerformanceReview $review, Employee $employee, User $ceo): void
    {
        $review = $service->approve($review, $ceo);

        // The employee confirms with their own account; without one, the HR desk records it on paper only.
        $account = $employee->user;

        if ($account) {
            $service->acknowledge($review, $employee, $account);
        }
    }
}
