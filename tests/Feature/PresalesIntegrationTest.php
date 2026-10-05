<?php

use App\Enums\DesignStatus;
use App\Enums\LeadStatus;
use App\Enums\QuotationStatus;
use App\Models\Design;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Project;
use App\Models\ProjectOpening;
use App\Models\Quotation;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * "Code review Jonathan Sigalingging + integration test alur presales
 * end-to-end" (.claude/plan/sprint-03.md Week 6, Catatan CSV:
 * Lead→Design→Quotation→Deal) — walks the whole chain in one test instead
 * of only the per-transition unit/feature tests already covering each
 * step individually.
 */
beforeEach(fn () => $this->seed(RoleSeeder::class));

/** Sprint 12 #8 — a review payload: approve with every item ✔, or return with only a note. */
function presalesReview(Quotation $quotation, string $decision, ?string $note = null): array
{
    return [
        'decision' => $decision,
        'note' => $note,
        'items' => $decision === 'approve' || $note === null
            ? $quotation->items()->get()->map(fn ($item) => ['item_id' => $item->id, 'verdict' => 'OK'])->all()
            : [],
    ];
}

/** Sprint 12 Sub 5, over HTTP: the client ticks and approves on the current link, then the CEO opens the project. */
function presalesClientApprovesAndProjectOpens(TestCase $test, Quotation $quotation, Lead $lead, User $ceo, User $pm, int $value): void
{
    $token = $quotation->fresh()->currentShareLink()->token;

    $test->get(route('public.quotation.show', $token))->assertOk();
    $test->post(route('public.quotation.approve', $token), ['agree' => true])->assertSessionHasNoErrors();

    $opening = ProjectOpening::where('quotation_id', $quotation->id)->sole();
    $test->actingAs($ceo)->post(route('projects.openings.open', $opening), [
        'name' => "Proyek {$lead->client_name}",
        'pm_id' => $pm->id,
        'start_date' => now()->toDateString(),
    ])->assertSessionHasNoErrors();

    expect((float) Project::where('lead_id', $lead->id)->sole()->contract_value)->toBe((float) $value);
}

/**
 * Sprint 12 #7–#10, over HTTP: PM reviews ✔ → CEO approves (RAB Proyek) →
 * Estimator sends the final RAB to Marketing → Marketing sends it to the client.
 */
function presalesApproveAndSend(TestCase $test, Quotation $quotation, User $pm, User $ceo, User $estimator, User $marketing): void
{
    $test->actingAs($pm)->post(route('quotations.review', $quotation), presalesReview($quotation, 'approve'))->assertSessionHasNoErrors();
    expect($quotation->fresh()->status)->toBe(QuotationStatus::WaitingCeo);

    $test->actingAs($ceo)->post(route('quotations.review', $quotation), ['decision' => 'approve'])->assertSessionHasNoErrors();
    expect($quotation->fresh()->status)->toBe(QuotationStatus::ApprovedInternal);

    $test->actingAs($estimator)->post(route('quotations.sendToMarketing', $quotation))->assertSessionHasNoErrors();
    $test->actingAs($marketing)->post(route('quotations.sendToClient', $quotation))->assertSessionHasNoErrors();
}

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
        'lead_source_id' => LeadSource::findOrCreateByName('Instagram')->id,
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
            ['description' => 'Kitchen Set Custom', 'qty' => 1, 'unit_id' => unitId('set'), 'unit_price' => 15_000_000],
            ['description' => 'Meja Makan', 'qty' => 2, 'unit_id' => unitId('unit'), 'unit_price' => 3_000_000],
        ],
    ])->assertRedirect();

    expect((float) $quotation->fresh()->total_amount)->toBe(21_000_000.0);

    $this->actingAs($estimator)->post(route('quotations.submit', ['quotation' => $quotation->id]))
        ->assertRedirect();

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Submitted);

    // 7. Sprint 12: PM reviews every item, then the CEO, then the
    // Estimator hands it to Marketing, who sends it to the client.
    presalesApproveAndSend($this, $quotation, $pm, $ceo, $estimator, $marketing);

    expect($quotation->fresh()->status)->toBe(QuotationStatus::SentToClient);

    // 8. The client approves on the link (the deal — lead CLOSING), then
    // the CEO opens the execution Project from the lead page.
    presalesClientApprovesAndProjectOpens($this, $quotation, $lead, $ceo, $pm, 21_000_000);

    expect($lead->fresh()->status)->toBe(LeadStatus::Closing)
        ->and(Project::where('lead_id', $lead->id)->exists())->toBeTrue();
});

test('the presales flow survives rejections — CEO return, client reject, revisions and re-approval — through to the deal', function () {
    Carbon::setTestNow(Carbon::parse('2026-09-30 09:00:00', 'Asia/Jakarta'));

    $marketing = User::factory()->create();
    $marketing->assignRole('MARKETING');
    $designer = User::factory()->create();
    $designer->assignRole('DESIGNER');
    $subStaff = User::factory()->create(['name' => 'Lika']);
    $subStaff->assignRole('DESIGNER');
    $estimator = User::factory()->create();
    $estimator->assignRole('ESTIMATOR');
    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');
    $pm = User::factory()->create();
    $pm->assignRole('PM');

    // 1. Lead → DEAL_DESAIN → design brief with a sub-staff member.
    $this->actingAs($marketing)->post(route('crm.leads.store'), [
        'client_name' => 'Sari Wulandari',
        'contact' => '0812-1111-2222',
        'lead_source_id' => LeadSource::findOrCreateByName('Instagram')->id,
        'priority' => 'HOT',
        'assigned_to' => $marketing->id,
    ])->assertRedirect();
    $lead = Lead::where('client_name', 'Sari Wulandari')->firstOrFail();

    $this->actingAs($marketing)->patch(route('crm.leads.updateStatus', ['lead' => $lead->id]), ['status' => 'DEAL_DESAIN'])
        ->assertRedirect();
    $this->actingAs($designer)->post(route('crm.leads.design.store', ['lead' => $lead->id]), ['pic_id' => $designer->id])
        ->assertRedirect();
    $design = Design::where('lead_id', $lead->id)->firstOrFail();

    // A post-ACC stage can't be picked before the client ACC'd.
    $this->actingAs($designer)->put(route('design.update', ['design' => $design->id]), [
        'pic_id' => $designer->id,
        'status' => DesignStatus::GambarRab->value,
    ])->assertSessionHasErrors('status');

    $this->actingAs($designer)->put(route('design.update', ['design' => $design->id]), [
        'pic_id' => $designer->id,
        'status' => DesignStatus::WaitingAccDesain->value,
        'staff' => [['user_id' => $subStaff->id, 'role_note' => '3D modeling']],
    ])->assertSessionHasNoErrors();
    expect($design->staff()->pluck('users.id')->all())->toBe([$subStaff->id]);

    // 2. Client ACC → quotation v1; RAB saved → design PEMBUATAN_PENAWARAN.
    $this->actingAs($marketing)->post(route('design.clientAcc', ['design' => $design->id]))->assertRedirect();
    $quotation = Quotation::where('lead_id', $lead->id)->firstOrFail();

    $this->actingAs($estimator)->put(route('quotations.items.update', ['quotation' => $quotation->id]), [
        'items' => [['description' => 'Kitchen Set Custom', 'qty' => 1, 'unit_id' => unitId('set'), 'unit_price' => 30_000_000]],
    ])->assertRedirect();
    expect($design->fresh()->status)->toBe(DesignStatus::PembuatanPenawaran);

    // 3. PM approves v1, the CEO returns it → revision #1, back to DRAFT as v2.
    $this->actingAs($estimator)->post(route('quotations.submit', ['quotation' => $quotation->id]))->assertRedirect();
    $this->actingAs($pm)->post(route('quotations.review', $quotation), presalesReview($quotation, 'approve'))->assertSessionHasNoErrors();
    $this->actingAs($ceo)->post(route('quotations.review', $quotation), presalesReview($quotation, 'return', 'Harga terlalu tinggi.'))
        ->assertSessionHasNoErrors();

    $quotation->refresh();
    expect($quotation->status)->toBe(QuotationStatus::Draft)
        ->and($quotation->version)->toBe(2)
        ->and($quotation->revisions()->pluck('reason', 'version')->all())->toBe([1 => 'CEO_REJECTED']);

    // 4. Estimator revises v2 → PM & CEO approve → sent and valid for 14
    // days; design WAITING_ACC_PENAWARAN.
    $this->actingAs($estimator)->put(route('quotations.items.update', ['quotation' => $quotation->id]), [
        'items' => [['description' => 'Kitchen Set Custom', 'qty' => 1, 'unit_id' => unitId('set'), 'unit_price' => 27_000_000]],
    ])->assertRedirect();
    $this->actingAs($estimator)->post(route('quotations.submit', ['quotation' => $quotation->id]))->assertRedirect();
    presalesApproveAndSend($this, $quotation, $pm, $ceo, $estimator, $marketing);

    $quotation->refresh();
    expect($quotation->status)->toBe(QuotationStatus::SentToClient)
        ->and($quotation->valid_until->toDateString())->toBe('2026-10-14')
        ->and($design->fresh()->status)->toBe(DesignStatus::WaitingAccPenawaran);

    // 5. The client rejects v2 → revision #2, DRAFT as v3, validity
    // cleared, design steps back to PEMBUATAN_PENAWARAN.
    $this->actingAs($marketing)->post(route('quotations.clientReject', ['quotation' => $quotation->id]), [
        'note' => 'Klien minta material diganti HPL.',
    ])->assertRedirect();

    $quotation->refresh();
    expect($quotation->status)->toBe(QuotationStatus::Draft)
        ->and($quotation->version)->toBe(3)
        ->and($quotation->valid_until)->toBeNull()
        ->and($design->fresh()->status)->toBe(DesignStatus::PembuatanPenawaran)
        // Newest first — Quotation::revisions()'s own order.
        ->and($quotation->revisions()->get()->map(fn ($revision) => [
            $revision->version, $revision->reason, (float) $revision->total_amount,
        ])->all())->toBe([
            [2, 'CLIENT_REJECTED', 27_000_000.0],
            [1, 'CEO_REJECTED', 30_000_000.0],
        ]);

    // 6. v3 goes through PM → CEO again (a day later) and the deal closes:
    // lead CLOSING, project created, design PRODUKSI.
    Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00', 'Asia/Jakarta'));

    $this->actingAs($estimator)->put(route('quotations.items.update', ['quotation' => $quotation->id]), [
        'items' => [['description' => 'Kitchen Set Custom (HPL)', 'qty' => 1, 'unit_id' => unitId('set'), 'unit_price' => 25_000_000]],
    ])->assertRedirect();
    $this->actingAs($estimator)->post(route('quotations.submit', ['quotation' => $quotation->id]))->assertRedirect();
    presalesApproveAndSend($this, $quotation, $pm, $ceo, $estimator, $marketing);

    $quotation->refresh();
    expect($quotation->status)->toBe(QuotationStatus::SentToClient)
        ->and($quotation->version)->toBe(3)
        ->and($quotation->valid_until->toDateString())->toBe('2026-10-15')
        ->and($quotation->approvals()->where('approver_role', 'CLIENT')->pluck('version')->all())->toBe([2]);

    // The link of version 2 now only says "sudah diperbarui"; version 3's is the one.
    $this->get(route('public.quotation.show', $quotation->shareLinks()->where('version', 2)->value('token')))
        ->assertInertia(fn (Assert $page) => $page->where('state', 'outdated'));

    presalesClientApprovesAndProjectOpens($this, $quotation, $lead, $ceo, $pm, 25_000_000);

    expect($lead->fresh()->status)->toBe(LeadStatus::Closing)
        ->and($quotation->fresh()->status)->toBe(QuotationStatus::ClientApproved)
        ->and(Project::where('lead_id', $lead->id)->exists())->toBeTrue()
        ->and($design->fresh()->status)->toBe(DesignStatus::Produksi)
        ->and($quotation->revisions()->count())->toBe(2);

    Carbon::setTestNow();
});
