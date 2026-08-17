<?php

use App\Enums\DesignStatus;
use App\Enums\LeadStatus;
use App\Enums\QuotationStatus;
use App\Models\Design;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\RoleSeeder;

/**
 * "Code review Jonathan Sigalingging + integration test alur presales
 * end-to-end" (.claude/plan/sprint-03.md Week 6, Catatan CSV:
 * Lead→Design→Quotation→Deal) — walks the whole chain in one test instead
 * of only the per-transition unit/feature tests already covering each
 * step individually.
 */
beforeEach(fn () => $this->seed(RoleSeeder::class));

test('the full presales flow — Lead to Design to Quotation to Deal — works end to end', function () {
    $marketing = User::factory()->create();
    $marketing->assignRole('MARKETING');
    $designer = User::factory()->create();
    $designer->assignRole('DESIGNER');
    $estimator = User::factory()->create();
    $estimator->assignRole('ESTIMATOR');
    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');
    $pm = User::factory()->create();
    $pm->assignRole('PM');

    // 1. Marketing creates a lead.
    $this->actingAs($marketing)->post(route('crm.leads.store'), [
        'client_name' => 'Budi Santoso',
        'contact' => '0812-0000-0000',
        'source' => 'Instagram',
        'priority' => 'HOT',
        'assigned_to' => $marketing->id,
    ])->assertRedirect();

    $lead = Lead::where('client_name', 'Budi Santoso')->firstOrFail();
    expect($lead->status)->toBe(LeadStatus::FollowUp);

    // 2. Marketing moves the lead to DEAL_DESAIN.
    $this->actingAs($marketing)->patch(route('crm.leads.updateStatus', ['lead' => $lead->id]), [
        'status' => 'DEAL_DESAIN',
    ])->assertRedirect();

    expect($lead->fresh()->status)->toBe(LeadStatus::DealDesain);

    // 3. Designer opens a design brief for the lead.
    $this->actingAs($designer)->post(route('crm.leads.design.store', ['lead' => $lead->id]), [
        'pic_id' => $designer->id,
    ])->assertRedirect();

    $design = Design::where('lead_id', $lead->id)->firstOrFail();
    expect($design->status)->toBe(DesignStatus::Brief);

    // 4. Designer works the brief through to WAITING_ACC_DESAIN.
    $this->actingAs($designer)->put(route('design.update', ['design' => $design->id]), [
        'pic_id' => $designer->id,
        'status' => DesignStatus::WaitingAccDesain->value,
    ])->assertRedirect();

    expect($design->fresh()->status)->toBe(DesignStatus::WaitingAccDesain);

    // 5. Marketing confirms the client ACC'd the design — this both moves
    // the design to GAMBAR_RAB and opens a Quotation (DesignService::clientAcc()).
    $this->actingAs($marketing)->post(route('design.clientAcc', ['design' => $design->id]))
        ->assertRedirect();

    $design->refresh();
    expect($design->client_acc)->toBeTrue()
        ->and($design->status)->toBe(DesignStatus::GambarRab);

    $quotation = Quotation::where('lead_id', $lead->id)->firstOrFail();
    expect($quotation->status)->toBe(QuotationStatus::Draft);

    // 6. Estimator builds the RAB and submits it for review.
    $this->actingAs($estimator)->put(route('quotations.items.update', ['quotation' => $quotation->id]), [
        'items' => [
            ['description' => 'Kitchen Set Custom', 'qty' => 1, 'unit' => 'set', 'unit_price' => 15_000_000],
            ['description' => 'Meja Makan', 'qty' => 2, 'unit' => 'unit', 'unit_price' => 3_000_000],
        ],
    ])->assertRedirect();

    expect((float) $quotation->fresh()->total_amount)->toBe(21_000_000.0);

    $this->actingAs($estimator)->post(route('quotations.submit', ['quotation' => $quotation->id]))
        ->assertRedirect();

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Submitted);

    // 7. CEO approves, then PM approves — sequential dual approval.
    $this->actingAs($ceo)->post(route('quotations.ceoDecision', ['quotation' => $quotation->id]), [
        'decision' => 'approve',
    ])->assertRedirect();

    expect($quotation->fresh()->status)->toBe(QuotationStatus::CeoReview);

    $this->actingAs($pm)->post(route('quotations.pmDecision', ['quotation' => $quotation->id]), [
        'decision' => 'approve',
    ])->assertRedirect();

    expect($quotation->fresh()->status)->toBe(QuotationStatus::SentToClient);

    // 8. Marketing confirms the deal — closes the lead and creates the
    // execution Project (LeadService::confirmDeal()).
    $this->actingAs($marketing)->post(route('crm.leads.confirmDeal', ['lead' => $lead->id]), [
        'name' => 'Proyek Budi Santoso',
        'pm_id' => $pm->id,
        'start_date' => now()->toDateString(),
        'contract_value' => 21_000_000,
    ])->assertRedirect(route('crm.leads.index'));

    expect($lead->fresh()->status)->toBe(LeadStatus::Closing)
        ->and(Project::where('lead_id', $lead->id)->exists())->toBeTrue();
});
