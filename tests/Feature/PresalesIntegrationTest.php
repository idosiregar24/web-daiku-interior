<?php

use App\Enums\DesignStatus;
use App\Enums\LeadStatus;
use App\Enums\QuotationStatus;
use App\Models\BankAccount;
use App\Models\Design;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Project;
use App\Models\ProjectOpening;
use App\Models\Quotation;
use App\Models\User;
use App\Services\DesignService;
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
    $head = User::factory()->create();
    $head->assignRole(User::rolesFor('KEPALA_DESAIN'));
    $finance = User::factory()->create();
    $finance->assignRole('FINANCE');
    $account = BankAccount::factory()->create(['is_active' => true]);
    $estimator = User::factory()->create();
    $estimator->assignRole('ESTIMATOR');
    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');
    $pm = User::factory()->create();
    $pm->assignRole('PM');

    // 1. Marketing creates a lead.
    $this->actingAs($marketing)->post(route('crm.leads.store'), [
        'client_name' => 'Budi Santoso',
        'phone' => '081200000000',
        'lead_source_id' => LeadSource::findOrCreateByName('Instagram')->id,
        'priority' => 'HOT',
        'assigned_to' => $marketing->id,
    ])->assertRedirect();

    $lead = Lead::where('client_name', 'Budi Santoso')->firstOrFail();
    expect($lead->status)->toBe(LeadStatus::FollowUp);

    // 2. Sprint 12: Marketing asks for a RAB Jasa Desain — the lead moves to
    // "Pengajuan Desain/Survey" (DEAL_DESAIN).
    $this->actingAs($marketing)->post(route('crm.leads.submitRequest', $lead), [
        'type' => 'RAB_DESAIN',
        'note' => 'Desain kitchen set + ruang makan.',
    ])->assertSessionHasNoErrors();

    expect($lead->fresh()->status)->toBe(LeadStatus::DealDesain);
    $designRab = Quotation::where('lead_id', $lead->id)->where('type', 'DESAIN')->sole();

    // 3. Estimator builds it, the PM approves (no CEO for a service RAB),
    // Marketing sends it and the client approves on the link → the design
    // is opened, locked until paid.
    $this->actingAs($estimator)->post(route('quotations.start', $designRab))->assertSessionHasNoErrors();
    $this->actingAs($estimator)->put(route('quotations.items.update', $designRab), [
        'items' => [['description' => 'Jasa Desain Interior', 'qty' => 1, 'unit_id' => unitId('ls'), 'unit_price' => 5_000_000]],
    ])->assertSessionHasNoErrors();
    $this->actingAs($estimator)->post(route('quotations.submit', $designRab))->assertSessionHasNoErrors();
    $this->actingAs($pm)->post(route('quotations.review', $designRab), presalesReview($designRab, 'approve'))->assertSessionHasNoErrors();
    $this->actingAs($estimator)->post(route('quotations.sendToMarketing', $designRab))->assertSessionHasNoErrors();
    $this->actingAs($marketing)->post(route('quotations.sendToClient', $designRab))->assertSessionHasNoErrors();
    $this->post(route('public.quotation.approve', $designRab->fresh()->currentShareLink()->token), ['agree' => true])
        ->assertSessionHasNoErrors();

    $design = Design::where('lead_id', $lead->id)->sole();
    expect($design->status)->toBe(DesignStatus::MenungguBayar)
        ->and($design->quotation_id)->toBe($designRab->id);

    // 4. Marketing invoices it, the client pays, Finance verifies → unlocked.
    $this->actingAs($marketing)->post(route('quotations.invoices.store', $designRab), ['due_date' => now()->addDays(3)->toDateString()])
        ->assertSessionHasNoErrors();
    $invoice = $designRab->invoices()->sole();
    $this->actingAs($marketing)->post(route('finance.invoices.proof', $invoice), ['payment_proof_url' => 'https://drive.google.com/bukti'])
        ->assertSessionHasNoErrors();
    $this->actingAs($finance)->post(route('finance.invoices.verify', $invoice), [
        'bank_account_id' => $account->id,
        'paid_date' => now()->toDateString(),
    ])->assertSessionHasNoErrors();

    expect($design->fresh()->status)->toBe(DesignStatus::MenungguPenugasan);

    // 5. The Kepala Desain assigns the architect; they upload the design;
    // Marketing sends it, asks one revision, resends, and the client approves.
    $this->actingAs($head)->post(route('design.assign', $design), [
        'pic_id' => $designer->id,
        'start_date' => now()->toDateString(),
        'target_hari' => 10,
    ])->assertSessionHasNoErrors();
    $this->actingAs($designer)->put(route('design.update', $design), ['design_urls' => ['https://figma.com/file/v1']])
        ->assertSessionHasNoErrors();
    $this->actingAs($marketing)->post(route('design.sendToClient', $design))->assertSessionHasNoErrors();
    $this->actingAs($marketing)->post(route('design.requestRevision', $design), ['note' => 'Meja makan untuk 8 orang.'])
        ->assertSessionHasNoErrors();
    $this->actingAs($designer)->put(route('design.update', $design), ['design_urls' => ['https://figma.com/file/v2']])
        ->assertSessionHasNoErrors();
    $this->actingAs($marketing)->post(route('design.sendToClient', $design))->assertSessionHasNoErrors();
    $this->actingAs($marketing)->post(route('design.markClientApproved', $design))->assertSessionHasNoErrors();

    $design->refresh();
    expect($design->client_acc)->toBeTrue()
        ->and($design->status)->toBe(DesignStatus::AccDesain)
        ->and($design->revision_count)->toBe(1);

    // The Estimator is asked for the RAB Proyek built on that design.
    $quotation = Quotation::where('lead_id', $lead->id)->where('type', 'PROYEK')->sole();
    expect($quotation->status)->toBe(QuotationStatus::Diminta);
    $this->actingAs($estimator)->post(route('quotations.start', $quotation))->assertSessionHasNoErrors();

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
        ->and(Project::where('lead_id', $lead->id)->exists())->toBeTrue()
        // A Sprint 12 design's own work ended at the client's approval.
        ->and($design->fresh()->status)->toBe(DesignStatus::AccDesain);
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
        'phone' => '081211112222',
        'lead_source_id' => LeadSource::findOrCreateByName('Instagram')->id,
        'priority' => 'HOT',
        'assigned_to' => $marketing->id,
    ])->assertRedirect();
    $lead = Lead::where('client_name', 'Sari Wulandari')->firstOrFail();

    $this->actingAs($marketing)->patch(route('crm.leads.updateStatus', ['lead' => $lead->id]), ['status' => 'DEAL_DESAIN'])
        ->assertRedirect();
    // A pre-Sprint-12 design (opened by hand before Sub 8 removed that) —
    // it still follows the quotation through the old pipeline stages.
    $design = app(DesignService::class)->create($lead->fresh(), ['pic_id' => $designer->id]);

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
