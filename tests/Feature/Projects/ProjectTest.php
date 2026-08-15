<?php

use App\Enums\LeadStatus;
use App\Enums\MilestoneStatus;
use App\Models\Lead;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use App\Services\MilestoneService;
use App\Services\ProjectService;
use Database\Seeders\RoleSeeder;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

test('roles with read access can view the project index', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('projects.index'))->assertOk();
})->with(['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'QA', 'FINANCE', 'LOGISTICS', 'FIELD_STAFF']);

test('field staff project index is scoped to projects with their own assigned tasks', function () {
    $staff = User::factory()->create();
    $staff->assignRole('FIELD_STAFF');

    $ownProject = Project::factory()->create();
    $ownProject->tasks()->create([
        'title' => 'Pasang kusen',
        'assignee_id' => $staff->id,
        'created_by' => $staff->id,
        'due_date' => now()->addWeek()->toDateString(),
        'status' => 'PENDING',
        'priority' => 'MEDIUM',
        'is_locked' => true,
    ]);

    $otherProject = Project::factory()->create();

    $this->actingAs($staff)->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('projects.data', 1)
            ->where('projects.data.0.id', $ownProject->id)
        );
});

test('only PM and CEO can create a project', function () {
    $lead = Lead::factory()->create(['status' => LeadStatus::DealDesain->value]);
    $pm = User::factory()->create();
    $pm->assignRole('PM');

    $response = $this->actingAs($pm)->post(route('projects.store'), [
        'lead_id' => $lead->id,
        'name' => 'Proyek Rumah Budi',
        'pm_id' => $pm->id,
        'start_date' => now()->toDateString(),
        'contract_value' => 150_000_000,
    ]);

    $response->assertRedirect();
    expect(Project::where('lead_id', $lead->id)->exists())->toBeTrue();
});

test('roles without write access cannot create a project', function () {
    $lead = Lead::factory()->create(['status' => LeadStatus::DealDesain->value]);
    $marketing = User::factory()->create();
    $marketing->assignRole('MARKETING');

    $this->actingAs($marketing)->post(route('projects.store'), [
        'lead_id' => $lead->id,
        'name' => 'Proyek Rumah Budi',
        'pm_id' => $marketing->id,
        'start_date' => now()->toDateString(),
        'contract_value' => 150_000_000,
    ])->assertForbidden();
});

test('project service refuses to create a project from a lead that is not DEAL_DESAIN or CLOSING', function () {
    $lead = Lead::factory()->create(['status' => LeadStatus::FollowUp->value]);

    expect(fn () => app(ProjectService::class)->createFromLead($lead, [
        'name' => 'Proyek Test',
        'pm_id' => User::factory()->create()->id,
        'start_date' => now()->toDateString(),
        'contract_value' => 1_000_000,
    ]))->toThrow(ValidationException::class);
});

test('project service refuses a second project for the same lead', function () {
    $lead = Lead::factory()->create(['status' => LeadStatus::DealDesain->value]);
    Project::factory()->create(['lead_id' => $lead->id]);

    expect(fn () => app(ProjectService::class)->createFromLead($lead, [
        'name' => 'Proyek Kedua',
        'pm_id' => User::factory()->create()->id,
        'start_date' => now()->toDateString(),
        'contract_value' => 1_000_000,
    ]))->toThrow(ValidationException::class);
});

test('milestone service assigns the next order automatically', function () {
    $project = Project::factory()->create();
    Milestone::factory()->create(['project_id' => $project->id, 'order' => 0]);

    $milestone = app(MilestoneService::class)->create($project, [
        'name' => 'Instalasi',
        'target_date' => now()->addWeek()->toDateString(),
    ]);

    expect($milestone->order)->toBe(1);
});

test('PM can add a milestone to their project', function () {
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $project = Project::factory()->create(['pm_id' => $pm->id]);

    $response = $this->actingAs($pm)->post(route('milestones.store', ['project' => $project->id]), [
        'name' => '3D Design',
        'target_date' => now()->addWeek()->toDateString(),
    ]);

    $response->assertRedirect();
    expect($project->milestones()->where('name', '3D Design')->exists())->toBeTrue();
});

test('roles without write access cannot add a milestone', function () {
    $qa = User::factory()->create();
    $qa->assignRole('QA');
    $project = Project::factory()->create();

    $this->actingAs($qa)->post(route('milestones.store', ['project' => $project->id]), [
        'name' => '3D Design',
        'target_date' => now()->addWeek()->toDateString(),
    ])->assertForbidden();
});

test('PM can update and delete a milestone', function () {
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $milestone = Milestone::factory()->create();

    $this->actingAs($pm)->put(route('milestones.update', ['milestone' => $milestone->id]), [
        'name' => 'Finishing',
        'target_date' => now()->addWeek()->toDateString(),
        'status' => MilestoneStatus::InProgress->value,
    ])->assertRedirect();

    expect($milestone->fresh()->name)->toBe('Finishing')
        ->and($milestone->fresh()->status)->toBe(MilestoneStatus::InProgress);

    $this->actingAs($pm)->delete(route('milestones.destroy', ['milestone' => $milestone->id]))
        ->assertRedirect();

    expect(Milestone::find($milestone->id))->toBeNull();
});
