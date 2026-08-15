<?php

use App\Enums\DesignStatus;
use App\Enums\LeadStatus;
use App\Models\Design;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\User;
use App\Services\DesignService;
use Database\Seeders\RoleSeeder;
use Illuminate\Validation\ValidationException;

beforeEach(fn () => $this->seed(RoleSeeder::class));

test('roles with read access can view the design index', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);
    Design::factory()->create();

    $this->actingAs($user)->get(route('design.index'))->assertOk();
})->with(['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'QA']);

test('roles without access are forbidden from the design index', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('design.index'))->assertForbidden();
})->with(['FINANCE', 'LOGISTICS', 'FIELD_STAFF']);

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

test('design service calculates deadline from start_date + target_hari', function () {
    $lead = Lead::factory()->create(['status' => LeadStatus::DealDesain->value]);
    $pic = User::factory()->create();

    $design = app(DesignService::class)->create($lead, [
        'pic_id' => $pic->id,
        'start_date' => '2026-08-01',
        'target_hari' => 10,
    ]);

    expect($design->deadline->toDateString())->toBe('2026-08-11');
});

test('roles with read access can view a design', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);
    $design = Design::factory()->create();

    $this->actingAs($user)->get(route('design.show', ['design' => $design->id]))->assertOk();
})->with(['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'QA']);

test('roles without access are forbidden from viewing a design', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);
    $design = Design::factory()->create();

    $this->actingAs($user)->get(route('design.show', ['design' => $design->id]))->assertForbidden();
})->with(['FINANCE', 'LOGISTICS', 'FIELD_STAFF']);

test('designer can update the design brief', function () {
    $designer = User::factory()->create();
    $designer->assignRole('DESIGNER');
    $pic = User::factory()->create();
    $design = Design::factory()->create(['status' => DesignStatus::Brief->value]);

    $this->actingAs($designer)->put(route('design.update', ['design' => $design->id]), [
        'pic_id' => $pic->id,
        'status' => DesignStatus::Desain->value,
        'brief_note' => 'Klien ingin nuansa minimalis.',
        'design_urls' => ['https://figma.com/file/abc'],
    ])->assertRedirect();

    $design->refresh();
    expect($design->pic_id)->toBe($pic->id)
        ->and($design->status)->toBe(DesignStatus::Desain)
        ->and($design->brief_note)->toBe('Klien ingin nuansa minimalis.')
        ->and($design->design_urls)->toBe(['https://figma.com/file/abc']);
});

test('roles other than DESIGNER cannot update the design brief', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');
    $pic = User::factory()->create();
    $design = Design::factory()->create();

    $this->actingAs($ceo)->put(route('design.update', ['design' => $design->id]), [
        'pic_id' => $pic->id,
        'status' => DesignStatus::Desain->value,
    ])->assertForbidden();
});

test('marketing can confirm client ACC once a design reaches WAITING_ACC_DESAIN, opening a quotation', function () {
    $marketing = User::factory()->create();
    $marketing->assignRole('MARKETING');
    $design = Design::factory()->create(['status' => DesignStatus::WaitingAccDesain->value]);

    $response = $this->actingAs($marketing)->post(route('design.clientAcc', ['design' => $design->id]));

    $design->refresh();
    expect($design->client_acc)->toBeTrue()
        ->and($design->status)->toBe(DesignStatus::GambarRab)
        ->and(Quotation::where('lead_id', $design->lead_id)->exists())->toBeTrue();

    $quotation = Quotation::where('lead_id', $design->lead_id)->first();
    $response->assertRedirect(route('quotations.show', $quotation));
});

test('client ACC is rejected unless the design is WAITING_ACC_DESAIN', function () {
    $marketing = User::factory()->create();
    $marketing->assignRole('MARKETING');
    $design = Design::factory()->create(['status' => DesignStatus::Desain->value]);

    $this->actingAs($marketing)->post(route('design.clientAcc', ['design' => $design->id]))
        ->assertSessionHasErrors('client_acc');

    expect($design->fresh()->client_acc)->toBeFalse();
});

test('roles other than MARKETING and DESIGNER cannot confirm client ACC', function () {
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $design = Design::factory()->create(['status' => DesignStatus::WaitingAccDesain->value]);

    $this->actingAs($pm)->post(route('design.clientAcc', ['design' => $design->id]))->assertForbidden();
});
