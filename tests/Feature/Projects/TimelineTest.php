<?php

use App\Enums\MilestoneStatus;
use App\Models\Lead;
use App\Models\Milestone;
use App\Models\Notification;
use App\Models\Project;
use App\Models\User;
use App\Services\MilestoneService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Sprint 23 K16/K17 — a milestone is shown as a "Timeline" and runs from
 * start_date to target_date ("Tanggal Selesai"); overdue still reads the end.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-14 09:00:00', 'Asia/Jakarta'));
    $this->seed(RoleSeeder::class);
    $this->pm = User::factory()->create();
    $this->pm->assignRole('PM');
    $this->project = Project::factory()->create(['pm_id' => $this->pm->id, 'start_date' => '2026-10-01']);
});

afterEach(fn () => Carbon::setTestNow());

test('a new timeline needs a start date not after its end date', function (array $payload, ?string $error) {
    $response = $this->actingAs($this->pm)->post(route('milestones.store', $this->project), ['name' => 'Produksi', ...$payload]);

    $error ? $response->assertSessionHasErrors($error) : $response->assertSessionHasNoErrors();
    expect($this->project->milestones()->count())->toBe($error ? 0 : 1);
})->with([
    'ok' => [['start_date' => '2026-10-15', 'target_date' => '2026-10-30'], null],
    'same day' => [['start_date' => '2026-10-15', 'target_date' => '2026-10-15'], null],
    'no start' => [['target_date' => '2026-10-30'], 'start_date'],
    'end before start' => [['start_date' => '2026-10-15', 'target_date' => '2026-10-14'], 'target_date'],
]);

test('editing a timeline keeps start ≤ end and stores both dates', function () {
    $milestone = Milestone::factory()->create(['project_id' => $this->project->id, 'start_date' => '2026-10-01', 'target_date' => '2026-10-10']);

    $this->actingAs($this->pm)->put(route('milestones.update', $milestone), [
        'name' => 'Produksi', 'start_date' => '2026-10-20', 'target_date' => '2026-10-10', 'status' => 'PENDING',
    ])->assertSessionHasErrors('target_date');

    $this->actingAs($this->pm)->put(route('milestones.update', $milestone), [
        'name' => 'Produksi', 'start_date' => '2026-10-05', 'target_date' => '2026-10-25', 'status' => 'PENDING',
    ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Timeline berhasil diperbarui.');

    expect($milestone->fresh()->start_date->toDateString())->toBe('2026-10-05')
        ->and($milestone->fresh()->target_date->toDateString())->toBe('2026-10-25');
});

test('the service defaults a missing start date: previous end + 1, else the project start', function () {
    $service = app(MilestoneService::class);

    $first = $service->create($this->project, ['name' => 'Desain', 'target_date' => '2026-10-10']);
    $second = $service->create($this->project, ['name' => 'Produksi', 'target_date' => '2026-10-31']);
    $early = $service->create($this->project, ['name' => 'Cek', 'target_date' => '2026-10-20']);

    expect($first->start_date->toDateString())->toBe('2026-10-01')
        ->and($second->start_date->toDateString())->toBe('2026-10-11')
        // The previous end + 1 would be after its own end — clamped.
        ->and($early->start_date->toDateString())->toBe('2026-10-20');
});

test('overdue still reads the end date, and the notification says Timeline', function () {
    Milestone::factory()->create(['project_id' => $this->project->id, 'name' => 'Produksi', 'start_date' => '2026-10-01', 'target_date' => '2026-10-13']);
    Milestone::factory()->create(['project_id' => $this->project->id, 'start_date' => '2026-10-01', 'target_date' => '2026-10-14']);

    expect(app(MilestoneService::class)->markOverdueMilestones())->toBe(1);
    $notification = Notification::where('user_id', $this->pm->id)->where('type', 'milestone_overdue')->sole();
    expect($notification->title)->toBe('Timeline Melewati Tanggal Selesai')
        ->and($notification->message)->toContain('Timeline "Produksi"');
});

test('the Sprint 23 migrations backfill timeline starts and project opening dates, and roll back', function () {
    $milestones = require database_path('migrations/2026_10_14_000007_add_start_date_to_milestones_table.php');
    $projects = require database_path('migrations/2026_10_13_235942_add_opening_details_to_projects_table.php');
    $openings = require database_path('migrations/2026_10_14_000032_add_opener_to_project_openings_table.php');

    $openings->down();
    $milestones->down();
    $projects->down();
    expect(Schema::hasColumn('milestones', 'start_date'))->toBeFalse()
        ->and(Schema::hasColumn('projects', 'team_lead_id'))->toBeFalse()
        ->and(Schema::hasColumn('project_openings', 'opener_id'))->toBeFalse();

    // Pre-Sprint-23 rows: created after the start date, timelines with an end only.
    $old = DB::table('projects')->insertGetId([
        'lead_id' => Lead::factory()->create()->id, 'name' => 'Lama', 'pm_id' => $this->pm->id, 'status' => 'ACTIVE',
        'start_date' => '2026-09-01', 'contract_value' => 1, 'created_at' => '2026-09-05 10:00:00', 'updated_at' => now(),
    ]);
    // (SQLite rebuilds `projects` to add its foreign keys — milestones are inserted after that step.)
    $projects->up();

    foreach ([['A', 0, '2026-09-10'], ['B', 1, '2026-09-30'], ['C', 2, '2026-09-20']] as [$name, $order, $end]) {
        DB::table('milestones')->insert(['project_id' => $old, 'name' => $name, 'order' => $order, 'target_date' => $end, 'status' => MilestoneStatus::Pending->value]);
    }

    $milestones->up();
    $openings->up();

    $starts = DB::table('milestones')->where('project_id', $old)->orderBy('order')->pluck('start_date')->map(fn ($date) => substr($date, 0, 10))->all();
    expect($starts)->toBe(['2026-09-01', '2026-09-11', '2026-09-20'])
        // Opened on its created day, but never after the start date.
        ->and(substr(DB::table('projects')->where('id', $old)->value('opened_on'), 0, 10))->toBe('2026-09-01');
});
