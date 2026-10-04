<?php

use App\Enums\DisciplinaryType;
use App\Enums\ReviewStatus;
use App\Models\AuditLog;
use App\Models\DisciplinaryRecord;
use App\Models\Employee;
use App\Models\KpiPeriod;
use App\Models\KpiScore;
use App\Models\Notification;
use App\Models\PerformanceReview;
use App\Models\Position;
use App\Models\User;
use App\Services\PerformanceReviewService;
use Database\Seeders\Demo\PerformanceReviewDemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

// "Today" is fixed inside Semester 2 2026, so the last completed semester is S1 2026.
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo('2026-10-04 10:00:00');
});

function prUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** Joined well before every semester under test (factory join dates follow the real clock). */
function prEmployee(array $attributes = []): Employee
{
    // One shared position per test: DivisionFactory's name pool only has 7 unique values.
    $positionId = Position::query()->value('id') ?? Position::factory()->create()->id;

    return Employee::factory()->create(['join_date' => '2024-01-02', 'position_id' => $positionId, ...$attributes]);
}

function prService(): PerformanceReviewService
{
    return app(PerformanceReviewService::class);
}

/** A closed (or open) KPI month with one score row totalling `$total`. */
function prKpiMonth(Employee $employee, string $period, float $total, string $status = 'CLOSED'): void
{
    $kpiPeriod = KpiPeriod::firstOrCreate(['period' => $period], ['status' => $status]);

    KpiScore::create([
        'kpi_period_id' => $kpiPeriod->id,
        'employee_id' => $employee->id,
        'indicator_name' => 'Indikator uji',
        'source' => 'MANUAL',
        'target' => 100,
        'weight' => 100,
        'direction' => 'HIGHER_BETTER',
        'actual' => $total,
        'score' => $total,
        'weighted_score' => $total,
    ]);
}

function prDiscipline(Employee $employee, DisciplinaryType $type, string $issuedOn, ?string $validUntil = null, ?int $voids = null): DisciplinaryRecord
{
    return DisciplinaryRecord::create([
        'employee_id' => $employee->id,
        'type' => $type->value,
        'issued_on' => $issuedOn,
        'valid_until' => $validUntil,
        'description' => 'Uji',
        'voids_id' => $voids,
        'recorded_by' => User::factory()->create()->id,
    ]);
}

/** A DRAFT S1 2026 review created through the service, with every aspect filled. */
function prFilledReview(?Employee $employee = null, ?User $hr = null, string $recommendation = 'BONUS'): PerformanceReview
{
    $hr ??= prUser('HR');
    $review = prService()->create($employee ?? prEmployee(), 2026, 1, $hr);

    return prService()->update($review, [
        'qualitative' => ['attitude' => 4, 'teamwork' => 4, 'initiative' => 4, 'responsibility' => 4],
        'weights' => ['kpi' => 60, 'qualitative' => 25, 'discipline' => 15],
        'recommendation' => $recommendation,
        'notes' => 'Kinerja baik.',
    ], $hr);
}

function prApprovedReview(Employee $employee, string $recommendation = 'NAIK_GAJI'): PerformanceReview
{
    $hr = prUser('HR');
    $review = prService()->submit(prFilledReview($employee, $hr, $recommendation), $hr);

    return prService()->approve($review, prUser('CEO'));
}

/** An employee linked to a non-field-staff account (for "Milik Saya"). */
function prLinkedEmployee(string $role = 'DESIGNER'): array
{
    $user = prUser($role);

    return [prEmployee(['user_id' => $user->id]), $user];
}

// ── RBAC ────────────────────────────────────────────────────────────────

test('CEO, HR and SUPERADMIN can read reviews (index, detail, PDF)', function (string $role) {
    $review = PerformanceReview::factory()->create();
    $user = prUser($role);

    $this->actingAs($user)->get(route('hr.reviews.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('HR/Reviews/Index')->has('reviews', 1)->where('filters.year', 2026)->where('filters.semester', 1));

    $this->actingAs($user)->get(route('hr.reviews.show', $review))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('HR/Reviews/Show')->where('review.id', $review->id));

    $this->actingAs($user)->get(route('hr.reviews.pdf', $review))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
})->with(['CEO', 'HR', 'SUPERADMIN']);

test('every other role is refused every review route', function (string $role) {
    $review = PerformanceReview::factory()->create();
    $employee = prEmployee();
    $user = prUser($role);

    $this->actingAs($user)->get(route('hr.reviews.index'))->assertForbidden();
    $this->actingAs($user)->get(route('hr.reviews.show', $review))->assertForbidden();
    $this->actingAs($user)->get(route('hr.reviews.pdf', $review))->assertForbidden();
    $this->actingAs($user)->post(route('hr.reviews.store'), ['employee_id' => $employee->id, 'year' => 2026, 'semester' => 1])->assertForbidden();
    $this->actingAs($user)->post(route('hr.reviews.bulk'), ['year' => 2026, 'semester' => 1])->assertForbidden();
    $this->actingAs($user)->put(route('hr.reviews.update', $review), [])->assertForbidden();
    $this->actingAs($user)->post(route('hr.reviews.refresh', $review))->assertForbidden();
    $this->actingAs($user)->post(route('hr.reviews.submit', $review))->assertForbidden();
    $this->actingAs($user)->post(route('hr.reviews.approve', $review))->assertForbidden();
    $this->actingAs($user)->post(route('hr.reviews.return', $review), ['note' => 'x'])->assertForbidden();
})->with(['FINANCE', 'PM', 'DESIGNER', 'MARKETING', 'ESTIMATOR', 'QA', 'LOGISTICS', 'FIELD_STAFF']);

test('the CEO reads and decides but cannot create or edit', function () {
    $ceo = prUser('CEO');
    $review = PerformanceReview::factory()->create();

    $this->actingAs($ceo)->post(route('hr.reviews.store'), ['employee_id' => $review->employee_id, 'year' => 2026, 'semester' => 2])->assertForbidden();
    $this->actingAs($ceo)->post(route('hr.reviews.bulk'), ['year' => 2026, 'semester' => 1])->assertForbidden();
    $this->actingAs($ceo)->put(route('hr.reviews.update', $review), [])->assertForbidden();
    $this->actingAs($ceo)->post(route('hr.reviews.refresh', $review))->assertForbidden();
    $this->actingAs($ceo)->post(route('hr.reviews.submit', $review))->assertForbidden();

    $this->actingAs($ceo)->get(route('hr.reviews.show', $review))
        ->assertInertia(fn (Assert $page) => $page->where('canManage', false)->where('canDecide', true));
    $this->actingAs($ceo)->get(route('hr.reviews.index'))
        ->assertInertia(fn (Assert $page) => $page->where('canManage', false)->has('employees', 0));
});

test('HR manages but cannot approve or return', function () {
    $hr = prUser('HR');
    $review = prService()->submit(prFilledReview(hr: $hr), $hr);

    $this->actingAs($hr)->post(route('hr.reviews.approve', $review))->assertForbidden();
    $this->actingAs($hr)->post(route('hr.reviews.return', $review), ['note' => 'Ulang'])->assertForbidden();

    $this->actingAs($hr)->get(route('hr.reviews.show', $review))
        ->assertInertia(fn (Assert $page) => $page->where('canManage', true)->where('canDecide', false));
    expect($review->fresh()->status)->toBe(ReviewStatus::Submitted);
});

test('no review route can delete a review, and the model refuses deletion', function () {
    $methods = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'hr/reviews') || str_starts_with($route->uri(), 'saya/reviews'))
        ->flatMap(fn ($route) => $route->methods());

    expect($methods)->not->toBeEmpty()
        ->and($methods->contains('DELETE'))->toBeFalse();

    expect(fn () => PerformanceReview::factory()->create()->delete())->toThrow(LogicException::class);
});

// ── Create + prefill ────────────────────────────────────────────────────

test('HR creates a review prefilled from closed KPI months and the semester discipline records', function () {
    $hr = prUser('HR');
    $employee = prEmployee(['join_date' => '2024-01-02']);

    prKpiMonth($employee, '2026-01', 80);
    prKpiMonth($employee, '2026-02', 100);
    prKpiMonth($employee, '2026-03', 50, 'OPEN');   // still open — ignored
    prKpiMonth($employee, '2025-12', 10);           // previous semester — ignored
    prKpiMonth($employee, '2026-07', 10);           // next semester — ignored

    prDiscipline($employee, DisciplinaryType::TeguranLisan, '2026-02-10');
    prDiscipline($employee, DisciplinaryType::Catatan, '2026-03-01');
    prDiscipline($employee, DisciplinaryType::Sp1, '2026-04-01', '2026-09-30');
    $voided = prDiscipline($employee, DisciplinaryType::Sp2, '2026-05-01', '2026-10-31');
    prDiscipline($employee, DisciplinaryType::Pembatalan, '2026-05-02', null, $voided->id);
    prDiscipline($employee, DisciplinaryType::TeguranLisan, '2025-12-20'); // before the semester
    prDiscipline($employee, DisciplinaryType::TeguranLisan, '2026-07-02'); // after the semester

    $this->actingAs($hr)->post(route('hr.reviews.store'), ['employee_id' => $employee->id, 'year' => 2026, 'semester' => 1])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('hr.reviews.show', PerformanceReview::sole()));

    $review = PerformanceReview::sole();
    expect($review->status)->toBe(ReviewStatus::Draft)
        ->and($review->reviewer_id)->toBe($hr->id)
        ->and((float) $review->kpi_average)->toBe(90.0)
        ->and($review->kpi_months)->toBe(2)
        ->and($review->discipline_summary)->toBe([
            'teguran_lisan' => 1, 'sp1' => 1, 'sp2' => 0, 'sp3' => 0, 'catatan' => 1, 'active_sp_at_end' => 'SP1',
        ])
        ->and((float) $review->discipline_score)->toBe(70.0) // 100 − 10 − 20
        ->and($review->weights)->toBe(['kpi' => 60, 'qualitative' => 25, 'discipline' => 15])
        ->and($review->final_score)->toBeNull();

    expect(AuditLog::where('action', 'hr.review_created')->where('model_id', $review->id)->exists())->toBeTrue();
});

test('a review without closed KPI months has a null KPI average', function () {
    $review = prService()->create(prEmployee(), 2026, 1, prUser('HR'));

    expect($review->kpi_average)->toBeNull()
        ->and($review->kpi_months)->toBe(0)
        ->and((float) $review->discipline_score)->toBe(100.0);
});

test('one review per employee and semester, never for a future semester', function () {
    $hr = prUser('HR');
    $employee = prEmployee();
    prService()->create($employee, 2026, 1, $hr);

    $this->actingAs($hr)->post(route('hr.reviews.store'), ['employee_id' => $employee->id, 'year' => 2026, 'semester' => 1])
        ->assertSessionHasErrors('employee_id');

    $this->actingAs($hr)->post(route('hr.reviews.store'), ['employee_id' => $employee->id, 'year' => 2027, 'semester' => 1])
        ->assertSessionHasErrors(['year' => 'Tahun tidak boleh di masa depan.']);

    $this->travelTo('2026-03-01 10:00:00');
    $this->actingAs($hr)->post(route('hr.reviews.store'), ['employee_id' => $employee->id, 'year' => 2026, 'semester' => 2])
        ->assertSessionHasErrors(['semester' => 'Semester 2 2026 belum dimulai.']);

    // The semester running now is allowed.
    $this->travelTo('2026-10-04 10:00:00');
    $this->actingAs($hr)->post(route('hr.reviews.store'), ['employee_id' => $employee->id, 'year' => 2026, 'semester' => 2])
        ->assertSessionHasNoErrors();

    expect(PerformanceReview::count())->toBe(2);
});

test('inactive employees, field-staff-linked rows and late joiners cannot be reviewed', function () {
    $hr = prUser('HR');

    $inactive = prEmployee(['is_active' => false]);
    expect(fn () => prService()->create($inactive, 2026, 1, $hr))->toThrow(ValidationException::class);

    $fieldStaffLinked = prEmployee(['user_id' => prUser('FIELD_STAFF')->id]);
    expect(fn () => prService()->create($fieldStaffLinked, 2026, 1, $hr))->toThrow(ValidationException::class);

    $lateJoiner = prEmployee(['join_date' => '2026-08-01']);
    expect(fn () => prService()->create($lateJoiner, 2026, 1, $hr))->toThrow(ValidationException::class);

    expect(PerformanceReview::count())->toBe(0);
});

test('bulk create makes one draft per eligible employee and skips existing ones', function () {
    $hr = prUser('HR');
    $existing = prEmployee();
    prService()->create($existing, 2026, 1, $hr);
    prEmployee();
    prEmployee();
    prEmployee(['is_active' => false]);
    prEmployee(['user_id' => prUser('FIELD_STAFF')->id]);

    $this->actingAs($hr)->post(route('hr.reviews.bulk'), ['year' => 2026, 'semester' => 1])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', '2 evaluasi Semester 1 2026 dibuat. Karyawan yang sudah punya evaluasi dilewati.');

    expect(PerformanceReview::where('year', 2026)->where('semester', 1)->count())->toBe(3);

    $this->actingAs($hr)->post(route('hr.reviews.bulk'), ['year' => 2026, 'semester' => 1])
        ->assertSessionHas('success', 'Semua karyawan aktif sudah punya evaluasi Semester 1 2026.');
});

// ── Scores ──────────────────────────────────────────────────────────────

test('final score is the weighted sum and the grade follows it', function () {
    $hr = prUser('HR');
    $employee = prEmployee();
    prKpiMonth($employee, '2026-01', 90);
    $review = prService()->create($employee, 2026, 1, $hr);

    $this->actingAs($hr)->put(route('hr.reviews.update', $review), [
        'qualitative' => ['attitude' => 5, 'teamwork' => 4, 'initiative' => 4, 'responsibility' => 3],
        'weights' => ['kpi' => 60, 'qualitative' => 25, 'discipline' => 15],
        'recommendation' => 'BONUS',
        'notes' => 'Baik.',
    ])->assertSessionHasNoErrors();

    $review->refresh();
    // qualitative 16/4 = 4 → 80; final = 0.6×90 + 0.25×80 + 0.15×100 = 89
    expect((float) $review->qualitative_score)->toBe(80.0)
        ->and((float) $review->final_score)->toBe(89.0)
        ->and($review->grade->value)->toBe('B')
        ->and($review->recommendation->value)->toBe('BONUS');

    expect(AuditLog::where('action', 'hr.review_updated')->where('model_id', $review->id)->exists())->toBeTrue();
});

test('a KPI average above 100 is capped at 100 in the final score', function () {
    $hr = prUser('HR');
    $employee = prEmployee();
    prKpiMonth($employee, '2026-01', 120);
    $review = prService()->create($employee, 2026, 1, $hr);

    $review = prService()->update($review, [
        'qualitative' => ['attitude' => 5, 'teamwork' => 5, 'initiative' => 5, 'responsibility' => 5],
        'weights' => ['kpi' => 60, 'qualitative' => 25, 'discipline' => 15],
    ], $hr);

    expect((float) $review->kpi_average)->toBe(120.0)
        ->and((float) $review->final_score)->toBe(100.0)
        ->and($review->grade->value)->toBe('A')
        ->and(prService()->present($review)['kpi_capped'])->toBeTrue();
});

test('without KPI data the KPI weight is redistributed over the other parts', function () {
    $hr = prUser('HR');
    $employee = prEmployee();
    prDiscipline($employee, DisciplinaryType::Sp1, '2026-02-01', '2026-07-31');
    $review = prService()->create($employee, 2026, 1, $hr);

    $review = prService()->update($review, [
        'qualitative' => ['attitude' => 3, 'teamwork' => 3, 'initiative' => 3, 'responsibility' => 3],
        'weights' => ['kpi' => 60, 'qualitative' => 30, 'discipline' => 10],
    ], $hr);

    // qualitative 60, discipline 80; weights become 75% / 25% → 45 + 20 = 65
    expect((float) $review->final_score)->toBe(65.0)
        ->and($review->grade->value)->toBe('D');

    $presented = prService()->present($review);
    expect($presented['kpi_redistributed'])->toBeTrue()
        ->and($presented['effective_weights'])->toBe(['kpi' => 0.0, 'qualitative' => 75.0, 'discipline' => 25.0]);

    expect(PerformanceReviewService::finalScore(null, 80, 100, ['kpi' => 100, 'qualitative' => 0, 'discipline' => 0]))->toBeNull()
        ->and(PerformanceReviewService::disciplineScore(['teguran_lisan' => 1, 'sp1' => 1, 'sp2' => 1, 'sp3' => 1]))->toBe(0.0);
});

test('update input is validated with Indonesian messages', function () {
    $hr = prUser('HR');
    $review = prService()->create(prEmployee(), 2026, 1, $hr);

    $this->actingAs($hr)->put(route('hr.reviews.update', $review), [
        'qualitative' => ['attitude' => 6, 'teamwork' => 0],
        'weights' => ['kpi' => 50, 'qualitative' => 25, 'discipline' => 15],
        'recommendation' => 'PROMOSI',
    ])->assertSessionHasErrors([
        'qualitative.attitude' => 'Nilai Sikap harus 1 sampai 5.',
        'qualitative.teamwork' => 'Nilai Kerja sama harus 1 sampai 5.',
        'recommendation' => 'Rekomendasi tidak valid.',
    ]);

    $this->actingAs($hr)->put(route('hr.reviews.update', $review), [
        'qualitative' => ['attitude' => 3],
        'weights' => ['kpi' => 50, 'qualitative' => 25, 'discipline' => 15],
    ])->assertSessionHasErrors(['weights' => 'Total bobot harus 100% (sekarang 90%).']);

    // A partial draft is fine — no final score yet.
    $this->actingAs($hr)->put(route('hr.reviews.update', $review), [
        'qualitative' => ['attitude' => 3],
        'weights' => ['kpi' => 60, 'qualitative' => 25, 'discipline' => 15],
    ])->assertSessionHasNoErrors();
    expect($review->fresh()->final_score)->toBeNull();
});

test('refresh re-pulls the KPI and discipline prefill', function () {
    $hr = prUser('HR');
    $employee = prEmployee();
    $review = prService()->create($employee, 2026, 1, $hr);
    expect($review->kpi_average)->toBeNull();

    prKpiMonth($employee, '2026-04', 75);
    prDiscipline($employee, DisciplinaryType::TeguranLisan, '2026-04-10');

    $this->actingAs($hr)->post(route('hr.reviews.refresh', $review))->assertSessionHasNoErrors();

    $review->refresh();
    expect((float) $review->kpi_average)->toBe(75.0)
        ->and($review->kpi_months)->toBe(1)
        ->and((float) $review->discipline_score)->toBe(90.0);
});

// ── State machine ───────────────────────────────────────────────────────

test('submit needs every aspect and a recommendation, then notifies the CEO', function () {
    $hr = prUser('HR');
    $ceo = prUser('CEO');
    $review = prService()->create(prEmployee(), 2026, 1, $hr);

    $this->actingAs($hr)->post(route('hr.reviews.submit', $review))->assertSessionHasErrors('qualitative');

    prService()->update($review, [
        'qualitative' => ['attitude' => 4, 'teamwork' => 4, 'initiative' => 4, 'responsibility' => 4],
        'weights' => PerformanceReviewService::DEFAULT_WEIGHTS,
    ], $hr);
    $this->actingAs($hr)->post(route('hr.reviews.submit', $review))->assertSessionHasErrors('recommendation');

    prService()->update($review->fresh(), [
        'qualitative' => ['attitude' => 4, 'teamwork' => 4, 'initiative' => 4, 'responsibility' => 4],
        'weights' => PerformanceReviewService::DEFAULT_WEIGHTS,
        'recommendation' => 'TIDAK_ADA',
    ], $hr);
    $this->actingAs($hr)->post(route('hr.reviews.submit', $review))->assertSessionHasNoErrors();

    $review->refresh();
    expect($review->status)->toBe(ReviewStatus::Submitted)
        ->and($review->submitted_at)->not->toBeNull();

    $notification = Notification::where('type', 'review_submitted')->sole();
    expect($notification->user_id)->toBe($ceo->id)
        ->and($notification->metadata)->toBe(['performance_review_id' => $review->id, 'employee_id' => $review->employee_id]);
    expect(AuditLog::where('action', 'hr.review_submitted')->exists())->toBeTrue();
});

test('editing is locked once submitted and once approved', function () {
    $hr = prUser('HR');
    $review = prService()->submit(prFilledReview(hr: $hr), $hr);
    $payload = [
        'qualitative' => ['attitude' => 1, 'teamwork' => 1, 'initiative' => 1, 'responsibility' => 1],
        'weights' => PerformanceReviewService::DEFAULT_WEIGHTS,
    ];

    $this->actingAs($hr)->put(route('hr.reviews.update', $review), $payload)->assertSessionHasErrors('status');
    $this->actingAs($hr)->post(route('hr.reviews.refresh', $review))->assertSessionHasErrors('status');
    $this->actingAs($hr)->post(route('hr.reviews.submit', $review))->assertSessionHasErrors('status');

    prService()->approve($review, prUser('CEO'));

    $this->actingAs($hr)->put(route('hr.reviews.update', $review), $payload)->assertSessionHasErrors('status');
    $this->actingAs($hr)->post(route('hr.reviews.submit', $review))->assertSessionHasErrors('status');
    $this->actingAs(prUser('CEO'))->post(route('hr.reviews.return', $review), ['note' => 'Ulang'])->assertSessionHasErrors('status');
    $this->actingAs(prUser('CEO'))->post(route('hr.reviews.approve', $review))->assertSessionHasErrors('status');

    expect($review->fresh()->qualitative['attitude'])->toBe(4)
        ->and($review->fresh()->status)->toBe(ReviewStatus::Approved);
});

test('the CEO approves a submitted review — employee and reviewer are notified', function () {
    $hr = prUser('HR');
    $ceo = prUser('CEO');
    [$employee, $account] = prLinkedEmployee();
    $review = prService()->submit(prFilledReview($employee, $hr), $hr);

    $this->actingAs($ceo)->post(route('hr.reviews.approve', $review))->assertSessionHasNoErrors();

    $review->refresh();
    expect($review->status)->toBe(ReviewStatus::Approved)
        ->and($review->approved_by)->toBe($ceo->id)
        ->and($review->approved_at)->not->toBeNull();

    expect(Notification::where('type', 'review_approved')->sole()->user_id)->toBe($account->id)
        ->and(Notification::where('type', 'review_approved_reviewer')->sole()->user_id)->toBe($hr->id);
    expect(AuditLog::where('action', 'hr.review_approved')->exists())->toBeTrue();
});

test('a draft cannot be approved or returned', function () {
    $ceo = prUser('CEO');
    $review = prFilledReview();

    $this->actingAs($ceo)->post(route('hr.reviews.approve', $review))->assertSessionHasErrors('status');
    $this->actingAs($ceo)->post(route('hr.reviews.return', $review), ['note' => 'Ulang'])->assertSessionHasErrors('status');

    expect($review->fresh()->status)->toBe(ReviewStatus::Draft);
});

test('the CEO returns a submitted review with a required note; resubmitting clears it', function () {
    $hr = prUser('HR');
    $ceo = prUser('CEO');
    $review = prService()->submit(prFilledReview(hr: $hr), $hr);

    $this->actingAs($ceo)->post(route('hr.reviews.return', $review), ['note' => ''])
        ->assertSessionHasErrors(['note' => 'Catatan pengembalian wajib diisi.']);

    $this->actingAs($ceo)->post(route('hr.reviews.return', $review), ['note' => 'Nilai inisiatif terlalu tinggi.'])
        ->assertSessionHasNoErrors();

    $review->refresh();
    expect($review->status)->toBe(ReviewStatus::Draft)
        ->and($review->return_note)->toBe('Nilai inisiatif terlalu tinggi.')
        ->and($review->submitted_at)->toBeNull();

    $notification = Notification::where('type', 'review_returned')->sole();
    expect($notification->user_id)->toBe($hr->id);
    expect(AuditLog::where('action', 'hr.review_returned')->exists())->toBeTrue();

    // Editable again, and resubmission clears the note.
    $this->actingAs($hr)->get(route('hr.reviews.show', $review))
        ->assertInertia(fn (Assert $page) => $page->where('review.return_note', 'Nilai inisiatif terlalu tinggi.')->where('review.status', 'DRAFT'));
    prService()->submit($review, $hr);
    expect($review->fresh()->return_note)->toBeNull();
});

// ── "Milik Saya" ────────────────────────────────────────────────────────

test('an employee acknowledges their own approved review once', function () {
    [$employee, $account] = prLinkedEmployee();
    $review = prApprovedReview($employee);

    $this->actingAs($account)->post(route('my.reviews.acknowledge', $review))->assertSessionHasNoErrors();

    $review->refresh();
    expect($review->status)->toBe(ReviewStatus::Acknowledged)
        ->and($review->acknowledged_at)->not->toBeNull();
    expect(AuditLog::where('action', 'hr.review_acknowledged')->sole()->user_id)->toBe($account->id);

    $this->actingAs($account)->post(route('my.reviews.acknowledge', $review))->assertSessionHasErrors('status');

    $this->actingAs($account)->get(route('my.reviews.pdf', $review))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('reviews that are not final or not your own are invisible in "Milik Saya"', function () {
    [$employee, $account] = prLinkedEmployee();
    [$other] = prLinkedEmployee('MARKETING');
    $hr = prUser('HR');

    $othersApproved = prApprovedReview($other);
    $draft = prService()->create($employee, 2026, 1, $hr);
    $submitted = prService()->submit(prFilledReview(prEmployee(['user_id' => null]), $hr), $hr);

    foreach ([$othersApproved, $draft] as $review) {
        $this->actingAs($account)->post(route('my.reviews.acknowledge', $review))->assertNotFound();
        $this->actingAs($account)->get(route('my.reviews.pdf', $review))->assertNotFound();
    }

    // Own but only submitted: still invisible.
    $draft = prService()->update($draft, [
        'qualitative' => ['attitude' => 4, 'teamwork' => 4, 'initiative' => 4, 'responsibility' => 4],
        'weights' => PerformanceReviewService::DEFAULT_WEIGHTS,
        'recommendation' => 'BONUS',
    ], $hr);
    $ownSubmitted = prService()->submit($draft, $hr);
    $this->actingAs($account)->post(route('my.reviews.acknowledge', $ownSubmitted))->assertNotFound();
    $this->actingAs($account)->get(route('my.reviews.pdf', $ownSubmitted))->assertNotFound();

    expect($othersApproved->fresh()->status)->toBe(ReviewStatus::Approved)
        ->and($submitted->fresh()->status)->toBe(ReviewStatus::Submitted);
});

test('field staff and users without an employee row get 403 on "Milik Saya" review routes', function (string $role) {
    $review = prApprovedReview(prEmployee());
    $user = prUser($role);

    $this->actingAs($user)->post(route('my.reviews.acknowledge', $review))->assertForbidden();
    $this->actingAs($user)->get(route('my.reviews.pdf', $review))->assertForbidden();
})->with(['FIELD_STAFF', 'DESIGNER']);

test('the service refuses acknowledging someone else\'s review', function () {
    $review = prApprovedReview(prEmployee());
    [$other, $account] = prLinkedEmployee();

    expect(fn () => prService()->acknowledge($review, $other, $account))->toThrow(ValidationException::class);
});

test('forEmployee lists newest first; finalOnly hides non-final reviews and the return note', function () {
    $hr = prUser('HR');
    $ceo = prUser('CEO');
    $employee = prEmployee(['join_date' => '2024-01-01']);

    // S2 2025: approved after once being returned.
    $this->travelTo('2026-01-15 10:00:00');
    $older = prService()->create($employee, 2025, 2, $hr);
    $older = prService()->update($older, [
        'qualitative' => ['attitude' => 4, 'teamwork' => 4, 'initiative' => 4, 'responsibility' => 4],
        'weights' => PerformanceReviewService::DEFAULT_WEIGHTS,
        'recommendation' => 'BONUS',
    ], $hr);
    prService()->return(prService()->submit($older, $hr), 'Periksa lagi.', $ceo);
    prService()->approve(prService()->submit($older, $hr), $ceo);

    // S1 2026: still a draft with a return note.
    $this->travelTo('2026-10-04 10:00:00');
    $draft = prService()->submit(prFilledReview($employee, $hr), $hr);
    prService()->return($draft, 'Lengkapi catatan.', $ceo);

    $all = prService()->forEmployee($employee);
    expect(collect($all['reviews'])->pluck('period_label')->all())->toBe(['Semester 1 2026', 'Semester 2 2025'])
        ->and($all['reviews'][0]['return_note'])->toBe('Lengkapi catatan.')
        ->and($all['previous'])->toMatchArray(['year' => 2026, 'semester' => 1, 'review_id' => $draft->id])
        ->and($all['current'])->toMatchArray(['year' => 2026, 'semester' => 2, 'review_id' => null]);

    $final = prService()->forEmployee($employee, finalOnly: true);
    expect($final['reviews'])->toHaveCount(1)
        ->and($final['reviews'][0]['period_label'])->toBe('Semester 2 2025')
        ->and($final['reviews'][0])->not->toHaveKey('return_note');
});

test('the employee profile passes the reviews tab data', function () {
    $review = prFilledReview();

    $this->actingAs(prUser('HR'))->get(route('hr.employees.show', $review->employee_id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('reviews.reviews', 1)->where('reviews.reviews.0.id', $review->id));
});

// ── Index + dashboard ───────────────────────────────────────────────────

test('index filters by semester and status and counts per status', function () {
    $hr = prUser('HR');
    $submitted = prService()->submit(prFilledReview(hr: $hr), $hr);
    prFilledReview(hr: $hr);
    prService()->create(prEmployee(), 2026, 2, $hr);

    $this->actingAs($hr)->get(route('hr.reviews.index', ['year' => 2026, 'semester' => 1, 'status' => 'SUBMITTED']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('reviews', 1)
            ->where('reviews.0.id', $submitted->id)
            ->where('counts.DRAFT', 1)
            ->where('counts.SUBMITTED', 1)
            ->where('canManage', true)
            ->has('existing', 3));

    $this->actingAs($hr)->get(route('hr.reviews.index', ['year' => 2026, 'semester' => 'all']))
        ->assertInertia(fn (Assert $page) => $page->has('reviews', 3)->where('filters.semester', null));
});

test('dashboard summary counts statuses, lists reviews awaiting the CEO and the grade spread', function () {
    $hr = prUser('HR');
    prService()->submit(prFilledReview(hr: $hr), $hr);
    prApprovedReview(prEmployee()); // 4/4/4/4, no KPI → 80 + 100 → (25×80+15×100)/40 = 87.5 → B
    prService()->create(prEmployee(), 2026, 2, $hr);

    $summary = prService()->dashboardSummary();

    expect($summary['previous']['counts'])->toBe(['DRAFT' => 0, 'SUBMITTED' => 1, 'APPROVED' => 1, 'ACKNOWLEDGED' => 0])
        ->and($summary['current']['counts']['DRAFT'])->toBe(1)
        ->and($summary['awaiting_count'])->toBe(1)
        ->and($summary['awaiting'])->toHaveCount(1)
        ->and($summary['grade_distribution'])->toBe(['A' => 0, 'B' => 1, 'C' => 0, 'D' => 0, 'E' => 0]);
});

// ── Demo seeder ─────────────────────────────────────────────────────────

test('the demo seeder writes the last semester reviews in every state and is idempotent', function () {
    prUser('HR');
    prUser('CEO');
    [$boy] = prLinkedEmployee('MARKETING');
    $boy->update(['name' => 'Boy']);
    foreach (['Icha', 'Ami', 'Ibnu', 'Ilham', 'Hesti', 'Satria', 'Rojab', 'Yola'] as $name) {
        prEmployee(['name' => $name]);
    }

    $this->seed(PerformanceReviewDemoSeeder::class);
    $this->seed(PerformanceReviewDemoSeeder::class);

    $byName = PerformanceReview::with('employee')->get()->keyBy(fn ($review) => $review->employee->name);

    expect($byName)->toHaveCount(8)
        ->and($byName['Boy']->status)->toBe(ReviewStatus::Acknowledged)
        ->and($byName['Icha']->status)->toBe(ReviewStatus::Approved)
        ->and($byName['Icha']->recommendation->value)->toBe('NAIK_GAJI')
        ->and($byName['Ami']->status)->toBe(ReviewStatus::Submitted)
        ->and($byName['Ibnu']->status)->toBe(ReviewStatus::Draft)
        ->and($byName['Ibnu']->return_note)->not->toBeNull()
        ->and($byName['Hesti']->status)->toBe(ReviewStatus::Draft)
        ->and($byName['Rojab']->status)->toBe(ReviewStatus::Approved) // no account → cannot acknowledge
        ->and($byName->has('Yola'))->toBeFalse()
        ->and($byName['Boy']->year)->toBe(2026)
        ->and($byName['Boy']->semester)->toBe(1);
});
