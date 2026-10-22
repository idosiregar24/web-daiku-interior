<?php

use App\Enums\DailyActivityReviewStatus;
use App\Models\DailyActivity;
use App\Models\DailyActivityReview;
use App\Models\Employee;
use App\Models\KpiIndicator;
use App\Models\KpiPeriod;
use App\Models\KpiScore;
use App\Models\KpiTemplate;
use App\Models\Position;
use App\Models\User;
use App\Services\Kpi\KpiMetricRegistry;
use App\Services\KpiService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 29 Sub 14 — KPI from "Aktivitas Harian" (decision I3): two AUTO
 * metrics in KpiMetricRegistry, scored by the unchanged KPI formula, with
 * the day breakdown snapshotted in `kpi_scores.detail`.
 *
 * October 2026: the 1st is a Thursday, Sundays are 4/11/18/25. An employee
 * who joined on Friday 9 October owes 20 working days (9–31 Oct, Mon–Sat).
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    // October is fully past.
    Carbon::setTestNow(Carbon::parse('2026-11-05 10:00', 'Asia/Jakarta'));
    $this->hr = kpiActivityUser('HR');
    $this->registry = app(KpiMetricRegistry::class);
    $this->october = [Carbon::parse('2026-10-01'), Carbon::parse('2026-11-01')];
});

afterEach(fn () => Carbon::setTestNow());

function kpiActivityUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** A position whose template holds the two Aktivitas Harian metrics (fill 30 % → target 95, ok 70 % → target 90). */
function kpiActivityPosition(): Position
{
    $template = KpiTemplate::factory()->create();
    KpiIndicator::factory()->for($template, 'template')->auto('daily_activity_fill_rate')
        ->create(['name' => 'Disiplin aktivitas harian', 'target' => 95, 'weight' => 30, 'sort_order' => 0]);
    KpiIndicator::factory()->for($template, 'template')->auto('daily_activity_ok_rate')
        ->create(['name' => 'Kualitas aktivitas harian', 'target' => 90, 'weight' => 70, 'sort_order' => 1]);

    return $template->position;
}

function kpiActivityEmployee(?Position $position = null, string $joinDate = '2026-10-09', string $role = 'DESIGNER'): Employee
{
    return Employee::factory()->create([
        'user_id' => kpiActivityUser($role)->id,
        'position_id' => ($position ?? kpiActivityPosition())->id,
        'join_date' => $joinDate,
    ]);
}

function kpiActivityReview(Employee $employee, string $date, DailyActivityReviewStatus $status, User $hr): void
{
    DailyActivityReview::create([
        'user_id' => $employee->user_id,
        'date' => $date,
        'status' => $status,
        'note' => $status === DailyActivityReviewStatus::PerluKlarifikasi ? 'Mohon dijelaskan.' : null,
        'reviewed_by' => $hr->id,
    ]);
}

/**
 * The constructed month: 18 of 20 working days filled (9 and 20 Oct
 * empty), a Sunday entry (holiday, unchecked) and one before the join date;
 * of the 18 filled days 15 end SESUAI and 3 PERLU_KLARIFIKASI — two of them
 * checked twice, the newest check winning either way. Empty days: 9 Oct
 * TIDAK_MENGISI, 20 Oct PERLU_KLARIFIKASI.
 */
function kpiActivityMonth(Employee $employee, User $hr): void
{
    $workDays = collect(range(9, 31))
        ->map(fn (int $day) => Carbon::parse(sprintf('2026-10-%02d', $day)))
        ->reject(fn (Carbon $day) => $day->isSunday())
        ->map(fn (Carbon $day) => $day->toDateString())
        ->values();

    expect($workDays)->toHaveCount(20);

    $filled = $workDays->reject(fn (string $date) => in_array($date, ['2026-10-09', '2026-10-20'], true))->values();

    foreach ($filled as $i => $date) {
        DailyActivity::create(['user_id' => $employee->user_id, 'date' => $date, 'description' => 'Menyusun gambar']);

        if ($i === 0) {
            // Two entries on one day still count as one day.
            DailyActivity::create(['user_id' => $employee->user_id, 'date' => $date, 'description' => 'Rapat klien']);
        }
    }

    DailyActivity::create(['user_id' => $employee->user_id, 'date' => '2026-10-18', 'description' => 'Survey hari Minggu']);
    DailyActivity::create(['user_id' => $employee->user_id, 'date' => '2026-10-05', 'description' => 'Sebelum bergabung']);

    foreach ($filled as $i => $date) {
        if ($i === 0) {
            // Klarifikasi first, then Sesuai — counts as Sesuai.
            kpiActivityReview($employee, $date, DailyActivityReviewStatus::PerluKlarifikasi, $hr);
        }

        kpiActivityReview($employee, $date, $i < 16 ? DailyActivityReviewStatus::Sesuai : DailyActivityReviewStatus::PerluKlarifikasi, $hr);
    }

    // Sesuai, then klarifikasi — the newest wins: 15 Sesuai, 3 klarifikasi.
    kpiActivityReview($employee, $filled[15], DailyActivityReviewStatus::PerluKlarifikasi, $hr);

    kpiActivityReview($employee, '2026-10-09', DailyActivityReviewStatus::TidakMengisi, $hr);
    kpiActivityReview($employee, '2026-10-20', DailyActivityReviewStatus::PerluKlarifikasi, $hr);
}

function kpiActivityScores(KpiPeriod $period, Employee $employee)
{
    return KpiScore::query()->where('kpi_period_id', $period->id)->where('employee_id', $employee->id)->get()->keyBy('metric_key');
}

// ── Metric values ───────────────────────────────────────────────────────

test('20 working days, 18 filled → 90 %; 15 Sesuai of 18 checked → 83,33 %', function () {
    $employee = kpiActivityEmployee();
    kpiActivityMonth($employee, $this->hr);
    $user = $employee->user;

    expect($this->registry->compute('daily_activity_fill_rate', $user, ...$this->october))->toBe(90.0)
        ->and($this->registry->compute('daily_activity_ok_rate', $user, ...$this->october))->toBe(83.33);

    $detail = $this->registry->detail('daily_activity_fill_rate', $user, ...$this->october);

    expect($detail)->toMatchArray([
        'kind' => 'daily_activity',
        'from' => '2026-10-09',
        'to' => '2026-10-31',
        'fromJoinDate' => true,
        'workDays' => 20,
        'filledWorkDays' => 18,
        'filledHolidays' => 1,
        'reviewed' => 18,
        'sesuai' => 15,
        'klarifikasi' => 4, // 3 filled days + the empty 20 Oct
        'tidakMengisi' => 1,
        'unreviewed' => 1, // the Sunday entry
    ])
        ->and($detail['days'])->toHaveCount(21) // 20 working days + the filled Sunday; 5 Oct (before joining) is not counted
        ->and(collect($detail['days'])->firstWhere('date', '2026-10-18'))->toBe(['date' => '2026-10-18', 'workDay' => false, 'entries' => 1, 'review' => null])
        ->and(collect($detail['days'])->firstWhere('date', '2026-10-10'))->toMatchArray(['entries' => 2, 'review' => 'SESUAI'])
        ->and(collect($detail['days'])->firstWhere('date', '2026-10-09'))->toMatchArray(['entries' => 0, 'review' => 'TIDAK_MENGISI'])
        ->and(collect($detail['days'])->pluck('date'))->not->toContain('2026-10-05')
        ->and($this->registry->detail('design_on_schedule_rate', $user, ...$this->october))->toBeNull();
});

test('nothing to measure is no data; an inactive account is not measured', function () {
    $employee = kpiActivityEmployee();
    $user = $employee->user;

    // Working days but no entry: a real 0 % fill; nothing checked: no ok rate.
    expect($this->registry->compute('daily_activity_fill_rate', $user, ...$this->october))->toBe(0.0)
        ->and($this->registry->compute('daily_activity_ok_rate', $user, ...$this->october))->toBeNull();

    // Joined after the month → no working day owed.
    $late = kpiActivityEmployee(joinDate: '2026-11-02');
    expect($this->registry->compute('daily_activity_fill_rate', $late->user, ...$this->october))->toBeNull();

    $user->update(['is_active' => false]);
    expect($this->registry->compute('daily_activity_fill_rate', $user->fresh(), ...$this->october))->toBeNull()
        ->and($this->registry->detail('daily_activity_fill_rate', $user->fresh(), ...$this->october))->toBeNull();
});

test('the running month counts up to yesterday — today can still be written', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-21 10:00', 'Asia/Jakarta'));
    $employee = kpiActivityEmployee(joinDate: '2025-01-01');
    DailyActivity::create(['user_id' => $employee->user_id, 'date' => '2026-10-01', 'description' => 'A']);
    DailyActivity::create(['user_id' => $employee->user_id, 'date' => '2026-10-21', 'description' => 'Hari ini']);

    $detail = $this->registry->detail('daily_activity_fill_rate', $employee->user, ...$this->october);

    // 1–20 Oct minus Sundays 4, 11, 18 = 17 working days.
    expect($detail)->toMatchArray(['from' => '2026-10-01', 'to' => '2026-10-20', 'fromJoinDate' => false, 'workDays' => 17, 'filledWorkDays' => 1])
        ->and($this->registry->compute('daily_activity_fill_rate', $employee->user, ...$this->october))->toBe(5.88);
});

test('priming loads every user of the month at once — computing then runs no query', function () {
    $position = kpiActivityPosition();
    $employees = collect(range(1, 3))->map(fn () => kpiActivityEmployee($position));
    kpiActivityMonth($employees[0], $this->hr);

    $this->registry->prime($employees->pluck('user'), ['daily_activity_fill_rate'], ...$this->october);

    DB::enableQueryLog();
    $values = $employees->map(fn (Employee $employee) => $this->registry->compute('daily_activity_fill_rate', $employee->user, ...$this->october));
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(0)
        ->and($values->all())->toBe([90.0, 0.0, 0.0]);
});

test('the metrics are offered to every role but the Tukang', function () {
    $options = collect($this->registry->options())->keyBy('key');

    expect($options['daily_activity_fill_rate'])->toMatchArray(['unit' => '%', 'direction' => 'HIGHER_BETTER'])
        ->and($options['daily_activity_ok_rate']['roles'])->toContain('HR', 'PM', 'MARKETING', 'ADMIN_PROYEK')
        ->and($options['daily_activity_ok_rate']['roles'])->not->toContain('FIELD_STAFF');
});

// ── KPI computation (unchanged formula) ─────────────────────────────────

test('compute scores the two metrics with the usual formula and stores the breakdown', function () {
    $employee = kpiActivityEmployee();
    kpiActivityMonth($employee, $this->hr);
    $period = app(KpiService::class)->openPeriod('2026-10', $this->hr);

    app(KpiService::class)->compute($period, $this->hr);
    $scores = kpiActivityScores($period, $employee);

    // 90 ÷ 95 × 100 = 94,74 → × 30 % = 28,42; 83,33 ÷ 90 × 100 = 92,59 → × 70 % = 64,81.
    expect((float) $scores['daily_activity_fill_rate']->actual)->toBe(90.0)
        ->and((float) $scores['daily_activity_fill_rate']->score)->toBe(94.74)
        ->and((float) $scores['daily_activity_fill_rate']->weighted_score)->toBe(28.42)
        ->and((float) $scores['daily_activity_ok_rate']->actual)->toBe(83.33)
        ->and((float) $scores['daily_activity_ok_rate']->score)->toBe(92.59)
        ->and((float) $scores['daily_activity_ok_rate']->weighted_score)->toBe(64.81)
        ->and($scores['daily_activity_ok_rate']->detail)->toMatchArray(['workDays' => 20, 'sesuai' => 15, 'reviewed' => 18]);

    $this->actingAs($this->hr)->get(route('hr.kpi.index', ['period' => '2026-10']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('HR/Kpi/Index')
            ->where('board.0.total', 93.23)
            ->where('board.0.rows.0.metricKey', 'daily_activity_fill_rate')
            ->where('board.0.rows.0.detail.filledWorkDays', 18)
            ->has('board.0.rows.0.detail.days', 21));
});

test('a field-staff account never gets the metric — the employee is not in SDM', function () {
    $position = kpiActivityPosition();
    $tukang = kpiActivityEmployee($position, role: 'FIELD_STAFF');
    $period = app(KpiService::class)->openPeriod('2026-10', $this->hr);

    app(KpiService::class)->compute($period, $this->hr);

    expect(kpiActivityScores($period, $tukang))->toBeEmpty();
});

test('a closed month keeps its value and day list when the checks change later', function () {
    $employee = kpiActivityEmployee();
    kpiActivityMonth($employee, $this->hr);
    $service = app(KpiService::class);
    $period = $service->openPeriod('2026-10', $this->hr);
    $service->compute($period, $this->hr);
    $service->close($period, $this->hr);

    // HR re-checks the 3 klarifikasi days as Sesuai after the month closed.
    foreach (['2026-10-29', '2026-10-30', '2026-10-31'] as $date) {
        kpiActivityReview($employee, $date, DailyActivityReviewStatus::Sesuai, $this->hr);
    }

    expect($this->registry->compute('daily_activity_ok_rate', $employee->user, ...$this->october))->toBe(100.0);
    expect(fn () => $service->compute($period->fresh(), $this->hr))->toThrow(ValidationException::class);

    $scores = kpiActivityScores($period, $employee);
    expect((float) $scores['daily_activity_ok_rate']->actual)->toBe(83.33)
        ->and($scores['daily_activity_ok_rate']->detail['sesuai'])->toBe(15)
        ->and(fn () => $scores['daily_activity_ok_rate']->update(['detail' => null]))->toThrow(LogicException::class);

    // The employee's own page shows the frozen month with its breakdown.
    $this->actingAs($employee->user)->get(route('my.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('HR/My/Index')
            ->where('kpi.periods.0.period', '2026-10')
            ->where('kpi.periods.0.rows.1.actual', 83.33)
            ->where('kpi.periods.0.rows.1.detail.sesuai', 15));
});

test('a manual indicator carries no breakdown', function () {
    $template = KpiTemplate::factory()->create();
    KpiIndicator::factory()->for($template, 'template')->create(['weight' => 100]);
    $employee = Employee::factory()->create(['user_id' => kpiActivityUser('MARKETING')->id, 'position_id' => $template->position_id]);
    $period = app(KpiService::class)->openPeriod('2026-10', $this->hr);

    app(KpiService::class)->compute($period, $this->hr);

    expect(KpiScore::where('employee_id', $employee->id)->sole()->detail)->toBeNull();
});
