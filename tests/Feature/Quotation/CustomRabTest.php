<?php

use App\Enums\LeadStatus;
use App\Enums\QuotationStatus;
use App\Enums\QuotationType;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 14 Sub 02 — "Buat RAB → Lainnya": a RAB Proyek with its own name.
 * Same flow as any RAB Proyek; the name is what everyone (and the client) sees.
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->marketing = customRabUser('MARKETING');
    $this->estimator = customRabUser('ESTIMATOR');
    $this->lead = Lead::factory()->create(['assigned_to' => $this->marketing->id, 'status' => LeadStatus::DealDesain->value]);
});

function customRabUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function requestCustomRab(object $test, ?string $name = 'Renovasi Pagar')
{
    return $test->actingAs($test->marketing)->post(route('crm.leads.submitRequest', $test->lead), [
        'type' => 'RAB_LAINNYA',
        'custom_name' => $name,
        'note' => 'Pagar depan 12 m, besi hollow + cat.',
    ]);
}

test('a custom-named RAB is a RAB Proyek with its own title everywhere', function () {
    requestCustomRab($this)
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'RAB Renovasi Pagar diminta — Estimator mendapat notifikasi.');

    $quotation = Quotation::where('lead_id', $this->lead->id)->sole();

    expect($quotation->type)->toBe(QuotationType::Proyek)
        ->and($quotation->custom_name)->toBe('Renovasi Pagar')
        ->and($quotation->status)->toBe(QuotationStatus::Diminta)
        ->and($quotation->title())->toBe('RAB Renovasi Pagar')
        ->and(Notification::where('user_id', $this->estimator->id)->value('title'))->toBe('Permintaan RAB Renovasi Pagar');
});

test('a name already starting with "RAB" is not prefixed twice', function () {
    requestCustomRab($this, 'RAB Maintenance AC');

    expect(Quotation::sole()->title())->toBe('RAB Maintenance AC');
});

test('"Lainnya" needs a name', function (?string $name) {
    requestCustomRab($this, $name)->assertSessionHasErrors('custom_name');

    expect(Quotation::count())->toBe(0);
})->with([null, '', 'ab']);

test('a custom name is ignored on the fixed RAB types', function () {
    $this->actingAs($this->marketing)->post(route('crm.leads.submitRequest', $this->lead), [
        'type' => 'RAB_DESAIN',
        'custom_name' => 'Tidak dipakai',
        'note' => 'Desain dapur.',
    ])->assertSessionHasNoErrors();

    expect(Quotation::sole())->custom_name->toBeNull()->title()->toBe('RAB Jasa Desain');
});

test('like any RAB Proyek, only one runs per client at a time', function () {
    requestCustomRab($this);

    $this->actingAs($this->marketing)->post(route('crm.leads.submitRequest', $this->lead), [
        'type' => 'RAB_PROYEK',
        'note' => 'Kitchen set.',
    ])->assertSessionHasErrors(['type' => 'Masih ada RAB Renovasi Pagar yang berjalan untuk lead ini — selesaikan atau batalkan dulu.']);
});

test('the lead page lists every RAB for the history card and suggests names used before', function () {
    Quotation::factory()->create(['custom_name' => 'Maintenance AC']);
    requestCustomRab($this);
    Quotation::factory()->create(['lead_id' => $this->lead->id, 'type' => 'SURVEY', 'status' => QuotationStatus::Cancelled->value]);

    $this->actingAs($this->marketing)
        ->get(route('crm.leads.show', $this->lead))
        ->assertInertia(fn (Assert $page) => $page
            ->has('lead.quotations', 2)
            ->where('lead.quotations.1.custom_name', 'Renovasi Pagar')
            ->where('customRabNames', ['Maintenance AC', 'Renovasi Pagar']));
});

test('the client link, the quotation list filter and the search use the custom title', function () {
    requestCustomRab($this);
    $quotation = Quotation::sole();
    $quotation->update(['status' => QuotationStatus::SentToClient->value, 'total_amount' => 12_000_000]);
    Quotation::factory()->create(['type' => 'PROYEK']);

    $this->get(route('public.quotation.show', shareLinkFor($quotation)->token))
        ->assertInertia(fn (Assert $page) => $page->where('quotation.typeLabel', 'RAB Renovasi Pagar'));

    $this->actingAs($this->marketing)
        ->get(route('quotations.index', ['type' => Quotation::FILTER_CUSTOM]))
        ->assertInertia(fn (Assert $page) => $page->where('quotations.total', 1)->where('quotations.data.0.custom_name', 'Renovasi Pagar'));

    $this->actingAs($this->marketing)
        ->getJson(route('search', ['q' => $this->lead->client_name]))
        ->assertJsonFragment(['sublabel' => 'RAB Renovasi Pagar v1']);
});
