<?php

use App\Enums\LeadStatus;
use App\Models\Design;
use App\Models\Lead;
use App\Models\User;
use App\Services\DesignService;
use Database\Seeders\RoleSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => $this->seed(RoleSeeder::class));

test('designer can open a design brief for a DEAL_DESAIN lead', function () {
    $designer = User::factory()->create();
    $designer->assignRole('DESIGNER');
    $lead = Lead::factory()->create(['status' => LeadStatus::DealDesain->value]);

    $response = $this->actingAs($designer)->post(route('crm.leads.design.store', ['lead' => $lead->id]), [
        'pic_id' => $designer->id,
    ]);

    $response->assertRedirect();
    expect(Design::where('lead_id', $lead->id)->exists())->toBeTrue();
});

test('roles other than DESIGNER cannot open a design brief', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);
    $lead = Lead::factory()->create(['status' => LeadStatus::DealDesain->value]);

    $this->actingAs($user)->post(route('crm.leads.design.store', ['lead' => $lead->id]), [
        'pic_id' => $user->id,
    ])->assertForbidden();
})->with(['CEO', 'MARKETING', 'PM']);

test('design service refuses to open a brief for a lead that is not DEAL_DESAIN', function () {
    $lead = Lead::factory()->create(['status' => LeadStatus::FollowUp->value]);
    $pic = User::factory()->create();

    expect(fn () => app(DesignService::class)->create($lead, ['pic_id' => $pic->id]))
        ->toThrow(ValidationException::class);
});

test('design service refuses a second design brief for the same lead', function () {
    $lead = Lead::factory()->create(['status' => LeadStatus::DealDesain->value]);
    Design::factory()->create(['lead_id' => $lead->id]);
    $pic = User::factory()->create();

    expect(fn () => app(DesignService::class)->create($lead, ['pic_id' => $pic->id]))
        ->toThrow(ValidationException::class);
});
