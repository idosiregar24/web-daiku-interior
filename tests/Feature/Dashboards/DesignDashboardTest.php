<?php

use App\Enums\DesignStatus;
use App\Enums\ProjectStatus;
use App\Models\Design;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Termin;
use App\Models\User;
use App\Services\DivisionDashboardService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    // A Wednesday — "this week" runs to Sunday 4 Oct.
    $this->travelTo(Carbon::parse('2026-09-30 10:00:00'));
});

function designDashboardUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** A project that came out of a design (lead → design → project). */
function designProject(array $projectAttributes, ?User $pic = null): Project
{
    $lead = Lead::factory()->create();
    Design::factory()->create(['lead_id' => $lead->id, 'pic_id' => $pic?->id]);

    return Project::factory()->create(['lead_id' => $lead->id, ...$projectAttributes]);
}

// ── RBAC: CEO + Designer only ────────────────────────────────────────────

test('CEO and Designer open KPI Desain', function (string $role) {
    $this->actingAs(designDashboardUser($role))->get(route('design.dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Design/Dashboard')
            ->has('kpis.summary')
            ->has('kpis.byPic')
            ->has('revenue.months', 6)
            ->has('revenue.totals')
            ->has('revenue.projects')
            ->has('monthOptions', DivisionDashboardService::RANGE_MONTHS)
            ->where('range.to', '2026-09')
            ->where('range.from', '2026-04'));
})->with(['CEO', 'DESIGNER']);

test('every other role is refused KPI Desain', function (string $role) {
    $this->actingAs(designDashboardUser($role))->get(route('design.dashboard'))->assertForbidden();
})->with(['MARKETING', 'ESTIMATOR', 'PM', 'QA', 'FINANCE', 'LOGISTICS', 'FIELD_STAFF']);

// ── KPI per PIC ──────────────────────────────────────────────────────────

test('KPI per PIC splits on schedule vs delay per designer', function () {
    $nisa = designDashboardUser('DESIGNER');
    $nisa->update(['name' => 'Nisa']);
    $yola = designDashboardUser('DESIGNER');
    $yola->update(['name' => 'Yola']);
    $idle = designDashboardUser('DESIGNER');
    $idle->update(['name' => 'Umi']);

    // Nisa: on schedule, delayed (job count), finished late.
    Design::factory()->create(['pic_id' => $nisa->id, 'status' => DesignStatus::Desain->value, 'deadline' => '2026-10-10']);
    Design::factory()->create(['pic_id' => $nisa->id, 'status' => DesignStatus::Desain->value, 'deadline' => '2026-09-27', 'delay_hari' => 3]);
    Design::factory()->create(['pic_id' => $nisa->id, 'status' => DesignStatus::DoneProduksi->value, 'deadline' => '2026-08-01', 'delay_hari' => 2]);
    // Yola: past deadline before the nightly job ran (delayed), suspended
    // on client hold (not delayed), finished on time.
    Design::factory()->create(['pic_id' => $yola->id, 'status' => DesignStatus::Desain->value, 'deadline' => '2026-09-28']);
    Design::factory()->create(['pic_id' => $yola->id, 'status' => DesignStatus::HoldClient->value, 'deadline' => '2026-09-25']);
    Design::factory()->create(['pic_id' => $yola->id, 'status' => DesignStatus::DoneProduksi->value, 'deadline' => '2026-09-01']);
    // No PIC.
    Design::factory()->create(['pic_id' => null, 'status' => DesignStatus::Brief->value]);

    $kpis = app(DivisionDashboardService::class)->designKpis();
    $rows = collect($kpis['byPic'])->keyBy('name');

    expect($kpis['summary'])->toBe([
        'total' => 7,
        'active' => 5,
        'done' => 2,
        'delayedActive' => 2,
        'onTimeRate' => 57,
    ]);

    expect($rows['Nisa'])->toMatchArray([
        'total' => 3, 'active' => 2, 'done' => 1, 'onSchedule' => 1, 'delayed' => 2, 'onTimeRate' => 33, 'avgDelayDays' => 2.5,
    ]);
    expect($rows['Yola'])->toMatchArray([
        'total' => 3, 'active' => 2, 'done' => 1, 'onSchedule' => 2, 'delayed' => 1, 'onTimeRate' => 67, 'avgDelayDays' => null,
    ]);
    // An idle designer still gets a row; designs without a PIC are grouped last.
    expect($rows['Umi'])->toMatchArray(['total' => 0, 'onTimeRate' => null]);
    expect(collect($kpis['byPic'])->last())->toMatchArray(['picId' => null, 'name' => 'Tanpa PIC', 'total' => 1]);
});

// ── Omset & piutang ──────────────────────────────────────────────────────

test('omset and piutang cover design projects per closing month, unscheduled value included', function () {
    $pic = designDashboardUser('DESIGNER');

    $thisMonth = designProject(['contract_value' => 100_000_000, 'created_at' => '2026-09-10 09:00:00'], $pic);
    // Fully paid termin (DP + pelunasan) and a partly paid one; 20jt has no termin yet.
    Termin::factory()->create(['project_id' => $thisMonth->id, 'amount' => 30_000_000, 'dp_amount' => 10_000_000, 'pelunasan' => 20_000_000, 'status' => 'PAID']);
    Termin::factory()->create(['project_id' => $thisMonth->id, 'termin_number' => 2, 'amount' => 50_000_000, 'dp_amount' => 5_000_000]);
    // No termins at all — the whole contract is still owed.
    designProject(['contract_value' => 40_000_000, 'created_at' => '2026-07-15 09:00:00']);

    // Out of scope: no design behind it, cancelled, older than the range.
    Project::factory()->create(['contract_value' => 999_000_000, 'created_at' => '2026-09-12 09:00:00']);
    designProject(['contract_value' => 888_000_000, 'status' => ProjectStatus::Cancelled->value, 'created_at' => '2026-09-12 09:00:00']);
    designProject(['contract_value' => 77_000_000, 'created_at' => '2026-01-20 09:00:00']);

    $response = $this->actingAs(designDashboardUser('CEO'))->get(route('design.dashboard'))->assertOk();
    $revenue = $response->viewData('page')['props']['revenue'];

    expect($revenue['totals'])->toEqual([
        'omset' => 140_000_000.0,
        'paid' => 35_000_000.0,
        'piutang' => 105_000_000.0,
        'unscheduled' => 60_000_000.0,
        'projects' => 2,
    ]);

    $months = collect($revenue['months'])->keyBy('month');
    expect($months['2026-09'])->toMatchArray(['omset' => 100_000_000.0, 'piutang' => 65_000_000.0, 'projects' => 1])
        ->and($months['2026-07'])->toMatchArray(['omset' => 40_000_000.0, 'piutang' => 40_000_000.0, 'projects' => 1])
        ->and($months['2026-08'])->toMatchArray(['omset' => 0.0, 'projects' => 0]);

    $row = collect($revenue['projects'])->firstWhere('projectId', $thisMonth->id);
    expect($row)->toMatchArray([
        'pic' => $pic->name,
        'contractValue' => 100_000_000.0,
        'paid' => 35_000_000.0,
        'piutang' => 65_000_000.0,
        'unscheduled' => 20_000_000.0,
        'closedAt' => '2026-09-10',
    ]);
});

test('the month filter narrows omset to the chosen range and ignores malformed input', function () {
    designProject(['contract_value' => 77_000_000, 'created_at' => '2026-01-20 09:00:00']);
    designProject(['contract_value' => 10_000_000, 'created_at' => '2026-09-02 09:00:00']);
    $ceo = designDashboardUser('CEO');

    $this->actingAs($ceo)->get(route('design.dashboard', ['from' => '2026-01', 'to' => '2026-02']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('range', ['from' => '2026-01', 'to' => '2026-02'])
            ->has('revenue.months', 2)
            ->where('revenue.totals.omset', fn ($omset) => (float) $omset === 77_000_000.0)
            ->has('revenue.projects', 1));

    // Reversed → swapped; garbage → default last 6 months.
    $this->actingAs($ceo)->get(route('design.dashboard', ['from' => '2026-09', 'to' => '2026-08']))
        ->assertInertia(fn (Assert $page) => $page->where('range', ['from' => '2026-08', 'to' => '2026-09']));
    $this->actingAs($ceo)->get(route('design.dashboard', ['from' => 'x; DROP', 'to' => ['2026-01']]))
        ->assertInertia(fn (Assert $page) => $page->where('range', ['from' => '2026-04', 'to' => '2026-09']));
});

// ── Desain Saya ──────────────────────────────────────────────────────────

test('a designer sees their own deadlines this week and delayed designs; the CEO gets none', function () {
    $designer = designDashboardUser('DESIGNER');
    $other = designDashboardUser('DESIGNER');

    $dueSunday = Design::factory()->create(['pic_id' => $designer->id, 'status' => DesignStatus::Desain->value, 'deadline' => '2026-10-04']);
    Design::factory()->create(['pic_id' => $designer->id, 'status' => DesignStatus::Desain->value, 'deadline' => '2026-10-05']); // next week
    $late = Design::factory()->create(['pic_id' => $designer->id, 'status' => DesignStatus::RevisiDesain->value, 'deadline' => '2026-09-26', 'delay_hari' => 4]);
    Design::factory()->create(['pic_id' => $designer->id, 'status' => DesignStatus::DoneProduksi->value, 'deadline' => '2026-10-01']);
    Design::factory()->create(['pic_id' => $other->id, 'status' => DesignStatus::Desain->value, 'deadline' => '2026-10-01']);

    $this->actingAs($designer)->get(route('design.dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('myDesigns.openCount', 3)
            ->has('myDesigns.dueThisWeek', 1)
            ->where('myDesigns.dueThisWeek.0.id', $dueSunday->id)
            ->where('myDesigns.dueThisWeek.0.daysLeft', 4)
            ->has('myDesigns.overdue', 1)
            ->where('myDesigns.overdue.0.id', $late->id)
            ->where('myDesigns.overdue.0.delayDays', 4));

    $this->actingAs(designDashboardUser('CEO'))->get(route('design.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('myDesigns', null));
});
