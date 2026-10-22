<?php

use App\Enums\ProjectPhase;
use App\Models\Project;
use App\Models\ProjectWageSplit;
use App\Models\ProjectWorkItem;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Models\WorkTeam;
use App\Services\WorkItemService;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 37 — a Tim Tukang template picked for a RAB item (Item RAB › Upah
 * Tukang, saved with the table's "Simpan"): the template's ketua becomes the
 * item's Kepala Tim and its members the project's Pembagian Upah of that
 * Kepala Tim (a copy).
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    foreach (['pm' => 'PM', 'qa' => 'QA', 'admin' => 'ADMIN_PROYEK', 'finance' => 'FINANCE'] as $key => $role) {
        $this->{$key} = User::factory()->create();
        $this->{$key}->assignRole($role);
    }

    $this->budi = User::factory()->create(['name' => 'Budi Tukang']);
    $this->budi->assignRole('FIELD_STAFF');

    $quotation = Quotation::factory()->create(['type' => 'PROYEK']);
    foreach (['Kitchen Set', 'Partisi', 'Listrik'] as $sort => $description) {
        QuotationItem::factory()->create(['quotation_id' => $quotation->id, 'description' => $description, 'qty' => 1, 'unit_price' => 10_000_000, 'total_price' => 10_000_000, 'sort_order' => $sort]);
    }
    $this->project = Project::factory()->create(['pm_id' => $this->pm->id, 'quotation_id' => $quotation->id, 'phase' => ProjectPhase::Persiapan->value]);
    $this->project->supervisors()->attach($this->qa->id);
    app(WorkItemService::class)->ensureForProject($this->project);
    $this->item = fn (string $description) => $this->project->workItems()->whereHas('quotationItem', fn ($query) => $query->where('description', $description))->sole();

    $this->members = [
        ['name' => 'budi tukang', 'level' => 'Ketua Tim', 'weight' => 24, 'company' => false],
        ['name' => 'Rudi', 'level' => 'Tukang 1', 'weight' => 17, 'company' => false],
        ['name' => null, 'level' => 'Perusahaan', 'weight' => 5, 'company' => true],
    ];
    $this->timBudi = WorkTeam::create(['name' => 'Tim Budi', 'leader_name' => 'budi tukang', 'members' => $this->members]);
    $this->timLuar = WorkTeam::create(['name' => 'Tim Jati', 'leader_name' => 'Pak Darto', 'members' => [
        ['name' => 'Pak Darto', 'level' => 'Ketua Tim', 'weight' => 24, 'company' => false],
    ]]);
});

test('a template picked for an item: its ketua (the Tukang account) is the Kepala Tim and its members go to Pembagian Upah', function () {
    $kitchen = ($this->item)('Kitchen Set');

    $this->actingAs($this->qa)->put(route('projects.work-items.wages', $this->project), ['items' => [
        ['id' => $kitchen->id, 'unit_wage' => 1_500_000, 'work_team_id' => $this->timBudi->id],
    ]])->assertSessionHasNoErrors()->assertSessionHas('success', '1 item tersimpan.');

    $kitchen->refresh();
    expect($kitchen->work_team_id)->toBe($this->timBudi->id)
        ->and($kitchen->team_lead_id)->toBe($this->budi->id)
        ->and($kitchen->team_lead_name)->toBeNull()
        ->and((float) $kitchen->unit_wage)->toBe(1_500_000.0);

    $split = ProjectWageSplit::where('project_id', $this->project->id)->sole();
    expect($split->team_label)->toBe('Budi Tukang')
        ->and($split->work_team_id)->toBe($this->timBudi->id)
        ->and(collect($split->members)->pluck('name')->all())->toBe(['budi tukang', 'Rudi', null]);

    $this->actingAs($this->qa)->get(route('projects.show', $this->project))->assertInertia(fn (Assert $page) => $page
        ->where('execution.workItems.sections.0.items.0.work_team', 'Tim Budi')
        ->where('execution.workItems.sections.0.items.0.team_lead', 'Budi Tukang'));
});

test('a ketua without an account stays a typed name; a Kepala Tim picked directly clears the template', function () {
    $partisi = ($this->item)('Partisi');
    $service = app(WorkItemService::class);

    $service->update($partisi, ['unit_wage' => 500_000, 'work_team_id' => $this->timLuar->id], $this->qa);
    expect($partisi->fresh()->only(['team_lead_id', 'team_lead_name', 'work_team_id']))
        ->toBe(['team_lead_id' => null, 'team_lead_name' => 'Pak Darto', 'work_team_id' => $this->timLuar->id]);

    $this->actingAs($this->admin)->put(route('projects.work-items.wages', $this->project), ['items' => [
        ['id' => $partisi->id, 'unit_wage' => 500_000, 'team_lead_id' => $this->budi->id, 'team_lead_name' => null, 'work_team_id' => null],
    ]])->assertSessionHasNoErrors();

    expect($partisi->fresh()->only(['team_lead_id', 'team_lead_name', 'work_team_id']))
        ->toBe(['team_lead_id' => $this->budi->id, 'team_lead_name' => null, 'work_team_id' => null]);
});

test('the copy is the project\'s: changes made on the project stay when the same template is picked again; another template replaces it', function () {
    $service = app(WorkItemService::class);
    $service->update(($this->item)('Kitchen Set'), ['unit_wage' => 1_000_000, 'work_team_id' => $this->timBudi->id], $this->qa);
    ProjectWageSplit::where('project_id', $this->project->id)->update(['members' => json_encode([['name' => 'Budi', 'level' => 'Ketua Tim', 'weight' => 30, 'company' => false]])]);

    // A second item for the same team keeps the project's own members.
    $service->update(($this->item)('Listrik'), ['unit_wage' => 300_000, 'work_team_id' => $this->timBudi->id], $this->qa);
    expect(ProjectWageSplit::where('project_id', $this->project->id)->sole()->members[0]['weight'])->toEqual(30);

    // Changing the template afterwards never touches the project.
    $this->timBudi->update(['members' => [['name' => 'Lain', 'level' => 'Ketua Tim', 'weight' => 10, 'company' => false]]]);
    expect(ProjectWageSplit::where('project_id', $this->project->id)->sole()->members[0]['name'])->toBe('Budi');

    $other = WorkTeam::create(['name' => 'Tim Budi B', 'leader_name' => 'Budi Tukang', 'members' => $this->members]);
    $service->update(($this->item)('Listrik'), ['work_team_id' => $other->id], $this->qa);
    expect(ProjectWageSplit::where('project_id', $this->project->id)->sole()->work_team_id)->toBe($other->id);
});

test('only the Pengawas / Admin Proyek pick the team; an unknown template is refused', function () {
    $kitchen = ($this->item)('Kitchen Set');
    $row = ['id' => $kitchen->id, 'unit_wage' => null, 'work_team_id' => $this->timBudi->id];

    $this->actingAs($this->finance)->put(route('projects.work-items.wages', $this->project), ['items' => [$row]])->assertForbidden();
    $this->actingAs($this->pm)->put(route('projects.work-items.wages', $this->project), ['items' => [$row]])->assertForbidden();
    $this->actingAs($this->qa)->put(route('projects.work-items.wages', $this->project), ['items' => [[...$row, 'work_team_id' => 999]]])
        ->assertSessionHasErrors(['items.0.work_team_id' => 'Template tim tidak ditemukan.']);

    expect(ProjectWorkItem::whereNotNull('work_team_id')->exists())->toBeFalse();
});
