<?php

use App\Enums\QaStatus;
use App\Models\Milestone;
use App\Models\OvertimeRequest;
use App\Models\Project;
use App\Models\QaForm;
use App\Models\Task;
use App\Models\Termin;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 13 Sub 05 — Detail Proyek as the hub of a project: the QA and
 * Lembur tabs, each sent only to the roles that may read it.
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->project = Project::factory()->create();
    $milestone = Milestone::factory()->create(['project_id' => $this->project->id]);
    QaForm::factory()->create([
        'project_id' => $this->project->id,
        'milestone_id' => $milestone->id,
        'status' => QaStatus::Rejected->value,
        'notes' => 'Sambungan HPL terlihat',
    ]);
    OvertimeRequest::factory()->create(['project_id' => $this->project->id]);
    Task::factory()->create(['project_id' => $this->project->id]);
    Termin::factory()->create(['project_id' => $this->project->id]);
});

function hubViewer(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

test('the PM gets the QA and Lembur tabs with their data', function () {
    $this->actingAs(hubViewer('PM'))
        ->get(route('projects.show', $this->project))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canViewQa', true)
            ->has('qaForms', 1)
            ->where('qaForms.0.notes', 'Sambungan HPL terlihat')
            ->where('canOpenQaForm', true)
            ->where('canViewOvertime', true)
            ->has('overtimeRequests', 1)
            ->where('canOpenOvertimeList', true));
});

test("the project's Asisten PM reads both tabs but can't open the QA form or Lembur pages", function () {
    $assistant = hubViewer('ASISTEN_PM');
    $this->project->update(['assistant_pm_id' => $assistant->id]);

    $this->actingAs($assistant)
        ->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page
            ->has('qaForms', 1)
            ->where('canOpenQaForm', false)
            ->has('overtimeRequests', 1)
            ->where('canOpenOvertimeList', false));
});

test('QA gets the QA tab but never task data or overtime', function () {
    $this->actingAs(hubViewer('QA'))
        ->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page
            ->has('qaForms', 1)
            ->where('canViewTasks', false)
            ->where('tasks', [])
            ->where('canViewOvertime', false)
            ->where('overtimeRequests', []));
});

test('Finance gets Lembur but not QA', function () {
    $this->actingAs(hubViewer('FINANCE'))
        ->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canViewQa', false)
            ->where('qaForms', [])
            ->has('overtimeRequests', 1));
});

test('Marketing gets neither tab, nor the finance summary', function () {
    $this->actingAs(hubViewer('MARKETING'))
        ->get(route('projects.show', $this->project))
        ->assertInertia(fn (Assert $page) => $page
            ->where('qaForms', [])
            ->where('overtimeRequests', [])
            ->where('canViewFinanceSummary', false)
            ->where('allocationBreakdown', [])
            ->where('budget', null));
});

test('a tab can be opened from the URL', function () {
    // The tab itself is picked client-side (useQueryTab); the server only
    // has to accept the query string on the same page.
    $this->actingAs(hubViewer('PM'))
        ->get(route('projects.show', ['project' => $this->project, 'tab' => 'qa']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Projects/Show'));
});
