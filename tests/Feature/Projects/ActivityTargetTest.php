<?php

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskScheduleChange;
use App\Models\User;
use App\Services\ProductionService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    // Wednesday 14 Oct 2026 (WIB) — the week runs Mon 12 → Sun 18.
    $this->travelTo(Carbon::parse('2026-10-14 10:00:00', 'Asia/Jakarta'));
});

function activityUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * Four daily targets: two planned Monday (one done Monday, one done
 * Tuesday early morning WIB), one planned Tuesday that rolled to today
 * unfinished, one planned for Thursday.
 *
 * @return array{project: Project, tukang: User}
 */
function activityFixture(): array
{
    $tukang = activityUser('FIELD_STAFF');
    $project = Project::factory()->create();
    $target = fn (string $planned, array $extra = []) => Task::factory()->create([
        'project_id' => $project->id,
        'assignee_id' => $tukang->id,
        'title' => 'Rahasia judul target',
        'planned_date' => $planned,
        'due_date' => $planned,
        ...$extra,
    ]);

    $target('2026-10-12', ['status' => TaskStatus::Done->value, 'completed_at' => Carbon::parse('2026-10-12 15:00', 'Asia/Jakarta')]);
    $target('2026-10-12', ['status' => TaskStatus::Done->value, 'completed_at' => Carbon::parse('2026-10-13 06:00', 'Asia/Jakarta')]);
    $rolled = $target('2026-10-13', ['status' => TaskStatus::OnProgress->value, 'due_date' => '2026-10-14', 'rollover_count' => 1]);
    $target('2026-10-15');

    TaskScheduleChange::create([
        'task_id' => $rolled->id, 'from_date' => '2026-10-13', 'to_date' => '2026-10-14', 'kind' => TaskScheduleChange::ROLLOVER,
    ]);

    return ['project' => $project, 'tukang' => $tukang];
}

test('Activity Target: daily target vs actual per working day, cumulated up to the project progress', function () {
    ['project' => $project] = activityFixture();

    $data = app(ProductionService::class)->activityTarget($project);

    expect($data['today'])->toBe('2026-10-14')
        ->and($data['summary'])->toBe([
            'total' => 4,
            'done' => 2,
            'progress' => 50,
            'planned_to_date' => 3,
            'target_to_date' => 75,
            'gap' => -25,
            'rolled' => 1,
        ])
        ->and($data['weeks'])->toHaveCount(1);

    $week = $data['weeks'][0];
    expect($week)->toMatchArray(['start' => '2026-10-12', 'end' => '2026-10-18', 'target' => 4, 'actual' => 2, 'rolled' => 1, 'cum_target' => 100, 'cum_actual' => 50])
        ->and($week['days'])->toBe([
            ['date' => '2026-10-12', 'target' => 2, 'actual' => 1, 'rolled' => 0, 'cum_target' => 50, 'cum_actual' => 25],
            // Done at 06:00 WIB on Tuesday counts on Tuesday; the target keeps its first planned day.
            ['date' => '2026-10-13', 'target' => 1, 'actual' => 1, 'rolled' => 1, 'cum_target' => 75, 'cum_actual' => 50],
            ['date' => '2026-10-14', 'target' => 0, 'actual' => 0, 'rolled' => 0, 'cum_target' => 75, 'cum_actual' => 50],
            // Not here yet: no actual.
            ['date' => '2026-10-15', 'target' => 1, 'actual' => null, 'rolled' => 0, 'cum_target' => 100, 'cum_actual' => null],
        ]);

    // The last actual figure is the project's progress everywhere else.
    expect($week['cum_actual'])->toBe($project->actualProgress());
});

test('Activity Target skips off days without activity and is empty without targets', function () {
    $project = Project::factory()->create();
    $service = app(ProductionService::class);

    expect($service->activityTarget($project)['weeks'])->toBe([])
        ->and($service->activityTarget($project)['summary']['progress'])->toBe(0);

    // Saturday 10 → Monday 12: Sunday 11 is off (config work days Mon–Sat) and empty.
    Task::factory()->create(['project_id' => $project->id, 'planned_date' => '2026-10-10', 'due_date' => '2026-10-10']);
    Task::factory()->create(['project_id' => $project->id, 'planned_date' => '2026-10-12', 'due_date' => '2026-10-12']);

    $days = collect($service->activityTarget($project)['weeks'])->flatMap(fn (array $week) => $week['days'])->pluck('date')->all();

    expect($days)->toBe(['2026-10-10', '2026-10-12', '2026-10-13', '2026-10-14']);
});

test('the Activity Target tab gets numbers only — never a task title — and the Tukang none', function () {
    ['project' => $project, 'tukang' => $tukang] = activityFixture();

    foreach (['CEO', 'PM', 'QA', 'FINANCE', 'MARKETING'] as $role) {
        $this->actingAs(activityUser($role))->get(route('projects.show', $project))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('activityTarget.summary.progress', 50)
                ->has('activityTarget.weeks', 1)
                ->where('activityTarget', fn ($data) => ! str_contains(json_encode($data), 'Rahasia judul target')
                    && ! str_contains(json_encode($data), $tukang->name)));
    }

    $this->actingAs($tukang)->get(route('projects.show', $project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('activityTarget', null));
});
