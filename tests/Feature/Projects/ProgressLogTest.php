<?php

use App\Enums\MilestoneStatus;
use App\Models\Milestone;
use App\Models\ProgressLog;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(fn () => $this->seed(RoleSeeder::class));

test('PM can add a progress log to their project', function () {
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $project = Project::factory()->create(['pm_id' => $pm->id]);

    $this->actingAs($pm)->post(route('progress-logs.store', ['project' => $project->id]), [
        'percentage' => 40,
        'description' => 'Pemasangan kusen selesai.',
        'log_date' => now()->toDateString(),
    ])->assertRedirect();

    expect(ProgressLog::where('project_id', $project->id)->where('percentage', 40)->exists())->toBeTrue();
});

test('roles other than PM cannot add a progress log', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);
    $project = Project::factory()->create();

    $this->actingAs($user)->post(route('progress-logs.store', ['project' => $project->id]), [
        'percentage' => 40,
        'description' => 'Test',
    ])->assertForbidden();
})->with(['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'QA', 'FINANCE', 'LOGISTICS', 'FIELD_STAFF']);

test('progress log is blocked while a milestone is QA_WAITING', function () {
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $project = Project::factory()->create(['pm_id' => $pm->id]);
    Milestone::factory()->create(['project_id' => $project->id, 'status' => MilestoneStatus::QaWaiting->value]);

    $this->actingAs($pm)->post(route('progress-logs.store', ['project' => $project->id]), [
        'percentage' => 40,
        'description' => 'Test',
    ])->assertSessionHasErrors('percentage');

    expect(ProgressLog::where('project_id', $project->id)->exists())->toBeFalse();
});
