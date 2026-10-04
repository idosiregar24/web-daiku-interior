<?php

use App\Enums\DesignStatus;
use App\Enums\KpiDirection;
use App\Enums\KpiPeriodStatus;
use App\Jobs\OpenKpiPeriodJob;
use App\Models\AuditLog;
use App\Models\Design;
use App\Models\Employee;
use App\Models\KpiIndicator;
use App\Models\KpiPeriod;
use App\Models\KpiScore;
use App\Models\KpiTemplate;
use App\Models\Lead;
use App\Models\Milestone;
use App\Models\PipelineLog;
use App\Models\Position;
use App\Models\Project;
use App\Models\QaForm;
use App\Models\Quotation;
use App\Models\QuotationApproval;
use App\Models\Termin;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Kpi\KpiMetricRegistry;
use App\Services\KpiService;
use Database\Seeders\Demo\KpiDemoSeeder;
use Database\Seeders\OrganizationStructureSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

function kpiUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * A position whose template holds the given indicators (MANUAL by default).
 *
 * @param  list<array<string, mixed>>  $indicators
 */
function kpiPosition(array $indicators): Position
{
    $template = KpiTemplate::factory()->create();

    foreach ($indicators as $i => $indicator) {
        KpiIndicator::factory()->for($template, 'template')->create([...$indicator, 'sort_order' => $i]);
    }

    return $template->position;
}

function kpiPreviousMonth(): Carbon
{
    return now()->subMonthNoOverflow()->startOfMonth();
}

function kpiOpenPreviousMonth(): KpiPeriod
{
    return app(KpiService::class)->openPeriod(kpiPreviousMonth()->format('Y-m'));
}

function kpiTemplatePayload(array $overrides = []): array
{
    return ['indicators' => $overrides ?: [
        ['name' => 'Kualitas gambar', 'source' => 'MANUAL', 'metric_key' => null, 'target' => 100, 'weight' => 40, 'direction' => 'HIGHER_BETTER'],
        ['name' => 'Desain tepat jadwal', 'source' => 'AUTO', 'metric_key' => 'design_on_schedule_rate', 'target' => 90, 'weight' => 60, 'direction' => 'HIGHER_BETTER'],
    ]];
}

// ── RBAC (§4: CEO reads, HR manages) ─────────────────────────────────────

test('CEO and HR can open the KPI pages', function (string $role) {
    $this->actingAs(kpiUser($role))->get(route('hr.kpi.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('HR/Kpi/Index')->where('canManage', $role !== 'CEO'));

    $this->actingAs(kpiUser($role))->get(route('hr.kpi.templates.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('HR/Kpi/Templates')->has('metrics'));
})->with(['CEO', 'HR', 'SUPERADMIN']);

test('the CEO reads but cannot write KPI data', function () {
    $ceo = kpiUser('CEO');
    $position = kpiPosition([['weight' => 100]]);
    $period = kpiOpenPreviousMonth();
    $score = KpiScore::factory()->for($period, 'period')->create();

    $this->actingAs($ceo)->put(route('hr.kpi.templates.save', $position), kpiTemplatePayload())->assertForbidden();
    $this->actingAs($ceo)->patch(route('hr.kpi.templates.toggle', $position))->assertForbidden();
    $this->actingAs($ceo)->post(route('hr.kpi.periods.store'), ['period' => now()->format('Y-m')])->assertForbidden();
    $this->actingAs($ceo)->post(route('hr.kpi.periods.compute', $period))->assertForbidden();
    $this->actingAs($ceo)->post(route('hr.kpi.periods.close', $period))->assertForbidden();
    $this->actingAs($ceo)->patch(route('hr.kpi.scores.update', $score), ['actual' => 1])->assertForbidden();
});

test('other roles are refused the KPI module', function (string $role) {
    $user = kpiUser($role);
    $period = kpiOpenPreviousMonth();

    $this->actingAs($user)->get(route('hr.kpi.index'))->assertForbidden();
    $this->actingAs($user)->get(route('hr.kpi.templates.index'))->assertForbidden();
    $this->actingAs($user)->post(route('hr.kpi.periods.compute', $period))->assertForbidden();
})->with(['FINANCE', 'PM', 'DESIGNER', 'FIELD_STAFF']);

test('no KPI route can delete anything', function () {
    $methods = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'hr/kpi'))
        ->flatMap(fn ($route) => $route->methods());

    expect($methods)->not->toBeEmpty()
        ->and($methods->contains('DELETE'))->toBeFalse();
});

// ── Templates ────────────────────────────────────────────────────────────

test('HR saves a position template, which is audited', function () {
    $hr = kpiUser('HR');
    $position = Position::factory()->create();

    $this->actingAs($hr)->put(route('hr.kpi.templates.save', $position), kpiTemplatePayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $template = KpiTemplate::where('position_id', $position->id)->sole();
    expect($template->indicators)->toHaveCount(2)
        ->and($template->indicators[1]->metric_key)->toBe('design_on_schedule_rate')
        ->and($template->indicators[0]->metric_key)->toBeNull();

    $audit = AuditLog::where('action', 'hr.kpi_template_saved')->sole();
    expect($audit->old_values)->toBeNull()
        ->and($audit->new_values['indicators'])->toHaveCount(2);

    // Editing keeps indicator ids sent back and drops the rest.
    $keep = $template->indicators[0];
    $this->actingAs($hr)->put(route('hr.kpi.templates.save', $position), ['indicators' => [
        ['id' => $keep->id, 'name' => 'Kualitas gambar', 'source' => 'MANUAL', 'target' => 100, 'weight' => 100, 'direction' => 'HIGHER_BETTER'],
    ]])->assertSessionHasNoErrors();

    expect($template->fresh()->indicators->pluck('id')->all())->toBe([$keep->id])
        ->and(AuditLog::where('action', 'hr.kpi_template_saved')->count())->toBe(2);

    $this->actingAs($hr)->patch(route('hr.kpi.templates.toggle', $position))->assertSessionHasNoErrors();
    expect($template->fresh()->is_active)->toBeFalse()
        ->and(AuditLog::where('action', 'hr.kpi_template_toggled')->count())->toBe(1);
});

test('template input is validated', function () {
    $hr = kpiUser('HR');
    $position = Position::factory()->create();
    $row = fn (array $overrides = []) => ['name' => 'X', 'source' => 'MANUAL', 'metric_key' => null, 'target' => 10, 'weight' => 50, 'direction' => 'HIGHER_BETTER', ...$overrides];

    $this->actingAs($hr)->put(route('hr.kpi.templates.save', $position), ['indicators' => [$row(), $row(['weight' => 40])]])
        ->assertSessionHasErrors(['indicators' => 'Total bobot harus tepat 100% (sekarang 90%).']);

    $this->actingAs($hr)->put(route('hr.kpi.templates.save', $position), ['indicators' => [$row(['weight' => 100, 'source' => 'AUTO', 'metric_key' => 'tidak_ada'])]])
        ->assertSessionHasErrors(['indicators.0.metric_key' => 'Metrik otomatis tidak dikenal sistem.']);

    $this->actingAs($hr)->put(route('hr.kpi.templates.save', $position), ['indicators' => [$row(['weight' => 100, 'source' => 'AUTO'])]])
        ->assertSessionHasErrors(['indicators.0.metric_key' => 'Indikator otomatis wajib memilih metrik.']);

    $this->actingAs($hr)->put(route('hr.kpi.templates.save', $position), ['indicators' => [$row(['weight' => 100, 'metric_key' => 'lead_new_count'])]])
        ->assertSessionHasErrors(['indicators.0.metric_key' => 'Indikator manual tidak memakai metrik otomatis.']);

    $this->actingAs($hr)->put(route('hr.kpi.templates.save', $position), ['indicators' => [$row(['weight' => 100, 'target' => 0])]])
        ->assertSessionHasErrors(['indicators.0.target' => 'Target harus lebih dari 0.']);

    $this->actingAs($hr)->put(route('hr.kpi.templates.save', $position), ['indicators' => []])
        ->assertSessionHasErrors('indicators');

    // The service enforces the same rules for any caller.
    expect(fn () => app(KpiService::class)->saveTemplate($position, [$row(['weight' => 99])], $hr))->toThrow(ValidationException::class);
    expect(fn () => app(KpiService::class)->saveTemplate($position, [$row(['weight' => 100, 'source' => 'AUTO', 'metric_key' => 'nope'])], $hr))->toThrow(ValidationException::class);

    expect(KpiTemplate::count())->toBe(0);
});

// ── Periods ──────────────────────────────────────────────────────────────

test('opening a period is idempotent, audited and refuses future months', function () {
    $hr = kpiUser('HR');

    $this->actingAs($hr)->post(route('hr.kpi.periods.store'), ['period' => now()->format('Y-m')])->assertSessionHasNoErrors();
    $this->actingAs($hr)->post(route('hr.kpi.periods.store'), ['period' => now()->format('Y-m')])->assertSessionHasNoErrors();

    expect(KpiPeriod::count())->toBe(1)
        ->and(KpiPeriod::sole()->status)->toBe(KpiPeriodStatus::Open)
        ->and(AuditLog::where('action', 'hr.kpi_period_opened')->count())->toBe(1);

    $this->actingAs($hr)->post(route('hr.kpi.periods.store'), ['period' => now()->addMonthNoOverflow()->format('Y-m')])
        ->assertSessionHasErrors(['period' => 'Periode KPI bulan yang akan datang belum bisa dibuka.']);
    $this->actingAs($hr)->post(route('hr.kpi.periods.store'), ['period' => '2026-13'])
        ->assertSessionHasErrors(['period' => 'Format periode harus YYYY-MM.']);

    expect(KpiPeriod::count())->toBe(1);
});

test('the scheduled job opens the current month once', function () {
    OpenKpiPeriodJob::dispatchSync();
    OpenKpiPeriodJob::dispatchSync();

    expect(KpiPeriod::where('period', now()->format('Y-m'))->count())->toBe(1);
});

test('compute snapshots the template per eligible employee and is idempotent', function () {
    $hr = kpiUser('HR');
    $position = kpiPosition([
        ['name' => 'Koordinasi tim', 'target' => 100, 'weight' => 60],
        ['name' => 'Kerapian', 'target' => 10, 'weight' => 40, 'direction' => 'LOWER_BETTER'],
    ]);
    $employee = Employee::factory()->create(['position_id' => $position->id]);
    Employee::factory()->create(['position_id' => $position->id, 'is_active' => false]);
    Employee::factory()->create(); // position without template
    $period = kpiOpenPreviousMonth();

    $this->actingAs($hr)->post(route('hr.kpi.periods.compute', $period))->assertSessionHasNoErrors();

    $rows = KpiScore::where('kpi_period_id', $period->id)->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('employee_id')->unique()->all())->toBe([$employee->id])
        ->and($rows[0]->indicator_name)->toBe('Koordinasi tim')
        ->and((float) $rows[0]->weight)->toBe(60.0)
        ->and($rows[1]->direction)->toBe(KpiDirection::LowerBetter)
        ->and($rows[0]->actual)->toBeNull()
        ->and($rows[0]->score)->toBeNull();

    $service = app(KpiService::class);
    $service->updateManualScore($rows[0], 80, $hr);

    $this->actingAs($hr)->post(route('hr.kpi.periods.compute', $period))->assertSessionHasNoErrors();

    $again = KpiScore::where('kpi_period_id', $period->id)->orderBy('id')->get();
    expect($again->pluck('id')->all())->toBe($rows->pluck('id')->all())
        ->and((float) $again[0]->actual)->toBe(80.0) // manual value kept
        ->and((float) $again[0]->weighted_score)->toBe(80.0) // only scored row → its weight takes 100%
        ->and(AuditLog::where('action', 'hr.kpi_period_computed')->count())->toBe(2);
});

test('template edits in an OPEN month replace the rows on the next compute', function () {
    $hr = kpiUser('HR');
    $position = kpiPosition([['name' => 'A', 'weight' => 50], ['name' => 'B', 'weight' => 50]]);
    Employee::factory()->create(['position_id' => $position->id]);
    $period = kpiOpenPreviousMonth();
    $service = app(KpiService::class);
    $service->compute($period, $hr);

    $kept = $position->kpiTemplate->indicators->first();
    $service->saveTemplate($position, [
        ['id' => $kept->id, 'name' => 'A baru', 'source' => 'MANUAL', 'target' => 50, 'weight' => 70, 'direction' => 'HIGHER_BETTER'],
        ['name' => 'C', 'source' => 'MANUAL', 'target' => 5, 'weight' => 30, 'direction' => 'HIGHER_BETTER'],
    ], $hr);
    $service->compute($period, $hr);

    expect(KpiScore::where('kpi_period_id', $period->id)->orderBy('indicator_name')->pluck('indicator_name')->all())->toBe(['A baru', 'C']);
});

test('field-staff-linked employees are never scored', function () {
    $position = kpiPosition([['weight' => 100]]);
    Employee::factory()->create(['position_id' => $position->id, 'user_id' => kpiUser('FIELD_STAFF')->id]);
    $period = kpiOpenPreviousMonth();

    $summary = app(KpiService::class)->compute($period, kpiUser('HR'));

    expect($summary['employees'])->toBe(0)
        ->and(KpiScore::count())->toBe(0);
});

// ── AUTO metrics ─────────────────────────────────────────────────────────

test('designer AUTO metrics come from the designs they are PIC of', function () {
    $designer = kpiUser('DESIGNER');
    $position = kpiPosition([
        ['name' => 'Tepat jadwal', 'source' => 'AUTO', 'metric_key' => 'design_on_schedule_rate', 'target' => 100, 'weight' => 40],
        ['name' => 'Delay', 'source' => 'AUTO', 'metric_key' => 'design_avg_delay_days', 'target' => 1, 'weight' => 30, 'direction' => 'LOWER_BETTER'],
        ['name' => 'ACC klien', 'source' => 'AUTO', 'metric_key' => 'design_client_acc_count', 'target' => 2, 'weight' => 30],
    ]);
    $employee = Employee::factory()->create(['position_id' => $position->id, 'user_id' => $designer->id]);
    $month = kpiPreviousMonth();

    Design::factory()->create(['pic_id' => $designer->id, 'status' => DesignStatus::DoneProduksi->value, 'deadline' => $month->copy()->addDays(4), 'delay_hari' => 0, 'client_acc' => true, 'acc_date' => $month->copy()->addDays(2)]);
    Design::factory()->create(['pic_id' => $designer->id, 'status' => DesignStatus::Produksi->value, 'deadline' => $month->copy()->addDays(9), 'delay_hari' => 4]);
    // Outside the month / someone else's — ignored.
    Design::factory()->create(['pic_id' => $designer->id, 'status' => DesignStatus::Desain->value, 'deadline' => $month->copy()->subDays(3), 'delay_hari' => 9, 'client_acc' => true, 'acc_date' => $month->copy()->subDays(3)]);
    Design::factory()->create(['pic_id' => kpiUser('DESIGNER')->id, 'deadline' => $month->copy()->addDays(5), 'delay_hari' => 10]);

    $period = kpiOpenPreviousMonth();
    app(KpiService::class)->compute($period, kpiUser('HR'));

    $rows = KpiScore::where('employee_id', $employee->id)->orderBy('id')->get();
    expect((float) $rows[0]->actual)->toBe(50.0)   // 1 of 2 designs on schedule
        ->and((float) $rows[0]->score)->toBe(50.0)
        ->and((float) $rows[1]->actual)->toBe(2.0) // (0 + 4) / 2 days
        ->and((float) $rows[1]->score)->toBe(50.0) // lower is better: 1 / 2 × 100
        ->and((float) $rows[2]->actual)->toBe(1.0)
        ->and((float) $rows[2]->score)->toBe(50.0)
        ->and((float) $rows->sum('weighted_score'))->toBe(50.0);
});

test('estimator AUTO metrics measure quotations first sent to the client in the month', function () {
    $estimator = kpiUser('ESTIMATOR');
    $pm = kpiUser('PM');
    $position = kpiPosition([
        ['name' => 'Turnaround', 'source' => 'AUTO', 'metric_key' => 'quotation_turnaround_days', 'target' => 3, 'weight' => 50, 'direction' => 'LOWER_BETTER'],
        ['name' => 'Terkirim', 'source' => 'AUTO', 'metric_key' => 'quotation_sent_count', 'target' => 4, 'weight' => 50],
    ]);
    $employee = Employee::factory()->create(['position_id' => $position->id, 'user_id' => $estimator->id]);
    $month = kpiPreviousMonth();

    $approve = function (Quotation $quotation, Carbon $at, string $status = 'APPROVED') use ($pm) {
        $this->travelTo($at);
        QuotationApproval::create(['quotation_id' => $quotation->id, 'version' => 1, 'approver_id' => $pm->id, 'approver_role' => 'PM', 'status' => $status]);
        $this->travelBack();
    };

    $this->travelTo($month->copy()->addDays(1)->setTime(9, 0));
    $q1 = Quotation::factory()->create(['created_by' => $estimator->id]);
    $q2 = Quotation::factory()->create(['created_by' => $estimator->id]);
    $other = Quotation::factory()->create();
    $this->travelBack();

    $approve($q1, $month->copy()->addDays(3)->setTime(9, 0));          // 2 days
    $approve($q2, $month->copy()->addDays(5)->setTime(9, 0), 'REJECTED');
    $approve($q2, $month->copy()->addDays(7)->setTime(9, 0));          // 6 days
    $approve($q2, $month->copy()->addDays(8)->setTime(9, 0));          // later approval — first one counts
    $approve($other, $month->copy()->addDays(3)->setTime(9, 0));       // not theirs

    $period = kpiOpenPreviousMonth();
    app(KpiService::class)->compute($period, kpiUser('HR'));

    $rows = KpiScore::where('employee_id', $employee->id)->orderBy('id')->get();
    expect((float) $rows[0]->actual)->toBe(4.0)  // (2 + 6) / 2 days
        ->and((float) $rows[0]->score)->toBe(75.0)
        ->and((float) $rows[1]->actual)->toBe(2.0)
        ->and((float) $rows[1]->score)->toBe(50.0);
});

test('an employee without an account gets no AUTO value and its weight is redistributed', function () {
    $hr = kpiUser('HR');
    $position = kpiPosition([
        ['name' => 'Disiplin', 'target' => 100, 'weight' => 50],
        ['name' => 'Kerapian', 'target' => 100, 'weight' => 30],
        ['name' => 'Lead baru', 'source' => 'AUTO', 'metric_key' => 'lead_new_count', 'target' => 10, 'weight' => 20],
    ]);
    $employee = Employee::factory()->create(['position_id' => $position->id, 'user_id' => null]);
    $period = kpiOpenPreviousMonth();
    $service = app(KpiService::class);
    $service->compute($period, $hr);

    $rows = KpiScore::where('employee_id', $employee->id)->orderBy('id')->get();
    expect($rows[2]->actual)->toBeNull();

    $service->updateManualScore($rows[0], 100, $hr);
    $service->updateManualScore($rows[1], 60, $hr);

    $rows = $rows->map->fresh();
    // Scored weight = 80: 100 × 50/80 = 62.5, 60 × 30/80 = 22.5.
    expect((float) $rows[0]->weighted_score)->toBe(62.5)
        ->and((float) $rows[1]->weighted_score)->toBe(22.5)
        ->and($rows[2]->score)->toBeNull()
        ->and($rows[2]->weighted_score)->toBeNull()
        ->and($service->forEmployee($employee)['periods'][0]['total'])->toBe(85.0);
});

test('scores follow direction and are clamped to 0–120', function () {
    $service = app(KpiService::class);

    expect($service->scoreFor(80, 100, KpiDirection::HigherBetter))->toBe(80.0)
        ->and($service->scoreFor(300, 100, KpiDirection::HigherBetter))->toBe(120.0)
        ->and($service->scoreFor(0, 100, KpiDirection::HigherBetter))->toBe(0.0)
        ->and($service->scoreFor(8, 4, KpiDirection::LowerBetter))->toBe(50.0)
        ->and($service->scoreFor(2, 4, KpiDirection::LowerBetter))->toBe(120.0) // 200 clamped
        ->and($service->scoreFor(0, 4, KpiDirection::LowerBetter))->toBe(120.0)
        ->and($service->scoreFor(null, 4, KpiDirection::LowerBetter))->toBeNull();
});

// ── Manual input & closing ───────────────────────────────────────────────

test('manual values can only be set on MANUAL rows of an OPEN month', function () {
    $hr = kpiUser('HR');
    $position = kpiPosition([
        ['name' => 'Manual', 'target' => 100, 'weight' => 50],
        ['name' => 'Auto', 'source' => 'AUTO', 'metric_key' => 'lead_new_count', 'target' => 5, 'weight' => 50],
    ]);
    $employee = Employee::factory()->create(['position_id' => $position->id, 'user_id' => kpiUser('MARKETING')->id]);
    $period = kpiOpenPreviousMonth();
    app(KpiService::class)->compute($period, $hr);
    [$manual, $auto] = KpiScore::where('employee_id', $employee->id)->orderBy('id')->get()->all();

    $this->actingAs($hr)->patch(route('hr.kpi.scores.update', $auto), ['actual' => 9])
        ->assertSessionHasErrors(['actual' => 'Nilai indikator otomatis dihitung sistem, tidak diisi manual.']);
    $this->actingAs($hr)->patch(route('hr.kpi.scores.update', $manual), ['actual' => -1])
        ->assertSessionHasErrors('actual');

    $this->actingAs($hr)->patch(route('hr.kpi.scores.update', $manual), ['actual' => 90])->assertSessionHasNoErrors();
    expect((float) $manual->fresh()->actual)->toBe(90.0)
        ->and($manual->fresh()->input_by)->toBe($hr->id);

    $audit = AuditLog::where('action', 'hr.kpi_score_updated')->sole();
    expect($audit->old_values['actual'])->toBeNull()
        ->and((float) $audit->new_values['actual'])->toBe(90.0);

    $this->actingAs($hr)->post(route('hr.kpi.periods.close', $period))->assertSessionHasNoErrors();

    $this->actingAs($hr)->patch(route('hr.kpi.scores.update', $manual), ['actual' => 50])
        ->assertSessionHasErrors(['period' => 'Periode KPI ini sudah ditutup dan tidak bisa diubah.']);
    expect((float) $manual->fresh()->actual)->toBe(90.0);
});

test('closing is refused before a compute and while manual values are missing', function () {
    $hr = kpiUser('HR');
    $position = kpiPosition([['name' => 'A', 'weight' => 50], ['name' => 'B', 'weight' => 50]]);
    Employee::factory()->count(2)->create(['position_id' => $position->id]);
    $period = kpiOpenPreviousMonth();

    $this->actingAs($hr)->post(route('hr.kpi.periods.close', $period))
        ->assertSessionHasErrors(['period' => 'Periode ini belum pernah dihitung. Klik "Hitung" dulu sebelum menutup.']);

    app(KpiService::class)->compute($period, $hr);
    $this->actingAs($hr)->post(route('hr.kpi.periods.close', $period))
        ->assertSessionHasErrors(['period' => 'Masih ada 4 nilai indikator manual yang belum diisi. Lengkapi dulu sebelum menutup periode.']);

    expect($period->fresh()->status)->toBe(KpiPeriodStatus::Open)
        ->and(AuditLog::where('action', 'hr.kpi_period_closed')->count())->toBe(0);
});

test('a closed month is locked: scores immutable, template edits never reach it', function () {
    $hr = kpiUser('HR');
    $position = kpiPosition([['name' => 'Kualitas', 'target' => 100, 'weight' => 100]]);
    $employee = Employee::factory()->create(['position_id' => $position->id]);
    $period = kpiOpenPreviousMonth();
    $service = app(KpiService::class);
    $service->compute($period, $hr);
    $score = KpiScore::where('employee_id', $employee->id)->sole();
    $service->updateManualScore($score, 70, $hr);

    $this->actingAs($hr)->post(route('hr.kpi.periods.close', $period))->assertSessionHasNoErrors();

    $period->refresh();
    expect($period->status)->toBe(KpiPeriodStatus::Closed)
        ->and($period->closed_by)->toBe($hr->id)
        ->and($period->closed_at)->not->toBeNull()
        ->and(AuditLog::where('action', 'hr.kpi_period_closed')->count())->toBe(1);

    $service->saveTemplate($position, [['name' => 'Baru', 'source' => 'MANUAL', 'target' => 10, 'weight' => 100, 'direction' => 'LOWER_BETTER']], $hr);

    $score->refresh();
    expect($score->indicator_name)->toBe('Kualitas')
        ->and((float) $score->target)->toBe(100.0)
        ->and((float) $score->weighted_score)->toBe(70.0);

    expect(fn () => $score->update(['actual' => 1]))->toThrow(LogicException::class);
    expect(fn () => $score->delete())->toThrow(LogicException::class);
    expect(fn () => $service->compute($period, $hr))->toThrow(ValidationException::class);
    $this->actingAs($hr)->post(route('hr.kpi.periods.close', $period))->assertSessionHasErrors('period');
});

// ── Read models ──────────────────────────────────────────────────────────

test('the employee tab lists months newest first; self view only CLOSED ones', function () {
    $employee = Employee::factory()->create();
    $closed = KpiPeriod::factory()->create(['period' => now()->subMonthsNoOverflow(2)->format('Y-m'), 'status' => 'CLOSED']);
    $open = KpiPeriod::factory()->create(['period' => now()->subMonthNoOverflow()->format('Y-m')]);
    KpiScore::factory()->for($closed, 'period')->for($employee)->create(['weighted_score' => 88]);
    KpiScore::factory()->for($open, 'period')->for($employee)->create(['weighted_score' => 70]);

    $all = app(KpiService::class)->forEmployee($employee);
    expect(collect($all['periods'])->pluck('period')->all())->toBe([$open->period, $closed->period])
        ->and($all['periods'][1]['total'])->toBe(88.0)
        ->and(collect($all['trend'])->pluck('period')->all())->toBe([$closed->period, $open->period]);

    $own = app(KpiService::class)->forEmployee($employee, true);
    expect(collect($own['periods'])->pluck('period')->all())->toBe([$closed->period]);
});

test('the KPI page ranks employees per position for the chosen month', function () {
    $hr = kpiUser('HR');
    $position = kpiPosition([['weight' => 100]]);
    $period = KpiPeriod::factory()->create(['period' => kpiPreviousMonth()->format('Y-m'), 'status' => 'CLOSED']);
    $low = Employee::factory()->create(['position_id' => $position->id, 'name' => 'Rendah']);
    $high = Employee::factory()->create(['position_id' => $position->id, 'name' => 'Tinggi']);
    KpiScore::factory()->for($period, 'period')->for($low)->create(['weighted_score' => 60]);
    KpiScore::factory()->for($period, 'period')->for($high)->create(['weighted_score' => 95]);

    $this->actingAs($hr)->get(route('hr.kpi.index', ['period' => $period->period, 'position' => $position->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('selected.period', $period->period)
            ->has('board', 2)
            ->where('board.0.name', 'Tinggi')
            ->where('board.0.rank', 1)
            ->where('board.1.total', 60));

    $summary = app(KpiService::class)->dashboardSummary();
    expect($summary['latestClosed']['average'])->toBe(77.5)
        ->and($summary['top'][0]['name'])->toBe('Tinggi')
        ->and($summary['bottom'][0]['name'])->toBe('Rendah');
});

test('semesterAverage averages CLOSED month totals only', function () {
    $employee = Employee::factory()->create();
    $jan = KpiPeriod::factory()->create(['period' => '2026-01', 'status' => 'CLOSED']);
    $feb = KpiPeriod::factory()->create(['period' => '2026-02', 'status' => 'CLOSED']);
    $mar = KpiPeriod::factory()->create(['period' => '2026-03', 'status' => 'OPEN']);
    $jul = KpiPeriod::factory()->create(['period' => '2026-07', 'status' => 'CLOSED']);
    KpiScore::factory()->for($jan, 'period')->for($employee)->create(['weighted_score' => 60]);
    KpiScore::factory()->for($jan, 'period')->for($employee)->create(['weighted_score' => 20]);
    KpiScore::factory()->for($feb, 'period')->for($employee)->create(['weighted_score' => 100]);
    KpiScore::factory()->for($mar, 'period')->for($employee)->create(['weighted_score' => 10]);
    KpiScore::factory()->for($jul, 'period')->for($employee)->create(['weighted_score' => 50]);

    expect(app(KpiService::class)->semesterAverage($employee, 2026, 1))->toBe(['average' => 90.0, 'months' => 2])
        ->and(app(KpiService::class)->semesterAverage($employee, 2026, 2))->toBe(['average' => 50.0, 'months' => 1]);
});

test('the demo seeder builds valid default templates and three months', function () {
    kpiUser('HR');
    $this->seed(OrganizationStructureSeeder::class);
    Employee::factory()->create(['position_id' => OrganizationStructureSeeder::position('Drafter')->id]);
    Employee::factory()->create(['position_id' => OrganizationStructureSeeder::position('Estimator')->id, 'user_id' => kpiUser('ESTIMATOR')->id]);

    $this->seed(KpiDemoSeeder::class);
    $this->seed(KpiDemoSeeder::class); // idempotent

    expect(KpiTemplate::count())->toBe(count(KpiDemoSeeder::TEMPLATES))
        ->and(KpiPeriod::where('status', 'CLOSED')->count())->toBe(2)
        ->and(KpiPeriod::where('status', 'OPEN')->count())->toBe(1)
        ->and(KpiPeriod::where('status', 'OPEN')->value('period'))->toBe(kpiPreviousMonth()->format('Y-m'))
        ->and(KpiScore::whereNull('actual')->where('source', 'MANUAL')->count())->toBeGreaterThan(0);
});

test('PM, QA, marketing and finance AUTO metrics read their operational data', function () {
    $registry = app(KpiMetricRegistry::class);
    $month = kpiPreviousMonth();
    $to = $month->copy()->addMonthNoOverflow();
    $at = fn (int $days) => $month->copy()->addDays($days)->setTime(10, 0);
    $pm = kpiUser('PM');
    $qa = kpiUser('QA');
    $marketing = kpiUser('MARKETING');

    // PM: one milestone QA-approved before its target, one still open past target.
    $project = Project::factory()->create(['pm_id' => $pm->id, 'start_date' => $month->copy()->subDays(10)]);
    $onTime = Milestone::factory()->create(['project_id' => $project->id, 'target_date' => $at(5), 'status' => 'COMPLETED']);
    $late = Milestone::factory()->create(['project_id' => $project->id, 'target_date' => $at(10), 'status' => 'IN_PROGRESS', 'order' => 1]);
    $approvedForm = QaForm::factory()->create(['project_id' => $project->id, 'milestone_id' => $onTime->id, 'status' => 'APPROVED', 'reviewed_at' => $at(4)]);
    $rejectedForm = QaForm::factory()->create(['project_id' => $project->id, 'milestone_id' => $late->id, 'status' => 'REJECTED', 'reviewed_at' => $at(9)]);
    $audit = app(AuditLogService::class);
    $this->travelTo($at(4));
    $audit->record('qa.approved', $approvedForm, null, [], $qa);
    $this->travelTo($at(9));
    $audit->record('qa.rejected', $rejectedForm, null, [], $qa);

    // Marketing: one new lead dealt this month, one older lead lost, one overdue follow-up.
    $this->travelTo($month->copy()->subDays(20));
    $lost = Lead::factory()->create(['assigned_to' => $marketing->id, 'status' => 'LOST']);
    Lead::factory()->create(['assigned_to' => $marketing->id, 'status' => 'FOLLOW_UP', 'follow_up_date' => $at(3)]);
    $this->travelTo($at(2));
    $deal = Lead::factory()->create(['assigned_to' => $marketing->id, 'status' => 'DEAL_DESAIN', 'follow_up_date' => null]);
    PipelineLog::create(['lead_id' => $deal->id, 'from_status' => 'FOLLOW_UP', 'to_status' => 'DEAL_DESAIN', 'changed_by' => $marketing->id]);
    $this->travelTo($at(6));
    PipelineLog::create(['lead_id' => $lost->id, 'from_status' => 'FOLLOW_UP', 'to_status' => 'LOST', 'changed_by' => $marketing->id]);
    $this->travelBack();

    // Finance: one termin paid on time, one unpaid past its date.
    Termin::factory()->create(['project_id' => $project->id, 'scheduled_date' => $at(5), 'paid_at' => $at(4), 'status' => 'PAID']);
    Termin::factory()->create(['project_id' => $project->id, 'scheduled_date' => $at(6), 'termin_number' => 2]);

    expect($registry->compute('milestone_on_time_rate', $pm, $month, $to))->toBe(50.0)
        ->and($registry->compute('project_delay_count', $pm, $month, $to))->toBe(1.0)
        ->and($registry->compute('qa_first_pass_rate', $pm, $month, $to))->toBe(50.0)
        ->and($registry->compute('qa_forms_processed_count', $qa, $month, $to))->toBe(2.0)
        ->and($registry->compute('lead_new_count', $marketing, $month, $to))->toBe(1.0)
        ->and($registry->compute('lead_conversion_rate', $marketing, $month, $to))->toBe(50.0)
        ->and($registry->compute('lead_overdue_followup_count', $marketing, $month, $to))->toBe(1.0)
        ->and($registry->compute('termin_on_time_rate', $pm, $month, $to))->toBe(50.0)
        // Nothing to measure → null, not zero.
        ->and($registry->compute('milestone_on_time_rate', $qa, $month, $to))->toBeNull()
        ->and($registry->compute('design_on_schedule_rate', $qa, $month, $to))->toBeNull();
});
