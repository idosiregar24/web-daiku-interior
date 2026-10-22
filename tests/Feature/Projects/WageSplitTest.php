<?php

use App\Enums\ProjectPhase;
use App\Models\AuditLog;
use App\Models\CareerLevel;
use App\Models\Project;
use App\Models\ProjectWageSplit;
use App\Models\ProjectWorkItem;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\SupervisorFee;
use App\Models\SupervisorPayment;
use App\Models\User;
use App\Models\WorkTeam;
use App\Services\WageSplitService;
use App\Services\WorkItemService;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 32 — Pembagian Upah per Orang (File D, `Upah Tim Interior
 * DAIKU.xlsx`): team templates in ⚙ Pengaturan › Tim Tukang, picked per
 * Kepala Tim on a project; every DP / pencairan shows each person's part
 * (bobot ÷ Σ bobot × bagian tim, whole rupiah, adding up exactly).
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);

    foreach (['ceo' => 'CEO', 'pm' => 'PM', 'qa' => 'QA', 'otherQa' => 'QA', 'admin' => 'ADMIN_PROYEK', 'finance' => 'FINANCE', 'logistics' => 'LOGISTICS', 'marketing' => 'MARKETING'] as $key => $role) {
        $this->{$key} = User::factory()->create();
        $this->{$key}->assignRole($role);
    }

    $quotation = Quotation::factory()->create(['type' => 'PROYEK']);
    foreach (['Panel klinik', 'Kabinet'] as $sort => $description) {
        QuotationItem::factory()->create(['quotation_id' => $quotation->id, 'description' => $description, 'qty' => 1, 'unit_price' => 10_000_000, 'total_price' => 10_000_000, 'sort_order' => $sort]);
    }
    $this->project = Project::factory()->create([
        'pm_id' => $this->pm->id, 'quotation_id' => $quotation->id, 'phase' => ProjectPhase::Produksi->value, 'team_lead_name' => 'Putra',
    ]);
    $this->project->supervisors()->attach($this->qa->id);
    $service = app(WorkItemService::class);
    $service->ensureForProject($this->project);
    $this->project->workItems()->get()->each(fn (ProjectWorkItem $item) => $service->update($item, ['unit_wage' => 1_000_000], $this->qa));
    $kabinet = $this->project->workItems()->whereHas('quotationItem', fn ($q) => $q->where('description', 'Kabinet'))->sole();
    $service->update($kabinet, ['team_lead_name' => 'Yusup'], $this->qa);

    // File D, Tim Putra: Ketua Tim 24, two Tukang 1 at 17, one at 14, the company 5.
    $this->putra = [
        ['name' => 'Putra', 'level' => 'Ketua Tim', 'weight' => 24],
        ['name' => 'Andi', 'level' => 'Tukang 1', 'weight' => 17],
        ['name' => 'Bayu', 'level' => 'Tukang 1', 'weight' => 17],
        ['name' => 'Candra', 'level' => null, 'weight' => 14],
        ['name' => null, 'level' => 'Perusahaan', 'weight' => 5, 'company' => true],
    ];
});

function splitPayment(Project $project, array $breakdown, string $status = 'DIBAYAR'): SupervisorPayment
{
    $fee = SupervisorFee::firstOrCreate(['project_id' => $project->id], ['total_amount' => 2_000_000, 'dp_percent' => 10]);

    return SupervisorPayment::create([
        'project_id' => $project->id, 'supervisor_fee_id' => $fee->id, 'kind' => 'DP', 'status' => $status,
        'amount' => array_sum(array_column($breakdown, 'amount')), 'breakdown' => $breakdown, 'requested_at' => now(),
    ]);
}

test('the positions come with File D\'s weights; only the CEO changes them', function () {
    expect(CareerLevel::orderBy('sort_order')->pluck('weight', 'name')->map(fn ($weight) => (float) $weight)->all())
        ->toBe(['Ketua Tim' => 24.0, 'Tukang 1' => 17.0, 'Tukang 2' => 15.0, 'Helper 1' => 9.0, 'Helper 2' => 7.0, 'Perusahaan' => 5.0])
        ->and(CareerLevel::where('name', 'Perusahaan')->value('is_company'))->toBeTrue();

    $helper = CareerLevel::where('name', 'Helper 2')->sole();
    $payload = ['levels' => [['id' => $helper->id, 'name' => 'Helper 2', 'weight' => 8]]];

    foreach ([$this->pm, $this->qa, $this->admin] as $user) {
        $this->actingAs($user)->put(route('work-teams.levels.update'), $payload)->assertForbidden();
    }
    $this->actingAs($this->ceo)->put(route('work-teams.levels.update'), $payload)->assertSessionHasNoErrors();

    expect((float) $helper->fresh()->weight)->toBe(8.0)
        ->and(AuditLog::where('action', 'wage_split.level_updated')->count())->toBe(1);
});

test('Tim Tukang: the CEO, PM, Pengawas and Admin Proyek keep templates; Finance, Logistik and Marketing may not open it', function () {
    foreach ([$this->finance, $this->logistics, $this->marketing] as $user) {
        $this->actingAs($user)->get(route('work-teams.index'))->assertForbidden();
        $this->actingAs($user)->post(route('work-teams.store'), ['name' => 'Tim X', 'members' => $this->putra])->assertForbidden();
    }

    $this->actingAs($this->qa)->post(route('work-teams.store'), ['name' => 'Tim Putra', 'leader_name' => 'Putra', 'members' => $this->putra])
        ->assertSessionHasNoErrors();
    $team = WorkTeam::sole();
    expect($team->members)->toHaveCount(5)->and($team->members[4]['company'])->toBeTrue();

    $this->actingAs($this->admin)->get(route('work-teams.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('WorkTeams/Index')
        ->where('canEditLevels', false)
        ->where('templates.0.name', 'Tim Putra')
        ->where('templates.0.members.0.percent', 31.17));

    // A team without a person, a duplicate name, a zero weight are refused.
    $this->actingAs($this->pm)->post(route('work-teams.store'), ['name' => 'Tim Kosong', 'members' => [['level' => 'Perusahaan', 'weight' => 5, 'company' => true]]])
        ->assertSessionHasErrors('members');
    $this->actingAs($this->pm)->post(route('work-teams.store'), ['name' => 'Tim Putra', 'members' => $this->putra])->assertSessionHasErrors('name');
    $this->actingAs($this->pm)->put(route('work-teams.update', $team), ['name' => 'Tim Putra', 'members' => [['name' => 'Putra', 'weight' => 0]]])
        ->assertSessionHasErrors('members.0.weight');

    $this->actingAs($this->ceo)->delete(route('work-teams.destroy', $team))->assertSessionHasNoErrors();
    expect(WorkTeam::count())->toBe(0)
        ->and(AuditLog::where('action', 'like', 'wage_split.template_%')->count())->toBe(2);
});

test('a project\'s split: picked from a template by the Pengawas or Admin Proyek, never the PM or another QA', function () {
    $team = WorkTeam::create(['name' => 'Tim Putra', 'leader_name' => 'Putra', 'members' => $this->putra]);
    $payload = ['team_label' => 'Putra', 'work_team_id' => $team->id];

    foreach ([$this->pm, $this->otherQa] as $user) {
        $this->actingAs($user)->put(route('projects.wage-splits.save', $this->project), $payload)->assertForbidden();
    }

    $this->actingAs($this->qa)->put(route('projects.wage-splits.save', $this->project), $payload)->assertSessionHasNoErrors();
    $split = ProjectWageSplit::sole();
    expect($split->work_team_id)->toBe($team->id)->and($split->members)->toHaveCount(5);

    // Changing the template later never changes the project's copy.
    $team->update(['members' => [['name' => 'Putra', 'level' => 'Ketua Tim', 'weight' => 24, 'company' => false]]]);
    expect($split->fresh()->members)->toHaveCount(5);

    // Adjusted for this project only (Candra off) by the Admin Proyek.
    $this->actingAs($this->admin)->put(route('projects.wage-splits.save', $this->project), [
        'team_label' => 'Putra', 'members' => array_values(array_filter($this->putra, fn ($row) => $row['name'] !== 'Candra')),
    ])->assertSessionHasNoErrors();
    expect($split->fresh()->members)->toHaveCount(4)->and($split->fresh()->work_team_id)->toBe($team->id);

    // A Kepala Tim not on the project's wage items is refused; an empty list clears.
    $this->actingAs($this->qa)->put(route('projects.wage-splits.save', $this->project), ['team_label' => 'Budi', 'members' => $this->putra])
        ->assertSessionHasErrors('team_label');
    $this->actingAs($this->qa)->put(route('projects.wage-splits.save', $this->project), ['team_label' => 'Putra', 'members' => []])
        ->assertSessionHasNoErrors();
    expect(ProjectWageSplit::count())->toBe(0);
});

test('File D: a team\'s part splits per person in whole rupiah, adding up exactly; the company\'s row is its share', function () {
    $rows = app(WageSplitService::class)->split(770_500, $this->putra);

    expect(array_column($rows, 'amount'))->toBe([240_156.0, 170_110.0, 170_110.0, 140_091.0, 50_033.0])
        ->and(array_sum(array_column($rows, 'amount')))->toBe(770_500.0)
        ->and(array_column($rows, 'percent'))->toBe([31.17, 22.08, 22.08, 18.18, 6.49])
        ->and($rows[4]['company'])->toBeTrue();
});

test('the Item RAB tab shows each payment\'s slip per person — to who sees the Upah Tukang, not to Logistik', function () {
    ProjectWageSplit::create(['project_id' => $this->project->id, 'team_label' => 'Putra', 'members' => $this->putra]);
    splitPayment($this->project, [['label' => 'Putra', 'amount' => 770_500], ['label' => 'Yusup', 'amount' => 100_000]]);
    splitPayment($this->project, [['label' => 'Putra', 'amount' => 5_000]], 'DITOLAK');

    $this->actingAs($this->qa)->get(route('projects.show', $this->project))->assertInertia(fn (Assert $page) => $page
        ->where('execution.can.splitWages', true)
        ->where('execution.wageSplit.teams.0.label', 'Putra')
        ->where('execution.wageSplit.teams.1.label', 'Yusup')
        ->where('execution.wageSplit.teams.1.members', null)
        ->has('execution.wageSplit.slips', 1)
        ->where('execution.wageSplit.slips.0.teams.0.rows.0.amount', 240156)
        ->where('execution.wageSplit.slips.0.teams.1.rows', null)
        ->has('execution.wageSplit.levels', 6));

    // The PM reads the slips (sees the money) but neither edits nor gets the templates.
    $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertInertia(fn (Assert $page) => $page
        ->where('execution.can.splitWages', false)
        ->where('execution.wageSplit.templates', [])
        ->has('execution.wageSplit.slips', 1));

    $this->actingAs($this->logistics)->get(route('projects.show', $this->project))->assertInertia(fn (Assert $page) => $page
        ->where('execution.wageSplit', null));
});
