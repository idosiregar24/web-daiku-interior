<?php

use App\Enums\MilestoneStatus;
use App\Enums\QaStatus;
use App\Models\Milestone;
use App\Models\Notification;
use App\Models\Project;
use App\Models\QaForm;
use App\Models\User;
use App\Services\MilestoneService;
use Database\Seeders\RoleSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => $this->seed(RoleSeeder::class));

test('roles with read access can view the QA form index', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('qa-forms.index'))->assertOk();
})->with(['CEO', 'PM', 'QA']);

test('roles without access are forbidden from the QA form index', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('qa-forms.index'))->assertForbidden();
})->with(['MARKETING', 'DESIGNER', 'ESTIMATOR', 'FINANCE', 'LOGISTICS', 'FIELD_STAFF']);

test('PM marking a milestone done creates a QA Form and sets it to QA_WAITING', function () {
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $project = Project::factory()->create(['pm_id' => $pm->id]);
    $milestone = Milestone::factory()->create([
        'project_id' => $project->id,
        'status' => MilestoneStatus::InProgress->value,
        'order' => 1,
    ]);

    $this->actingAs($pm)->post(route('milestones.markDone', ['milestone' => $milestone->id]))->assertRedirect();

    $milestone->refresh();
    expect($milestone->status)->toBe(MilestoneStatus::QaWaiting)
        ->and(QaForm::where('milestone_id', $milestone->id)->exists())->toBeTrue();
});

test('QA approving a form completes the milestone and advances the next one', function () {
    $qa = User::factory()->create();
    $qa->assignRole('QA');
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $project = Project::factory()->create(['pm_id' => $pm->id]);
    $current = Milestone::factory()->create([
        'project_id' => $project->id,
        'status' => MilestoneStatus::QaWaiting->value,
        'order' => 1,
    ]);
    $next = Milestone::factory()->create([
        'project_id' => $project->id,
        'status' => MilestoneStatus::Pending->value,
        'order' => 2,
    ]);
    $qaForm = QaForm::factory()->create(['project_id' => $project->id, 'milestone_id' => $current->id]);

    $this->actingAs($qa)->put(route('qa-forms.update', ['qa_form' => $qaForm->id]), [
        'decision' => 'approve',
        'checklist_data' => [['label' => 'Item 1', 'passed' => true, 'note' => null]],
    ])->assertRedirect();

    expect($current->fresh()->status)->toBe(MilestoneStatus::Completed)
        ->and($next->fresh()->status)->toBe(MilestoneStatus::InProgress)
        ->and(Notification::where('user_id', $pm->id)->where('type', 'qa_approved')->exists())->toBeTrue();
});

test('QA rejecting a form requires notes and sends the milestone back to IN_PROGRESS', function () {
    $qa = User::factory()->create();
    $qa->assignRole('QA');
    $milestone = Milestone::factory()->create(['status' => MilestoneStatus::QaWaiting->value]);
    $qaForm = QaForm::factory()->create(['project_id' => $milestone->project_id, 'milestone_id' => $milestone->id]);

    $this->actingAs($qa)->put(route('qa-forms.update', ['qa_form' => $qaForm->id]), [
        'decision' => 'reject',
        'checklist_data' => [['label' => 'Item 1', 'passed' => false, 'note' => 'Belum rapi']],
    ])->assertSessionHasErrors('notes');

    $this->actingAs($qa)->put(route('qa-forms.update', ['qa_form' => $qaForm->id]), [
        'decision' => 'reject',
        'checklist_data' => [['label' => 'Item 1', 'passed' => false, 'note' => 'Belum rapi']],
        'notes' => 'Perbaiki kerapian dulu.',
    ])->assertRedirect();

    expect($milestone->fresh()->status)->toBe(MilestoneStatus::InProgress)
        ->and($qaForm->fresh()->rejection_count)->toBe(1);
});

test('a second consecutive rejection notifies every CEO', function () {
    $qa = User::factory()->create();
    $qa->assignRole('QA');
    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');
    $milestone = Milestone::factory()->create(['status' => MilestoneStatus::QaWaiting->value]);
    $qaForm = QaForm::factory()->create([
        'project_id' => $milestone->project_id,
        'milestone_id' => $milestone->id,
        'rejection_count' => 1,
    ]);

    $this->actingAs($qa)->put(route('qa-forms.update', ['qa_form' => $qaForm->id]), [
        'decision' => 'reject',
        'checklist_data' => [['label' => 'Item 1', 'passed' => false, 'note' => null]],
        'notes' => 'Masih belum sesuai.',
    ])->assertRedirect();

    expect($qaForm->fresh()->rejection_count)->toBe(2)
        ->and(Notification::where('user_id', $ceo->id)->where('type', 'qa_rejected_twice')->exists())->toBeTrue();
});

test('an APPROVED QA Form cannot be reviewed again', function () {
    $qa = User::factory()->create();
    $qa->assignRole('QA');
    $qaForm = QaForm::factory()->create(['status' => QaStatus::Approved->value]);

    $this->actingAs($qa)->put(route('qa-forms.update', ['qa_form' => $qaForm->id]), [
        'decision' => 'approve',
        'checklist_data' => [['label' => 'Item 1', 'passed' => true, 'note' => null]],
    ])->assertSessionHasErrors('status');
});

test('roles other than QA cannot review a QA Form', function () {
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $qaForm = QaForm::factory()->create();

    $this->actingAs($pm)->put(route('qa-forms.update', ['qa_form' => $qaForm->id]), [
        'decision' => 'approve',
        'checklist_data' => [['label' => 'Item 1', 'passed' => true, 'note' => null]],
    ])->assertForbidden();
});

test('markDone is rejected for a milestone already QA_WAITING or COMPLETED', function () {
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $milestone = Milestone::factory()->create(['status' => MilestoneStatus::Completed->value]);

    expect(fn () => app(MilestoneService::class)->markDone($milestone))
        ->toThrow(ValidationException::class);
});
