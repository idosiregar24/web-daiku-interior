<?php

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Project;
use App\Models\User;
use App\Services\LeadService;
use Database\Seeders\LeadSourceSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

test('roles with read access can view the lead index', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('crm.leads.index'))->assertOk();
})->with(['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM']);

test('roles without access are forbidden from the lead index', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('crm.leads.index'))->assertForbidden();
})->with(['QA', 'FINANCE', 'LOGISTICS', 'FIELD_STAFF']);

test('lead index exposes lead sources from the master data table', function () {
    $this->seed(LeadSourceSeeder::class);
    $user = User::factory()->create();
    $user->assignRole('MARKETING');

    $this->actingAs($user)->get(route('crm.leads.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('leadSources', LeadSource::count())
        );
});

test('marketing can create a lead and it writes an initial pipeline log', function () {
    $marketing = User::factory()->create();
    $marketing->assignRole('MARKETING');

    $response = $this->actingAs($marketing)->post(route('crm.leads.store'), [
        'client_name' => 'Budi Santoso',
        'contact' => '0812-3456-7890',
        'source' => 'Instagram',
        'priority' => 'HOT',
        'assigned_to' => $marketing->id,
    ]);

    $response->assertRedirect(route('crm.leads.index'));

    $lead = Lead::first();
    expect($lead)->not->toBeNull()
        ->and($lead->status)->toBe(LeadStatus::FollowUp)
        ->and($lead->created_by)->toBe($marketing->id)
        ->and($lead->pipelineLogs)->toHaveCount(1)
        ->and($lead->pipelineLogs->first()->to_status)->toBe(LeadStatus::FollowUp->value);
});

test('field staff cannot create a lead', function () {
    $staff = User::factory()->create();
    $staff->assignRole('FIELD_STAFF');

    $this->actingAs($staff)->post(route('crm.leads.store'), [
        'client_name' => 'Budi Santoso',
        'contact' => '0812-3456-7890',
        'source' => 'Instagram',
        'priority' => 'HOT',
        'assigned_to' => $staff->id,
    ])->assertForbidden();
});

test('lead service requires a lost reason when status changes to LOST', function () {
    $lead = Lead::factory()->create(['status' => LeadStatus::FollowUp->value]);
    $actor = User::factory()->create();

    expect(fn () => app(LeadService::class)->changeStatus($lead, ['status' => 'LOST'], $actor))
        ->toThrow(ValidationException::class);
});

test('lead service blocks further status changes once a lead is LOST', function () {
    $lead = Lead::factory()->create([
        'status' => LeadStatus::Lost->value,
        'lost_reason' => 'Budget klien tidak cukup.',
    ]);
    $actor = User::factory()->create();

    expect(fn () => app(LeadService::class)->changeStatus($lead, ['status' => 'CLOSING'], $actor))
        ->toThrow(ValidationException::class);
});

test('lead service blocks a direct transition to CLOSING outside confirmDeal', function () {
    $lead = Lead::factory()->create(['status' => LeadStatus::DealDesain->value]);
    $actor = User::factory()->create();

    expect(fn () => app(LeadService::class)->changeStatus($lead, ['status' => 'CLOSING'], $actor))
        ->toThrow(ValidationException::class);
});

test('lead service writes a pipeline log on every status change', function () {
    $lead = Lead::factory()->create(['status' => LeadStatus::FollowUp->value]);
    $actor = User::factory()->create();

    app(LeadService::class)->changeStatus($lead, ['status' => 'DEAL_DESAIN', 'note' => 'Klien sudah ACC brief.'], $actor);

    expect($lead->fresh()->status)->toBe(LeadStatus::DealDesain)
        ->and($lead->pipelineLogs()->count())->toBe(1)
        ->and($lead->pipelineLogs()->first()->from_status)->toBe(LeadStatus::FollowUp->value)
        ->and($lead->pipelineLogs()->first()->to_status)->toBe(LeadStatus::DealDesain->value)
        ->and($lead->pipelineLogs()->first()->changed_by)->toBe($actor->id);
});

test('marketing can edit lead fields but the request cannot smuggle a status change', function () {
    $marketing = User::factory()->create();
    $marketing->assignRole('MARKETING');
    $lead = Lead::factory()->create(['status' => LeadStatus::FollowUp->value, 'client_name' => 'Lama']);

    $this->actingAs($marketing)->put(route('crm.leads.update', ['lead' => $lead->id]), [
        'client_name' => 'Baru',
        'contact' => $lead->contact,
        'source' => $lead->source,
        'priority' => $lead->priority->value,
        'assigned_to' => $lead->assigned_to,
        'status' => 'CLOSING',
    ])->assertRedirect();

    expect($lead->fresh()->client_name)->toBe('Baru')
        ->and($lead->fresh()->status)->toBe(LeadStatus::FollowUp);
});

test('marketing can change a lead status to DEAL_DESAIN and it is logged', function () {
    $marketing = User::factory()->create();
    $marketing->assignRole('MARKETING');
    $lead = Lead::factory()->create(['status' => LeadStatus::FollowUp->value]);

    $this->actingAs($marketing)->patch(route('crm.leads.updateStatus', ['lead' => $lead->id]), [
        'status' => 'DEAL_DESAIN',
    ])->assertRedirect();

    expect($lead->fresh()->status)->toBe(LeadStatus::DealDesain);
});

test('roles without write access cannot change a lead status', function () {
    $designer = User::factory()->create();
    $designer->assignRole('DESIGNER');
    $lead = Lead::factory()->create(['status' => LeadStatus::FollowUp->value]);

    $this->actingAs($designer)->patch(route('crm.leads.updateStatus', ['lead' => $lead->id]), [
        'status' => 'DEAL_DESAIN',
    ])->assertForbidden();
});

test('confirming a deal closes the lead and creates a project in one transaction', function () {
    $marketing = User::factory()->create();
    $marketing->assignRole('MARKETING');
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $lead = Lead::factory()->create(['status' => LeadStatus::DealDesain->value, 'client_name' => 'Budi Santoso']);

    $response = $this->actingAs($marketing)->post(route('crm.leads.confirmDeal', ['lead' => $lead->id]), [
        'name' => 'Proyek Budi Santoso',
        'pm_id' => $pm->id,
        'start_date' => now()->toDateString(),
        'contract_value' => 200_000_000,
    ]);

    $response->assertRedirect(route('crm.leads.index'));

    $lead->refresh();
    expect($lead->status)->toBe(LeadStatus::Closing)
        ->and($lead->pipelineLogs()->count())->toBe(1)
        ->and(Project::where('lead_id', $lead->id)->exists())->toBeTrue();
});

test('confirming a deal is rejected unless the lead is DEAL_DESAIN', function () {
    $marketing = User::factory()->create();
    $marketing->assignRole('MARKETING');
    $pm = User::factory()->create();
    $lead = Lead::factory()->create(['status' => LeadStatus::FollowUp->value]);

    $this->actingAs($marketing)->post(route('crm.leads.confirmDeal', ['lead' => $lead->id]), [
        'name' => 'Proyek Test',
        'pm_id' => $pm->id,
        'start_date' => now()->toDateString(),
        'contract_value' => 200_000_000,
    ])->assertSessionHasErrors('status');

    expect(Project::where('lead_id', $lead->id)->exists())->toBeFalse();
});
