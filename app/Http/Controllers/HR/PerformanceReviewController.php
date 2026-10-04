<?php

namespace App\Http\Controllers\HR;

use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\HR\BulkPerformanceReviewRequest;
use App\Http\Requests\HR\ReturnPerformanceReviewRequest;
use App\Http\Requests\HR\StorePerformanceReviewRequest;
use App\Http\Requests\HR\UpdatePerformanceReviewRequest;
use App\Models\Division;
use App\Models\Employee;
use App\Models\PerformanceReview;
use App\Models\SiteSetting;
use App\Services\PerformanceReviewService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * SDM (Sprint 10, §3.4) — semester performance reviews. Reads are open to
 * the whole `module:hr` group (CEO + HR); HR writes until SUBMITTED, the
 * CEO approves or returns (route `role:` per action). No destroy route —
 * reviews are never deleted. Every query is scoped to HR-eligible
 * employees (decision #11).
 */
class PerformanceReviewController extends Controller
{
    public function index(Request $request): Response
    {
        $previous = PerformanceReviewService::previousSemester();
        $current = PerformanceReviewService::currentSemester();

        // Default view: the last completed semester — the one being reviewed.
        $year = $request->integer('year') ?: $previous['year'];
        $semesterInput = $request->string('semester')->value();
        $semester = match (true) {
            $semesterInput === 'all' => null,
            in_array($semesterInput, ['1', '2'], true) => (int) $semesterInput,
            $request->has('year') => null,
            default => $previous['semester'],
        };
        $status = ReviewStatus::tryFrom($request->string('status')->value());
        $divisionId = $request->integer('division') ?: null;
        $positionId = $request->integer('position') ?: null;

        $base = PerformanceReview::query()
            ->where('year', $year)
            ->when($semester, fn ($query) => $query->where('semester', $semester))
            ->whereHas('employee', fn ($query) => $query->hrEligible()->inStructure($divisionId, $positionId));

        $counts = (clone $base)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        $reviews = (clone $base)
            ->when($status, fn ($query) => $query->where('status', $status->value))
            ->with(['employee:id,name,position_id', 'employee.position:id,name,division_id', 'employee.position.division:id,name', 'reviewer:id,name'])
            ->orderByDesc('semester')
            ->get()
            ->sortBy(fn (PerformanceReview $review) => $review->employee?->name)
            ->values()
            ->map(fn (PerformanceReview $review) => [
                'id' => $review->id,
                'employee' => [
                    'id' => $review->employee->id,
                    'name' => $review->employee->name,
                    'position' => $review->employee->position?->name,
                    'division' => $review->employee->position?->division?->name,
                ],
                'year' => $review->year,
                'semester' => $review->semester,
                'period_label' => $review->periodLabel(),
                'final_score' => $review->final_score === null ? null : (float) $review->final_score,
                'grade' => $review->grade?->value,
                'recommendation' => $review->recommendation?->value,
                'status' => $review->status->value,
                'has_return_note' => filled($review->return_note),
                'reviewer' => $review->reviewer?->name,
            ]);

        $canManage = $request->user()->hasAnyRole(['HR', 'SUPERADMIN']);
        $firstYear = min((int) (PerformanceReview::min('year') ?? $current['year']), $current['year'] - 1);

        return Inertia::render('HR/Reviews/Index', [
            'reviews' => $reviews,
            'counts' => collect(ReviewStatus::cases())->mapWithKeys(fn (ReviewStatus $s) => [$s->value => (int) ($counts[$s->value] ?? 0)]),
            'filters' => [
                'year' => $year,
                'semester' => $semester,
                'status' => $status?->value,
                'division' => $divisionId,
                'position' => $positionId,
            ],
            'years' => range($current['year'], max(2020, $firstYear)),
            'current' => $current,
            'previous' => $previous,
            'structure' => Division::query()
                ->ordered()
                ->with(['positions' => fn ($query) => $query->ordered()->select(['id', 'division_id', 'name', 'is_active'])])
                ->get(['id', 'name', 'is_active']),
            'canManage' => $canManage,
            'employees' => $canManage ? $this->creatableEmployees() : [],
            // "employeeId:year:semester" of every existing review — the create dialog hides those.
            'existing' => $canManage
                ? PerformanceReview::query()->get(['employee_id', 'year', 'semester'])->map(fn ($r) => "{$r->employee_id}:{$r->year}:{$r->semester}")->values()
                : [],
        ]);
    }

    public function show(Request $request, PerformanceReview $performanceReview, PerformanceReviewService $service): Response
    {
        $employee = $this->eligibleEmployee($performanceReview);
        $user = $request->user();

        return Inertia::render('HR/Reviews/Show', [
            'review' => [
                ...$service->present($performanceReview),
                'employee' => [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'position' => $employee->position?->name,
                    'division' => $employee->position?->division?->name,
                    'is_active' => $employee->is_active,
                    'has_account' => $employee->user_id !== null,
                ],
            ],
            'canManage' => $user->hasAnyRole(['HR', 'SUPERADMIN']),
            'canDecide' => $user->hasAnyRole(['CEO', 'SUPERADMIN']),
            'disciplinePenalty' => PerformanceReviewService::DISCIPLINE_PENALTY,
            'kpiCap' => PerformanceReviewService::KPI_CAP,
        ]);
    }

    public function pdf(PerformanceReview $performanceReview, PerformanceReviewService $service): HttpResponse
    {
        $employee = $this->eligibleEmployee($performanceReview);

        return self::renderPdf($performanceReview, $employee, $service, false);
    }

    public function store(StorePerformanceReviewRequest $request, PerformanceReviewService $service): RedirectResponse
    {
        $employee = Employee::findOrFail($request->validated('employee_id'));
        $review = $service->create($employee, (int) $request->validated('year'), (int) $request->validated('semester'), $request->user());

        return redirect()->route('hr.reviews.show', $review)->with('success', "Evaluasi {$review->periodLabel()} untuk {$employee->name} dibuat.");
    }

    public function bulk(BulkPerformanceReviewRequest $request, PerformanceReviewService $service): RedirectResponse
    {
        $year = (int) $request->validated('year');
        $semester = (int) $request->validated('semester');
        $created = $service->createForAll($year, $semester, $request->user());

        return redirect()->route('hr.reviews.index', ['year' => $year, 'semester' => $semester])->with(
            'success',
            $created > 0
                ? "{$created} evaluasi Semester {$semester} {$year} dibuat. Karyawan yang sudah punya evaluasi dilewati."
                : "Semua karyawan aktif sudah punya evaluasi Semester {$semester} {$year}.",
        );
    }

    public function update(UpdatePerformanceReviewRequest $request, PerformanceReview $performanceReview, PerformanceReviewService $service): RedirectResponse
    {
        $this->eligibleEmployee($performanceReview);
        $service->update($performanceReview, $request->validated(), $request->user());

        return back()->with('success', 'Evaluasi disimpan.');
    }

    public function refresh(Request $request, PerformanceReview $performanceReview, PerformanceReviewService $service): RedirectResponse
    {
        $this->eligibleEmployee($performanceReview);
        $service->refresh($performanceReview, $request->user());

        return back()->with('success', 'Data KPI dan kedisiplinan diperbarui.');
    }

    public function submit(Request $request, PerformanceReview $performanceReview, PerformanceReviewService $service): RedirectResponse
    {
        $this->eligibleEmployee($performanceReview);
        $service->submit($performanceReview, $request->user());

        return back()->with('success', 'Evaluasi diajukan ke CEO.');
    }

    public function approve(Request $request, PerformanceReview $performanceReview, PerformanceReviewService $service): RedirectResponse
    {
        $this->eligibleEmployee($performanceReview);
        $service->approve($performanceReview, $request->user());

        return back()->with('success', 'Evaluasi disetujui.');
    }

    public function return(ReturnPerformanceReviewRequest $request, PerformanceReview $performanceReview, PerformanceReviewService $service): RedirectResponse
    {
        $this->eligibleEmployee($performanceReview);
        $service->return($performanceReview, $request->validated('note'), $request->user());

        return back()->with('success', 'Evaluasi dikembalikan ke SDM.');
    }

    /**
     * DomPDF export, shared with the employee's own copy
     * (MyPerformanceReviewController) — `$forEmployee` drops `return_note`.
     */
    public static function renderPdf(PerformanceReview $review, Employee $employee, PerformanceReviewService $service, bool $forEmployee): HttpResponse
    {
        $employee->loadMissing(['position:id,name,division_id', 'position.division:id,name']);

        $pdf = Pdf::loadView('pdf.performance-review', [
            'review' => $service->present($review, $forEmployee),
            'employee' => $employee,
            'aspects' => PerformanceReviewService::ASPECT_LABELS,
            'siteSettings' => SiteSetting::current(),
        ]);

        return $pdf->stream('evaluasi-'.Str::slug($employee->name)."-s{$review->semester}-{$review->year}.pdf");
    }

    /** 404 for reviews of employees outside SDM (decision #11). */
    private function eligibleEmployee(PerformanceReview $review): Employee
    {
        $employee = Employee::query()
            ->hrEligible()
            ->with(['position:id,name,division_id', 'position.division:id,name'])
            ->find($review->employee_id);

        abort_unless($employee, 404);

        return $employee;
    }

    /** Active HR-eligible employees for the "Buat Evaluasi" picker. */
    private function creatableEmployees(): array
    {
        return Employee::query()
            ->hrEligible()
            ->active()
            ->with(['position:id,name,division_id', 'position.division:id,name'])
            ->orderBy('name')
            ->get(['id', 'name', 'position_id', 'join_date'])
            ->map(fn (Employee $employee) => [
                'id' => $employee->id,
                'name' => $employee->name,
                'position' => $employee->position?->name,
                'division' => $employee->position?->division?->name,
                'join_date' => $employee->join_date?->toDateString(),
            ])
            ->all();
    }
}
