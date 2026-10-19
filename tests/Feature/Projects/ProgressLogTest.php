<?php

use App\Enums\MilestoneStatus;
use App\Models\Milestone;
use App\Models\ProgressLog;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\RoleSeeder;

beforeEach(fn () => $this->seed(RoleSeeder::class));

test('PM can add a progress note to their project', function () {
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $project = Project::factory()->create(['pm_id' => $pm->id]);

    $this->actingAs($pm)->post(route('progress-logs.store', ['project' => $project->id]), [
        'description' => 'Pemasangan kusen selesai.',
        'log_date' => now()->toDateString(),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $log = ProgressLog::where('project_id', $project->id)->sole();

    // Sprint 24 D11 — a note only: the progress is the actual activity.
    expect($log->description)->toBe('Pemasangan kusen selesai.')
        ->and($log->percentage)->toBeNull();
});

test('a typed percentage is ignored — progress comes from the actual activity', function () {
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $project = Project::factory()->create(['pm_id' => $pm->id]);

    $this->actingAs($pm)->post(route('progress-logs.store', ['project' => $project->id]), [
        'percentage' => 90,
        'description' => 'Rangka terpasang.',
    ])->assertSessionHasNoErrors();

    expect(ProgressLog::where('project_id', $project->id)->sole()->percentage)->toBeNull();
});

test('roles other than PM cannot add a progress note', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);
    $project = Project::factory()->create();

    $this->actingAs($user)->post(route('progress-logs.store', ['project' => $project->id]), [
        'description' => 'Test',
    ])->assertForbidden();
})->with(['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'QA', 'FINANCE', 'LOGISTICS', 'FIELD_STAFF']);

test('progress note is blocked while a milestone is QA_WAITING', function () {
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $project = Project::factory()->create(['pm_id' => $pm->id]);
    Milestone::factory()->create(['project_id' => $project->id, 'status' => MilestoneStatus::QaWaiting->value]);

    $this->actingAs($pm)->post(route('progress-logs.store', ['project' => $project->id]), [
        'description' => 'Test',
    ])->assertSessionHasErrors('description');

    expect(ProgressLog::where('project_id', $project->id)->exists())->toBeFalse();
});
