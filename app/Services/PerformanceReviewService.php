<?php

namespace App\Services;

use App\Enums\DisciplinaryType;
use App\Enums\ReviewGrade;
use App\Enums\ReviewStatus;
use App\Models\DisciplinaryRecord;
use App\Models\Employee;
use App\Models\PerformanceReview;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * SDM (Sprint 10, §3.4) — semester performance reviews.
 *
 * Flow: DRAFT (HR writes) → SUBMITTED (HR) → APPROVED (CEO) or back to
 * DRAFT with a `return_note` (CEO) → ACKNOWLEDGED (the employee confirms
 * they read it). From APPROVED on nothing but the acknowledgement may
 * change it. Reviewer = HR only (decision #7).
 *
 * Prefill (create/refresh):
 * - KPI: average of the semester's CLOSED monthly KPI totals
 *   (KpiService::semesterAverage(), decision #5). Null when no month of
 *   the semester has been closed yet.
 * - Discipline: effective (not voided, not PEMBATALAN) records issued
 *   inside the semester, summarised as counts + the highest SP still in
 *   force at the end of the semester (or today, while it runs).
 *   Default discipline score rule:
 *     100 − 10 × teguran lisan − 20 × SP1 − 35 × SP2 − 50 × SP3, floor 0.
 *   CATATAN entries are listed but cost nothing.
 *
 * Final score = Σ weight% × part score, where the KPI part is capped at
 * 100 (KPI totals can reach 120). Without a KPI average the KPI weight is
 * redistributed proportionally over the qualitative and discipline parts
 * (`kpi_redistributed` in the presented data). The recommendation is
 * advice only — it never changes pay (decision #8); NAIK_GAJI can be
 * turned into a salary-change request the CEO still has to approve.
 */
class PerformanceReviewService
{
    public const DEFAULT_WEIGHTS = ['kpi' => 60, 'qualitative' => 25, 'discipline' => 15];

    public const ASPECTS = ['attitude', 'teamwork', 'initiative', 'responsibility'];

    public const ASPECT_LABELS = [
        'attitude' => 'Sikap',
        'teamwork' => 'Kerja sama',
        'initiative' => 'Inisiatif',
        'responsibility' => 'Tanggung jawab',
    ];

    public const RECOMMENDATION_LABELS = [
        'NAIK_GAJI' => 'Naik gaji',
        'BONUS' => 'Bonus',
        'PEMBINAAN' => 'Pembinaan',
        'SP' => 'Surat peringatan (SP)',
        'TIDAK_ADA' => 'Tidak ada',
    ];

    public const STATUS_LABELS = [
        'DRAFT' => 'Draf',
        'SUBMITTED' => 'Menunggu CEO',
        'APPROVED' => 'Disetujui',
        'ACKNOWLEDGED' => 'Sudah dibaca',
    ];

    /** Points deducted from 100 per effective record issued in the semester. */
    public const DISCIPLINE_PENALTY = [
        'TEGURAN_LISAN' => 10,
        'SP1' => 20,
        'SP2' => 35,
        'SP3' => 50,
    ];

    /** The KPI part of the final score is capped here (KPI totals go up to 120). */
    public const KPI_CAP = 100;

    public function __construct(
        private KpiService $kpiService,
        private NotificationService $notificationService,
        private AuditLogService $auditLogService,
    ) {}

    // ── Semester helpers ────────────────────────────────────────────────

    public static function semesterStart(int $year, int $semester): Carbon
    {
        return Carbon::create($year, $semester === 1 ? 1 : 7, 1)->startOfDay();
    }

    public static function semesterEnd(int $year, int $semester): Carbon
    {
        return self::semesterStart($year, $semester)->addMonths(5)->endOfMonth();
    }

    /** @return array{year: int, semester: int} */
    public static function currentSemester(): array
    {
        $today = now();

        return ['year' => $today->year, 'semester' => $today->month <= 6 ? 1 : 2];
    }

    /** The last semester that has fully ended. @return array{year: int, semester: int} */
    public static function previousSemester(): array
    {
        ['year' => $year, 'semester' => $semester] = self::currentSemester();

        return $semester === 1 ? ['year' => $year - 1, 'semester' => 2] : ['year' => $year, 'semester' => 1];
    }

    public static function hasStarted(int $year, int $semester): bool
    {
        return self::semesterStart($year, $semester)->lte(now());
    }

    // ── Transitions ─────────────────────────────────────────────────────

    public function create(Employee $employee, int $year, int $semester, User $hr): PerformanceReview
    {
        $this->assertCreatable($employee, $year, $semester);

        try {
            return DB::transaction(function () use ($employee, $year, $semester, $hr) {
                // Lock the employee row so two clicks can't race past the duplicate check.
                Employee::whereKey($employee->id)->lockForUpdate()->first();

                if ($this->exists($employee, $year, $semester)) {
                    throw $this->duplicate($employee, $year, $semester);
                }

                $review = new PerformanceReview([
                    'employee_id' => $employee->id,
                    'year' => $year,
                    'semester' => $semester,
                    'weights' => self::DEFAULT_WEIGHTS,
                    'status' => ReviewStatus::Draft->value,
                    'reviewer_id' => $hr->id,
                ]);
                $review->setRelation('employee', $employee);
                $review->fill($this->prefill($employee, $year, $semester));
                $review->fill($this->scores($review));
                $review->save();

                $this->auditLogService->record('hr.review_created', $review, null, $this->auditSnapshot($review), $hr);

                return $review;
            });
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicate($employee, $year, $semester);
        }
    }

    /**
     * "Buat untuk semua karyawan" — one DRAFT per active HR-eligible
     * employee who joined before the semester ended and has none yet.
     *
     * @return int how many were created
     */
    public function createForAll(int $year, int $semester, User $hr): int
    {
        $this->assertSemesterStarted($year, $semester);

        $employees = Employee::query()
            ->hrEligible()
            ->active()
            ->where(fn ($query) => $query->whereNull('join_date')->orWhereDate('join_date', '<=', self::semesterEnd($year, $semester)))
            ->whereDoesntHave('performanceReviews', fn ($query) => $query->where('year', $year)->where('semester', $semester))
            ->orderBy('name')
            ->get();

        $created = 0;

        foreach ($employees as $employee) {
            try {
                $this->create($employee, $year, $semester, $hr);
                $created++;
            } catch (ValidationException) {
                // Created meanwhile by someone else — skip it, like an existing one.
            }
        }

        return $created;
    }

    /** "Perbarui data" — re-pulls the KPI and discipline prefill (DRAFT only). */
    public function refresh(PerformanceReview $review, User $hr): PerformanceReview
    {
        return DB::transaction(function () use ($review, $hr) {
            $review = $this->lockDraft($review);
            $old = $this->auditSnapshot($review);

            $review->fill($this->prefill($review->employee, $review->year, $review->semester));
            $review->fill($this->scores($review));
            $review->save();

            $this->auditLogService->record('hr.review_updated', $review, $old, $this->auditSnapshot($review), $hr);

            return $review;
        });
    }

    /**
     * @param  array{qualitative: array<string, int|null>, weights: array<string, int>, recommendation?: string|null, notes?: string|null}  $data
     */
    public function update(PerformanceReview $review, array $data, User $hr): PerformanceReview
    {
        $weights = $this->normalizeWeights($data['weights'] ?? []);
        $qualitative = $this->normalizeQualitative($data['qualitative'] ?? []);

        return DB::transaction(function () use ($review, $data, $hr, $weights, $qualitative) {
            $review = $this->lockDraft($review);
            $old = $this->auditSnapshot($review);

            $review->fill([
                'qualitative' => $qualitative,
                'weights' => $weights,
                'recommendation' => $data['recommendation'] ?? null,
                'notes' => filled($data['notes'] ?? null) ? $data['notes'] : null,
            ]);
            $review->fill($this->scores($review));
            $review->save();

            $this->auditLogService->record('hr.review_updated', $review, $old, $this->auditSnapshot($review), $hr);

            return $review;
        });
    }

    public function submit(PerformanceReview $review, User $hr): PerformanceReview
    {
        return DB::transaction(function () use ($review, $hr) {
            $review = $this->lockDraft($review);

            if (! $this->qualitativeComplete($review->qualitative)) {
                throw ValidationException::withMessages(['qualitative' => 'Lengkapi keempat aspek kualitatif (nilai 1–5) sebelum mengajukan evaluasi.']);
            }

            if (! $review->recommendation) {
                throw ValidationException::withMessages(['recommendation' => 'Pilih rekomendasi sebelum mengajukan evaluasi.']);
            }

            $old = $this->auditSnapshot($review);
            $review->fill($this->scores($review));
            $review->fill([
                'status' => ReviewStatus::Submitted->value,
                'submitted_at' => now(),
                'return_note' => null,
            ])->save();

            $this->auditLogService->record('hr.review_submitted', $review, $old, $this->auditSnapshot($review), $hr);

            $this->notificationService->notifyRoles(
                ['CEO'],
                'review_submitted',
                'Evaluasi Menunggu Persetujuan',
                "Evaluasi {$review->periodLabel()} untuk {$review->employee->name} diajukan SDM dan menunggu persetujuan Anda.",
                ['performance_review_id' => $review->id, 'employee_id' => $review->employee_id],
            );

            return $review;
        });
    }

    public function approve(PerformanceReview $review, User $ceo): PerformanceReview
    {
        return DB::transaction(function () use ($review, $ceo) {
            $review = $this->lockInStatus($review, ReviewStatus::Submitted, 'Hanya evaluasi yang berstatus menunggu persetujuan yang bisa disetujui.');
            $old = $this->auditSnapshot($review);

            $review->fill([
                'status' => ReviewStatus::Approved->value,
                'approved_by' => $ceo->id,
                'approved_at' => now(),
            ])->save();

            $this->auditLogService->record('hr.review_approved', $review, $old, $this->auditSnapshot($review), $ceo);

            $metadata = ['performance_review_id' => $review->id, 'employee_id' => $review->employee_id];
            $employeeUser = $review->employee->user;

            if ($employeeUser && $employeeUser->is_active) {
                $this->notificationService->notify(
                    $employeeUser,
                    'review_approved',
                    'Evaluasi Kinerja Tersedia',
                    "Evaluasi kinerja {$review->periodLabel()} Anda sudah disetujui. Silakan baca dan konfirmasi.",
                    $metadata,
                );
            }

            if ($review->reviewer && $review->reviewer->is_active && $review->reviewer->id !== $employeeUser?->id) {
                $this->notificationService->notify(
                    $review->reviewer,
                    'review_approved_reviewer',
                    'Evaluasi Disetujui CEO',
                    "Evaluasi {$review->periodLabel()} untuk {$review->employee->name} disetujui CEO.",
                    $metadata,
                );
            }

            return $review;
        });
    }

    public function return(PerformanceReview $review, string $note, User $ceo): PerformanceReview
    {
        if (blank($note)) {
            throw ValidationException::withMessages(['note' => 'Catatan pengembalian wajib diisi.']);
        }

        return DB::transaction(function () use ($review, $note, $ceo) {
            $review = $this->lockInStatus($review, ReviewStatus::Submitted, 'Hanya evaluasi yang berstatus menunggu persetujuan yang bisa dikembalikan.');
            $old = $this->auditSnapshot($review);

            $review->fill([
                'status' => ReviewStatus::Draft->value,
                'return_note' => trim($note),
                'submitted_at' => null,
            ])->save();

            $this->auditLogService->record('hr.review_returned', $review, $old, $this->auditSnapshot($review), $ceo);

            if ($review->reviewer && $review->reviewer->is_active) {
                $this->notificationService->notify(
                    $review->reviewer,
                    'review_returned',
                    'Evaluasi Dikembalikan CEO',
                    "Evaluasi {$review->periodLabel()} untuk {$review->employee->name} dikembalikan: {$review->return_note}",
                    ['performance_review_id' => $review->id, 'employee_id' => $review->employee_id],
                );
            }

            return $review;
        });
    }

    /** The employee confirms they read their APPROVED review ("Saya sudah membaca"). */
    public function acknowledge(PerformanceReview $review, Employee $self, User $user): PerformanceReview
    {
        if ($review->employee_id !== $self->id) {
            throw ValidationException::withMessages(['status' => 'Evaluasi ini bukan milik Anda.']);
        }

        return DB::transaction(function () use ($review, $user) {
            $review = $this->lockInStatus($review, ReviewStatus::Approved, 'Evaluasi ini sudah dikonfirmasi atau belum disetujui.');
            $old = $this->auditSnapshot($review);

            $review->fill([
                'status' => ReviewStatus::Acknowledged->value,
                'acknowledged_at' => now(),
            ])->save();

            $this->auditLogService->record('hr.review_acknowledged', $review, $old, $this->auditSnapshot($review), $user);

            return $review;
        });
    }

    // ── Read side ───────────────────────────────────────────────────────

    /**
     * Evaluasi tab data (HR profile page, and the employee's own "Milik
     * Saya" page with `$finalOnly` — APPROVED/ACKNOWLEDGED only, never the
     * internal `return_note`).
     *
     * @return array{reviews: array<int, array<string, mixed>>, current: array<string, mixed>, previous: array<string, mixed>}
     */
    public function forEmployee(Employee $employee, bool $finalOnly = false): array
    {
        $reviews = PerformanceReview::query()
            ->where('employee_id', $employee->id)
            ->when($finalOnly, fn ($query) => $query->final())
            ->with(['reviewer:id,name', 'approver:id,name'])
            ->orderByDesc('year')
            ->orderByDesc('semester')
            ->get();

        $period = function (array $semester) use ($reviews) {
            $review = $reviews->first(fn (PerformanceReview $r) => $r->year === $semester['year'] && $r->semester === $semester['semester']);

            return [...$semester, 'label' => "Semester {$semester['semester']} {$semester['year']}", 'review_id' => $review?->id];
        };

        return [
            'reviews' => $reviews->map(fn (PerformanceReview $review) => $this->present($review, $finalOnly))->values()->all(),
            // For the "Buat evaluasi" shortcut on the profile (HR only).
            'current' => $period(self::currentSemester()),
            'previous' => $period(self::previousSemester()),
        ];
    }

    /**
     * Review as sent to the pages (detail view, tab, PDF).
     *
     * @return array<string, mixed>
     */
    public function present(PerformanceReview $review, bool $forEmployee = false): array
    {
        $review->loadMissing(['reviewer:id,name', 'approver:id,name']);
        $kpi = $review->kpi_average === null ? null : (float) $review->kpi_average;

        $data = [
            'id' => $review->id,
            'employee_id' => $review->employee_id,
            'year' => $review->year,
            'semester' => $review->semester,
            'period_label' => $review->periodLabel(),
            'status' => $review->status->value,
            'status_label' => self::STATUS_LABELS[$review->status->value],
            'kpi_average' => $kpi,
            'kpi_months' => $review->kpi_months,
            'kpi_capped' => $kpi !== null && $kpi > self::KPI_CAP,
            'kpi_redistributed' => $kpi === null,
            'discipline_summary' => $review->discipline_summary,
            'discipline_score' => $review->discipline_score === null ? null : (float) $review->discipline_score,
            'qualitative' => $review->qualitative,
            'qualitative_score' => $review->qualitative_score === null ? null : (float) $review->qualitative_score,
            'weights' => $review->weights ?? self::DEFAULT_WEIGHTS,
            'effective_weights' => $this->effectiveWeights($review->weights ?? self::DEFAULT_WEIGHTS, $kpi === null),
            'final_score' => $review->final_score === null ? null : (float) $review->final_score,
            'grade' => $review->grade?->value,
            'recommendation' => $review->recommendation?->value,
            'recommendation_label' => $review->recommendation ? self::RECOMMENDATION_LABELS[$review->recommendation->value] : null,
            'notes' => $review->notes,
            'reviewer' => $review->reviewer?->only(['id', 'name']),
            'submitted_at' => $review->submitted_at?->toIso8601String(),
            'approver' => $review->approver?->only(['id', 'name']),
            'approved_at' => $review->approved_at?->toIso8601String(),
            'acknowledged_at' => $review->acknowledged_at?->toIso8601String(),
            'created_at' => $review->created_at?->toIso8601String(),
        ];

        if (! $forEmployee) {
            $data['return_note'] = $review->return_note;
        }

        return $data;
    }

    /**
     * SDM dashboard: status counts for the current and the last completed
     * semester, reviews awaiting the CEO, and the grade distribution of
     * the last completed semester's final reviews.
     *
     * @return array<string, mixed>
     */
    public function dashboardSummary(): array
    {
        $eligible = Employee::query()->hrEligible()->active()->count();

        $semesterCounts = function (array $semester) use ($eligible) {
            $counts = PerformanceReview::query()
                ->where('year', $semester['year'])
                ->where('semester', $semester['semester'])
                ->whereHas('employee', fn ($query) => $query->hrEligible())
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $byStatus = collect(ReviewStatus::cases())->mapWithKeys(fn (ReviewStatus $s) => [$s->value => (int) ($counts[$s->value] ?? 0)]);

            return [
                ...$semester,
                'label' => "Semester {$semester['semester']} {$semester['year']}",
                'counts' => $byStatus->all(),
                'total' => $byStatus->sum(),
                'eligible' => $eligible,
            ];
        };

        $previous = self::previousSemester();

        $grades = PerformanceReview::query()
            ->final()
            ->where('year', $previous['year'])
            ->where('semester', $previous['semester'])
            ->whereHas('employee', fn ($query) => $query->hrEligible())
            ->whereNotNull('grade')
            ->selectRaw('grade, COUNT(*) as total')
            ->groupBy('grade')
            ->pluck('total', 'grade');

        $awaiting = PerformanceReview::query()
            ->where('status', ReviewStatus::Submitted->value)
            ->whereHas('employee', fn ($query) => $query->hrEligible())
            ->with(['employee:id,name'])
            ->orderBy('submitted_at')
            ->get();

        return [
            'current' => $semesterCounts(self::currentSemester()),
            'previous' => $semesterCounts($previous),
            'awaiting_count' => $awaiting->count(),
            'awaiting' => $awaiting->take(5)->map(fn (PerformanceReview $review) => [
                'id' => $review->id,
                'employee' => $review->employee?->only(['id', 'name']),
                'period_label' => $review->periodLabel(),
                'final_score' => $review->final_score === null ? null : (float) $review->final_score,
                'grade' => $review->grade?->value,
                'submitted_at' => $review->submitted_at?->toIso8601String(),
            ])->values()->all(),
            'grade_distribution' => collect(ReviewGrade::cases())->mapWithKeys(fn (ReviewGrade $g) => [$g->value => (int) ($grades[$g->value] ?? 0)])->all(),
        ];
    }

    // ── Calculation ─────────────────────────────────────────────────────

    /**
     * KPI + discipline prefill for one employee/semester.
     *
     * @return array<string, mixed>
     */
    public function prefill(Employee $employee, int $year, int $semester): array
    {
        $kpi = $this->kpiService->semesterAverage($employee, $year, $semester);
        $summary = $this->disciplineSummary($employee, $year, $semester);

        return [
            'kpi_average' => $kpi['average'],
            'kpi_months' => $kpi['months'],
            'discipline_summary' => $summary,
            'discipline_score' => self::disciplineScore($summary),
        ];
    }

    /**
     * @return array{teguran_lisan: int, sp1: int, sp2: int, sp3: int, catatan: int, active_sp_at_end: string|null}
     */
    public function disciplineSummary(Employee $employee, int $year, int $semester): array
    {
        $start = self::semesterStart($year, $semester);
        $end = self::semesterEnd($year, $semester);

        $counts = DisciplinaryRecord::query()
            ->where('employee_id', $employee->id)
            ->effective()
            ->whereDate('issued_on', '>=', $start->toDateString())
            ->whereDate('issued_on', '<=', $end->toDateString())
            ->selectRaw('type, COUNT(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        // While the semester is still running, "at the end" means today.
        $endDay = Carbon::parse(min($end->toDateString(), now()->toDateString()));

        $activeSp = DisciplinaryRecord::query()
            ->where('employee_id', $employee->id)
            ->activeSp($endDay)
            ->pluck('type')
            ->map(fn ($type) => $type instanceof DisciplinaryType ? $type : DisciplinaryType::from($type))
            ->sortByDesc(fn (DisciplinaryType $type) => $type->spLevel())
            ->first();

        return [
            'teguran_lisan' => (int) ($counts[DisciplinaryType::TeguranLisan->value] ?? 0),
            'sp1' => (int) ($counts[DisciplinaryType::Sp1->value] ?? 0),
            'sp2' => (int) ($counts[DisciplinaryType::Sp2->value] ?? 0),
            'sp3' => (int) ($counts[DisciplinaryType::Sp3->value] ?? 0),
            'catatan' => (int) ($counts[DisciplinaryType::Catatan->value] ?? 0),
            'active_sp_at_end' => $activeSp?->value,
        ];
    }

    /** Default rule: 100 − 10×teguran − 20×SP1 − 35×SP2 − 50×SP3, never below 0. */
    public static function disciplineScore(array $summary): float
    {
        $penalty = self::DISCIPLINE_PENALTY['TEGURAN_LISAN'] * ($summary['teguran_lisan'] ?? 0)
            + self::DISCIPLINE_PENALTY['SP1'] * ($summary['sp1'] ?? 0)
            + self::DISCIPLINE_PENALTY['SP2'] * ($summary['sp2'] ?? 0)
            + self::DISCIPLINE_PENALTY['SP3'] * ($summary['sp3'] ?? 0);

        return (float) max(0, 100 - $penalty);
    }

    /**
     * qualitative_score / final_score / grade from the review's current
     * parts. Final score stays null until all four aspects are filled.
     *
     * @return array{qualitative_score: float|null, final_score: float|null, grade: string|null}
     */
    public function scores(PerformanceReview $review): array
    {
        $qualitativeScore = $this->qualitativeComplete($review->qualitative)
            ? round(array_sum(array_map(fn ($aspect) => (int) $review->qualitative[$aspect], self::ASPECTS)) / count(self::ASPECTS) / 5 * 100, 2)
            : null;

        $final = self::finalScore(
            $review->kpi_average === null ? null : (float) $review->kpi_average,
            $qualitativeScore,
            $review->discipline_score === null ? 100.0 : (float) $review->discipline_score,
            $review->weights ?? self::DEFAULT_WEIGHTS,
        );

        return [
            'qualitative_score' => $qualitativeScore,
            'final_score' => $final,
            'grade' => $final === null ? null : ReviewGrade::fromScore($final)->value,
        ];
    }

    /** Weighted sum; KPI capped at KPI_CAP; null KPI → its weight redistributed over the other two. */
    public static function finalScore(?float $kpiAverage, ?float $qualitativeScore, float $disciplineScore, array $weights): ?float
    {
        if ($qualitativeScore === null) {
            return null;
        }

        $effective = self::effectiveWeights($weights, $kpiAverage === null);

        if ($effective === null) {
            return null;
        }

        $kpi = $kpiAverage === null ? 0.0 : min($kpiAverage, self::KPI_CAP);

        return round(
            ($effective['kpi'] * $kpi + $effective['qualitative'] * $qualitativeScore + $effective['discipline'] * $disciplineScore) / 100,
            2,
        );
    }

    /**
     * Weights actually applied: as entered, or — without a KPI average —
     * the KPI share spread proportionally over qualitative + discipline.
     * Null when nothing is left to weigh (KPI 100% and no KPI data).
     *
     * @return array{kpi: float, qualitative: float, discipline: float}|null
     */
    public static function effectiveWeights(array $weights, bool $withoutKpi): ?array
    {
        $kpi = (float) ($weights['kpi'] ?? 0);
        $qualitative = (float) ($weights['qualitative'] ?? 0);
        $discipline = (float) ($weights['discipline'] ?? 0);

        if (! $withoutKpi) {
            return ['kpi' => $kpi, 'qualitative' => $qualitative, 'discipline' => $discipline];
        }

        $rest = $qualitative + $discipline;

        if ($rest <= 0) {
            return null;
        }

        return [
            'kpi' => 0.0,
            'qualitative' => round($qualitative / $rest * 100, 4),
            'discipline' => round($discipline / $rest * 100, 4),
        ];
    }

    // ── Guards ──────────────────────────────────────────────────────────

    private function assertCreatable(Employee $employee, int $year, int $semester): void
    {
        if (! in_array($semester, [1, 2], true)) {
            throw ValidationException::withMessages(['semester' => 'Semester harus 1 atau 2.']);
        }

        $this->assertSemesterStarted($year, $semester);

        if (! $employee->is_active || ! Employee::query()->hrEligible()->whereKey($employee->id)->exists()) {
            throw ValidationException::withMessages(['employee_id' => 'Evaluasi hanya untuk karyawan tetap yang aktif.']);
        }

        if ($employee->join_date && $employee->join_date->gt(self::semesterEnd($year, $semester))) {
            throw ValidationException::withMessages(['employee_id' => "{$employee->name} belum bergabung pada Semester {$semester} {$year}."]);
        }

        if ($this->exists($employee, $year, $semester)) {
            throw $this->duplicate($employee, $year, $semester);
        }
    }

    private function assertSemesterStarted(int $year, int $semester): void
    {
        if (! self::hasStarted($year, $semester)) {
            throw ValidationException::withMessages(['semester' => "Semester {$semester} {$year} belum dimulai."]);
        }
    }

    private function exists(Employee $employee, int $year, int $semester): bool
    {
        return PerformanceReview::query()->where('employee_id', $employee->id)->where('year', $year)->where('semester', $semester)->exists();
    }

    private function duplicate(Employee $employee, int $year, int $semester): ValidationException
    {
        return ValidationException::withMessages(['employee_id' => "{$employee->name} sudah punya evaluasi Semester {$semester} {$year}."]);
    }

    private function lockDraft(PerformanceReview $review): PerformanceReview
    {
        return $this->lockInStatus($review, ReviewStatus::Draft, 'Evaluasi hanya bisa diubah selama berstatus draf.');
    }

    /** Row lock, reload, and re-check the status inside the transaction. */
    private function lockInStatus(PerformanceReview $review, ReviewStatus $required, string $message): PerformanceReview
    {
        $locked = PerformanceReview::whereKey($review->getKey())->lockForUpdate()->firstOrFail();
        $locked->load(['employee.user', 'reviewer']);

        if ($locked->status !== $required) {
            throw ValidationException::withMessages(['status' => $message]);
        }

        return $locked;
    }

    private function qualitativeComplete(?array $qualitative): bool
    {
        foreach (self::ASPECTS as $aspect) {
            $value = $qualitative[$aspect] ?? null;

            if (! is_numeric($value) || (int) $value < 1 || (int) $value > 5) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, int|null> */
    private function normalizeQualitative(array $input): array
    {
        $result = [];

        foreach (self::ASPECTS as $aspect) {
            $value = $input[$aspect] ?? null;

            if ($value !== null && (! is_numeric($value) || (int) $value < 1 || (int) $value > 5)) {
                throw ValidationException::withMessages(["qualitative.{$aspect}" => 'Nilai aspek harus 1 sampai 5.']);
            }

            $result[$aspect] = $value === null ? null : (int) $value;
        }

        return $result;
    }

    /** @return array{kpi: int, qualitative: int, discipline: int} */
    private function normalizeWeights(array $input): array
    {
        $weights = [];

        foreach (array_keys(self::DEFAULT_WEIGHTS) as $part) {
            $value = $input[$part] ?? null;

            if (! is_numeric($value) || (int) $value < 0 || (int) $value > 100) {
                throw ValidationException::withMessages(["weights.{$part}" => 'Bobot harus angka 0 sampai 100.']);
            }

            $weights[$part] = (int) $value;
        }

        if (array_sum($weights) !== 100) {
            throw ValidationException::withMessages(['weights' => 'Total bobot harus 100%.']);
        }

        return $weights;
    }

    /** @return array<string, mixed> */
    private function auditSnapshot(PerformanceReview $review): array
    {
        return [
            'employee_id' => $review->employee_id,
            'period' => $review->periodLabel(),
            'status' => $review->status,
            'kpi_average' => $review->kpi_average,
            'kpi_months' => $review->kpi_months,
            'discipline_summary' => $review->discipline_summary,
            'discipline_score' => $review->discipline_score,
            'qualitative' => $review->qualitative,
            'qualitative_score' => $review->qualitative_score,
            'weights' => $review->weights,
            'final_score' => $review->final_score,
            'grade' => $review->grade,
            'recommendation' => $review->recommendation,
            'notes' => $review->notes,
            'return_note' => $review->return_note,
            'approved_by' => $review->approved_by,
            'approved_at' => $review->approved_at,
            'acknowledged_at' => $review->acknowledged_at,
        ];
    }
}
