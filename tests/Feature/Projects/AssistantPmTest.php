<?php

use App\Enums\MaterialRequestStatus;
use App\Models\AuditLog;
use App\Models\Milestone;
use App\Models\Notification;
use App\Models\ProgressLog;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\DivisionDashboardService;
use App\Services\MaterialRequestService;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 12 Sub 11 — the Asisten PM works on the projects assigned to
 * them like the PM (milestones, tasks, progress, the Tukang's material
 * requests), never on allocation/realisation (#22, D2); Marketing follows
 * a project without its internal finance data (#30).
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->pm = assistantUser('PM');
    $this->assistant = assistantUser('ASISTEN_PM');
    $this->tukang = assistantUser('FIELD_STAFF');
    $this->project = Project::factory()->create(['pm_id' => $this->pm->id, 'assistant_pm_id' => $this->assistant->id]);
    $this->other = Project::factory()->create(['pm_id' => $this->pm->id]);
});

function assistantUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

test('a project is managed by its PM and its Asisten PM only', function () {
    expect($this->project->isManagedBy($this->pm))->toBeTrue()
        ->and($this->project->isManagedBy($this->assistant))->toBeTrue()
        ->and($this->other->isManagedBy($this->assistant))->toBeFalse()
        ->and($this->project->isManagedBy(assistantUser('ASISTEN_PM')))->toBeFalse();
});

// ── Own projects only ───────────────────────────────────────────────────

test('the Asisten PM lists, opens and monitors only the projects assigned to them', function () {
    $this->actingAs($this->assistant)->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page->has('projects.data', 1)->where('projects.data.0.id', $this->project->id));

    $this->actingAs($this->assistant)->get(route('projects.show', $this->project))->assertOk();
    $this->actingAs($this->assistant)->get(route('projects.show', $this->other))->assertForbidden();

    $this->actingAs($this->assistant)->get(route('projects.dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('pmOptions', []));
    expect(app(DivisionDashboardService::class)->monitoredProjects($this->assistant)->pluck('id')->all())
        ->toBe([$this->project->id]);
});

// ── Milestones, tasks, progress like the PM ─────────────────────────────

test('the Asisten PM manages milestones, tasks and progress on its project — 403 elsewhere', function () {
    $this->actingAs($this->assistant)->post(route('milestones.store', $this->project), ['name' => 'Produksi', 'target_date' => now()->addWeek()->toDateString()])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->assistant)->post(route('milestones.store', $this->other), ['name' => 'Produksi', 'target_date' => now()->addWeek()->toDateString()])
        ->assertForbidden();

    $milestone = Milestone::where('project_id', $this->project->id)->sole();
    $this->actingAs($this->assistant)->put(route('milestones.update', $milestone), ['name' => 'Produksi Kitchen', 'target_date' => now()->addWeeks(2)->toDateString(), 'status' => 'IN_PROGRESS'])
        ->assertSessionHasNoErrors();
    $otherMilestone = Milestone::factory()->create(['project_id' => $this->other->id]);
    $this->actingAs($this->assistant)->delete(route('milestones.destroy', $otherMilestone))->assertForbidden();

    $task = [
        'title' => 'Pasang rangka',
        'assignee_id' => $this->tukang->id,
        'due_date' => now()->addDays(3)->toDateString(),
    ];
    $this->actingAs($this->assistant)->post(route('tasks.store', $this->project), $task)->assertSessionHasNoErrors();
    $this->actingAs($this->assistant)->post(route('tasks.store', $this->other), $task)->assertForbidden();

    $mine = Task::where('project_id', $this->project->id)->sole();
    $this->actingAs($this->assistant)->patch(route('tasks.updateStatus', $mine), ['status' => 'ONPROGRESS'])->assertSessionHasNoErrors();
    $theirs = Task::factory()->create(['project_id' => $this->other->id, 'assignee_id' => $this->tukang->id]);
    $this->actingAs($this->assistant)->patch(route('tasks.updateStatus', $theirs), ['status' => 'ONPROGRESS'])->assertForbidden();
    $this->actingAs($this->assistant)->delete(route('tasks.destroy', $theirs))->assertForbidden();

    $this->actingAs($this->assistant)->post(route('progress-logs.store', $this->project), ['percentage' => 20, 'description' => 'Rangka terpasang.'])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->assistant)->post(route('progress-logs.store', $this->other), ['percentage' => 20, 'description' => 'x'])
        ->assertForbidden();

    expect(Milestone::where('project_id', $this->project->id)->sole()->name)->toBe('Produksi Kitchen')
        ->and(ProgressLog::where('project_id', $this->project->id)->count())->toBe(1)
        ->and(ProgressLog::where('project_id', $this->other->id)->count())->toBe(0);
});

test('the Asisten PM approves a Tukang material request on its project and is notified of it', function () {
    Task::factory()->create(['project_id' => $this->project->id, 'assignee_id' => $this->tukang->id]);
    Task::factory()->create(['project_id' => $this->other->id, 'assignee_id' => $this->tukang->id]);
    $service = app(MaterialRequestService::class);

    $line = $service->submit($this->project, ['name' => 'Handle pintu', 'qty' => 4], $this->tukang);
    $foreign = $service->submit($this->other, ['name' => 'Paku', 'qty' => 1], $this->tukang);

    expect(Notification::where('type', 'material_request_pm_pending')->where('metadata->project_material_id', $line->id)->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$this->pm->id, $this->assistant->id])->sort()->values()->all());

    $this->actingAs($this->assistant)->get(route('logistics.material-requests.index'))->assertOk();
    $this->actingAs($this->assistant)->post(route('project-materials.pmDecision', $line), ['decision' => 'approve'])->assertSessionHasNoErrors();
    $this->actingAs($this->assistant)->post(route('project-materials.pmDecision', $foreign), ['decision' => 'approve'])->assertForbidden();

    expect($line->fresh()->request_status)->toBe(MaterialRequestStatus::Diajukan)
        ->and($line->fresh()->pm_reviewed_by)->toBe($this->assistant->id);
});

// ── Assignment (D2) ─────────────────────────────────────────────────────

test('the PM of the project changes the Asisten PM in Edit Proyek, audited and notified', function () {
    $next = assistantUser('ASISTEN_PM');

    $this->actingAs($this->pm)->put(route('projects.update', $this->project), [
        'name' => $this->project->name,
        'start_date' => $this->project->start_date->toDateString(),
        'contract_value' => $this->project->contract_value,
        'status' => 'ACTIVE',
        'assistant_pm_id' => $next->id,
    ])->assertSessionHasNoErrors();

    $log = AuditLog::where('action', 'project.assistant_pm_changed')->sole();
    expect($this->project->fresh()->assistant_pm_id)->toBe($next->id)
        ->and($log->old_values['assistant_pm_id'])->toBe($this->assistant->id)
        ->and($log->new_values['assistant_pm_id'])->toBe($next->id)
        ->and(Notification::where('user_id', $next->id)->where('type', 'project_assistant_assigned')->exists())->toBeTrue()
        ->and(Notification::where('user_id', $this->assistant->id)->where('type', 'project_assistant_unassigned')->exists())->toBeTrue();

    // The previous assistant lost the project.
    $this->actingAs($this->assistant)->get(route('projects.show', $this->project))->assertForbidden();
});

test('only an active Asisten PM can be picked, and the assistant itself cannot edit the project', function () {
    $edit = fn (array $extra) => [
        'name' => $this->project->name,
        'start_date' => $this->project->start_date->toDateString(),
        'contract_value' => $this->project->contract_value,
        'status' => 'ACTIVE',
        ...$extra,
    ];

    $this->actingAs($this->pm)->put(route('projects.update', $this->project), $edit(['assistant_pm_id' => assistantUser('PM')->id]))
        ->assertSessionHasErrors('assistant_pm_id');
    $this->actingAs($this->pm)->put(route('projects.update', $this->project), $edit(['assistant_pm_id' => null]))
        ->assertSessionHasNoErrors();
    expect($this->project->fresh()->assistant_pm_id)->toBeNull();

    $this->project->update(['assistant_pm_id' => $this->assistant->id]);
    $this->actingAs($this->assistant)->put(route('projects.update', $this->project), $edit(['assistant_pm_id' => null]))->assertForbidden();

    $this->actingAs($this->pm)->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page->has('assistantPms', 1));
});

// ── Marketing (#30) ─────────────────────────────────────────────────────

test('Marketing follows milestones, progress, termins and documents — without internal finance data', function () {
    Milestone::factory()->create(['project_id' => $this->project->id]);
    ProgressLog::factory()->create(['project_id' => $this->project->id]);

    $this->actingAs(assistantUser('MARKETING'))->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canViewMilestones', true)
            ->has('milestones', 1)
            ->where('canViewProgressLogs', true)
            ->has('progressLogs', 1)
            ->where('canViewTermins', true)
            ->has('documents')
            ->where('canViewTasks', false)
            ->where('tasks', [])
            ->where('canViewFinanceSummary', false)
            ->where('allocationBreakdown', [])
            ->where('supplierDebts', [])
            ->where('budget', null)
            ->where('budgetVendors', [])
            ->where('canViewMaterials', false)
            ->where('projectMaterials', [])
            ->where('canManageMilestones', false)
            ->where('canManageProgressLogs', false));
});
