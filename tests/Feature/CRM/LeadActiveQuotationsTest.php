<?php

use App\Enums\QuotationStatus;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 19 Sub 05 (M5, K4) — the lead page's "RAB" stage card links the
 * running RAB of any kind, not only the RAB Proyek.
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->marketing = User::factory()->create();
    $this->marketing->assignRole('MARKETING');
});

test('a lead with only a RAB Jasa Survey gets that RAB as its active quotation', function () {
    $lead = Lead::factory()->create();
    $survey = Quotation::factory()->sentToClient()->create([
        'lead_id' => $lead->id,
        'type' => 'SURVEY',
        'total_amount' => 500_000,
        'valid_until' => '2026-10-20',
    ]);

    $this->actingAs($this->marketing)->get(route('crm.leads.show', ['lead' => $lead->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('CRM/Show')
            ->where('lead.quotation', null)
            ->has('activeQuotations', 1)
            ->where('activeQuotations.0.id', $survey->id)
            ->where('activeQuotations.0.type', 'SURVEY')
            ->where('activeQuotations.0.title', 'RAB Jasa Survey')
            ->where('activeQuotations.0.version', 1)
            ->where('activeQuotations.0.status', QuotationStatus::SentToClient->value)
            ->where('activeQuotations.0.total_amount', '500000.00')
            ->where('activeQuotations.0.valid_until', '2026-10-20')
        );
});

test('active quotations skip cancelled RABs and addenda, newest per kind, Proyek first', function () {
    $lead = Lead::factory()->create();
    $oldSurvey = Quotation::factory()->create(['lead_id' => $lead->id, 'type' => 'SURVEY']);
    $survey = Quotation::factory()->approved()->create(['lead_id' => $lead->id, 'type' => 'SURVEY', 'version' => 2]);
    Quotation::factory()->create(['lead_id' => $lead->id, 'type' => 'DESAIN', 'status' => QuotationStatus::Cancelled->value]);
    $proyek = Quotation::factory()->approved()->create(['lead_id' => $lead->id, 'type' => 'PROYEK']);
    Quotation::factory()->create(['lead_id' => $lead->id, 'type' => 'PROYEK', 'parent_quotation_id' => $proyek->id]);

    $this->actingAs($this->marketing)->get(route('crm.leads.show', ['lead' => $lead->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('activeQuotations', 2)
            ->where('activeQuotations.0.id', $proyek->id)
            ->where('activeQuotations.0.title', 'RAB Proyek')
            ->where('activeQuotations.1.id', $survey->id)
            ->where('activeQuotations.1.version', 2)
            // "Riwayat RAB" still lists every version, the old one and the addendum included.
            ->has('lead.quotations', 5)
        );

    expect($oldSurvey->id)->toBeLessThan($survey->id);
});

test('a lead with a RAB Proyek keeps lead.quotation as before', function () {
    $lead = Lead::factory()->create();
    Quotation::factory()->create(['lead_id' => $lead->id, 'type' => 'SURVEY']);
    $proyek = Quotation::factory()->sentToClient()->create(['lead_id' => $lead->id, 'type' => 'PROYEK', 'custom_name' => 'Kitchen Set']);

    $this->actingAs($this->marketing)->get(route('crm.leads.show', ['lead' => $lead->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('lead.quotation.id', $proyek->id)
            ->where('lead.quotation.type', 'PROYEK')
            ->where('activeQuotations.0.id', $proyek->id)
            ->where('activeQuotations.0.title', 'RAB Kitchen Set')
            ->where('activeQuotations.1.type', 'SURVEY')
        );
});

test('a lead without any RAB gets no active quotation', function () {
    $lead = Lead::factory()->create();
    Quotation::factory()->create(['lead_id' => $lead->id, 'type' => 'SURVEY', 'status' => QuotationStatus::Cancelled->value]);

    $this->actingAs($this->marketing)->get(route('crm.leads.show', ['lead' => $lead->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('activeQuotations', 0));
});
