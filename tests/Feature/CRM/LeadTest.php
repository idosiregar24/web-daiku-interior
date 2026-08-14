<?php

use App\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadService;
use Database\Seeders\RoleSeeder;
use Illuminate\Validation\ValidationException;

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

    expect(fn () => (new LeadService)->changeStatus($lead, ['status' => 'LOST'], $actor))
        ->toThrow(ValidationException::class);
});

test('lead service blocks further status changes once a lead is LOST', function () {
    $lead = Lead::factory()->create([
        'status' => LeadStatus::Lost->value,
        'lost_reason' => 'Budget klien tidak cukup.',
    ]);
    $actor = User::factory()->create();

    expect(fn () => (new LeadService)->changeStatus($lead, ['status' => 'CLOSING'], $actor))
        ->toThrow(ValidationException::class);
});

test('lead service writes a pipeline log on every status change', function () {
    $lead = Lead::factory()->create(['status' => LeadStatus::FollowUp->value]);
    $actor = User::factory()->create();

    (new LeadService)->changeStatus($lead, ['status' => 'DEAL_DESAIN', 'note' => 'Klien sudah ACC brief.'], $actor);

    expect($lead->fresh()->status)->toBe(LeadStatus::DealDesain)
        ->and($lead->pipelineLogs()->count())->toBe(1)
        ->and($lead->pipelineLogs()->first()->from_status)->toBe(LeadStatus::FollowUp->value)
        ->and($lead->pipelineLogs()->first()->to_status)->toBe(LeadStatus::DealDesain->value)
        ->and($lead->pipelineLogs()->first()->changed_by)->toBe($actor->id);
});
