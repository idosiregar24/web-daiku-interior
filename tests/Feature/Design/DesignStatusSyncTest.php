<?php

use App\Enums\DesignStatus;
use App\Enums\LeadStatus;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\QuotationStatus;
use App\Models\Design;
use App\Models\Lead;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\QaForm;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Services\DesignService;
use App\Services\LeadService;
use App\Services\QaFormService;
use App\Services\QuotationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;

/**
 * Sprint 9 decision #4 — the post-ACC stages can't be picked by hand
 * before Client ACC, and the design follows its quotation / deal /
 * project forward on its own (DesignService::syncWithPipeline()).
 */
beforeEach(fn () => $this->seed(RoleSeeder::class));

function syncUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** An ACC'd design in `$status` with its lead's quotation in `$quotationStatus` (one RAB item). */
function accdDesignWithQuotation(DesignStatus $status, QuotationStatus $quotationStatus = QuotationStatus::Draft): array
{
    $lead = Lead::factory()->create(['status' => LeadStatus::DealDesain->value]);
    $design = Design::factory()->create([
        'lead_id' => $lead->id,
        'client_acc' => true,
        'acc_date' => now()->toDateString(),
        'status' => $status->value,
    ]);
    $quotation = Quotation::factory()->create(['lead_id' => $lead->id, 'status' => $quotationStatus->value]);
    QuotationItem::factory()->create(['quotation_id' => $quotation->id]);

    return [$lead, $design, $quotation];
}

// ── Manual updates can't skip Client ACC ────────────────────────────────

test('a designer cannot pick a post-ACC stage before Client ACC', function (DesignStatus $status) {
    $designer = syncUser('DESIGNER');
    $design = Design::factory()->create(['pic_id' => $designer->id, 'status' => DesignStatus::Desain->value]);

    $this->actingAs($designer)->put(route('design.update', ['design' => $design->id]), [
        'pic_id' => $designer->id,
        'status' => $status->value,
    ])->assertSessionHasErrors('status');

    expect($design->fresh()->status)->toBe(DesignStatus::Desain);
})->with(DesignService::STATUSES_REQUIRING_CLIENT_ACC);

test('pre-ACC and client-paused stages are always allowed', function (DesignStatus $status) {
    $designer = syncUser('DESIGNER');
    $design = Design::factory()->create(['pic_id' => $designer->id, 'status' => DesignStatus::Brief->value]);

    $this->actingAs($designer)->put(route('design.update', ['design' => $design->id]), [
        'pic_id' => $designer->id,
        'status' => $status->value,
    ])->assertSessionHasNoErrors();

    expect($design->fresh()->status)->toBe($status);
})->with([
    DesignStatus::Desain,
    DesignStatus::WaitingAccDesain,
    DesignStatus::RevisiDesain,
    DesignStatus::HoldClient,
    DesignStatus::RevisiClient,
]);

test('after Client ACC any stage can be set, and going back to DESAIN keeps client_acc', function () {
    $designer = syncUser('DESIGNER');
    [, $design, $quotation] = accdDesignWithQuotation(DesignStatus::Produksi, QuotationStatus::ClientApproved);

    $this->actingAs($designer)->put(route('design.update', ['design' => $design->id]), [
        'pic_id' => $designer->id,
        'status' => DesignStatus::RejectProduksi->value,
    ])->assertSessionHasNoErrors();

    // PRD §4.2 "REJECT_PRODUKSI artinya proyek kembali ke tahap desain ulang".
    $this->actingAs($designer)->put(route('design.update', ['design' => $design->id]), [
        'pic_id' => $designer->id,
        'status' => DesignStatus::Desain->value,
    ])->assertSessionHasNoErrors();

    $design->refresh();
    expect($design->status)->toBe(DesignStatus::Desain)
        ->and($design->client_acc)->toBeTrue()
        ->and(Quotation::where('lead_id', $design->lead_id)->count())->toBe(1);
});

test('re-saving a brief whose (legacy) status predates the rule is not blocked', function () {
    $designer = syncUser('DESIGNER');
    $design = Design::factory()->create([
        'pic_id' => $designer->id,
        'client_acc' => false,
        'status' => DesignStatus::GambarRab->value,
    ]);

    $this->actingAs($designer)->put(route('design.update', ['design' => $design->id]), [
        'pic_id' => $designer->id,
        'status' => DesignStatus::GambarRab->value,
        'brief_note' => 'Hanya ubah catatan.',
    ])->assertSessionHasNoErrors();

    expect($design->fresh()->brief_note)->toBe('Hanya ubah catatan.');
});

// ── Automatic advance ───────────────────────────────────────────────────

test('saving RAB items moves the design to PEMBUATAN_PENAWARAN', function () {
    [, $design, $quotation] = accdDesignWithQuotation(DesignStatus::GambarRab);

    app(QuotationService::class)->replaceItems($quotation, [
        ['description' => 'Kitchen Set', 'qty' => 1, 'unit_id' => unitId('set'), 'unit_price' => 1_000_000],
    ]);

    expect($design->fresh()->status)->toBe(DesignStatus::PembuatanPenawaran);
});

test('submitting the quotation also moves the design to PEMBUATAN_PENAWARAN', function () {
    [, $design, $quotation] = accdDesignWithQuotation(DesignStatus::GambarRab);

    app(QuotationService::class)->submit($quotation);

    expect($design->fresh()->status)->toBe(DesignStatus::PembuatanPenawaran);
});

test('sending the RAB to the client (SENT_TO_CLIENT) moves the design to WAITING_ACC_PENAWARAN', function () {
    [, $design, $quotation] = accdDesignWithQuotation(DesignStatus::PembuatanPenawaran, QuotationStatus::ReadyToSend);

    app(QuotationService::class)->sendToClient($quotation, syncUser('MARKETING'));

    expect($design->fresh()->status)->toBe(DesignStatus::WaitingAccPenawaran);
});

test('PM / CEO returns leave the design where it is', function () {
    [, $design, $quotation] = accdDesignWithQuotation(DesignStatus::PembuatanPenawaran, QuotationStatus::Submitted);

    reviewQuotation($quotation, syncUser('PM'), [], 'Revisi harga.');

    expect($design->fresh()->status)->toBe(DesignStatus::PembuatanPenawaran);
});

test('a client rejection only steps back from WAITING_ACC_PENAWARAN', function (DesignStatus $from, DesignStatus $expected) {
    [, $design, $quotation] = accdDesignWithQuotation($from, QuotationStatus::SentToClient);

    app(QuotationService::class)->clientReject($quotation, syncUser('MARKETING'), 'Minta revisi.');

    expect($design->fresh()->status)->toBe($expected);
})->with([
    'waiting → back' => [DesignStatus::WaitingAccPenawaran, DesignStatus::PembuatanPenawaran],
    'set further by hand → kept' => [DesignStatus::Produksi, DesignStatus::Produksi],
    'hold → kept' => [DesignStatus::HoldClient, DesignStatus::HoldClient],
]);

test('confirming the deal moves the design to PRODUKSI', function () {
    [$lead, $design] = accdDesignWithQuotation(DesignStatus::WaitingAccPenawaran, QuotationStatus::SentToClient);

    app(LeadService::class)->confirmDeal($lead, [
        'name' => 'Proyek Sync',
        'pm_id' => syncUser('PM')->id,
        'start_date' => now()->toDateString(),
        'contract_value' => 10_000_000,
    ], syncUser('MARKETING'));

    expect($design->fresh()->status)->toBe(DesignStatus::Produksi);
});

test('the project passing its last QA moves the design to DONE_PRODUKSI and freezes the delay', function () {
    [$lead, $design] = accdDesignWithQuotation(DesignStatus::Produksi, QuotationStatus::ClientApproved);
    $design->update(['deadline' => now()->subDays(5)->toDateString(), 'delay_hari' => 5, 'delay_counted_on' => now()->toDateString()]);
    $project = Project::factory()->create(['lead_id' => $lead->id, 'pm_id' => syncUser('PM')->id]);
    $milestone = Milestone::factory()->create([
        'project_id' => $project->id,
        'status' => MilestoneStatus::QaWaiting->value,
        'order' => 1,
    ]);
    $qaForm = QaForm::factory()->create(['project_id' => $project->id, 'milestone_id' => $milestone->id]);

    app(QaFormService::class)->review($qaForm, 'approve', [['label' => 'OK', 'passed' => true, 'note' => null]], null, syncUser('QA'));

    expect($project->fresh()->status)->toBe(ProjectStatus::Completed)
        ->and($design->fresh()->status)->toBe(DesignStatus::DoneProduksi);

    app(DesignService::class)->recalculateDelays(Carbon::today()->addDays(10));

    expect($design->fresh()->delay_hari)->toBe(5);
});

test('automatic sync never overrides REJECT_PRODUKSI / HOLD_CLIENT / REVISI_CLIENT', function (DesignStatus $paused) {
    [$lead, $design] = accdDesignWithQuotation($paused);

    app(DesignService::class)->syncWithPipeline($lead->id, DesignService::EVENT_DEAL_CONFIRMED);
    app(DesignService::class)->syncWithPipeline($lead->id, DesignService::EVENT_PROJECT_COMPLETED);

    expect($design->fresh()->status)->toBe($paused);
})->with([DesignStatus::RejectProduksi, DesignStatus::HoldClient, DesignStatus::RevisiClient]);

test('automatic sync only moves forward', function () {
    [$lead, $design, $quotation] = accdDesignWithQuotation(DesignStatus::Produksi);

    app(QuotationService::class)->replaceItems($quotation, [
        ['description' => 'Tambahan', 'qty' => 1, 'unit_id' => unitId('unit'), 'unit_price' => 500_000],
    ]);

    expect($design->fresh()->status)->toBe(DesignStatus::Produksi);
});

test('a design without Client ACC is never moved automatically', function () {
    $lead = Lead::factory()->create();
    $design = Design::factory()->create(['lead_id' => $lead->id, 'client_acc' => false, 'status' => DesignStatus::Desain->value]);

    app(DesignService::class)->syncWithPipeline($lead->id, DesignService::EVENT_QUOTATION_SENT);

    expect($design->fresh()->status)->toBe(DesignStatus::Desain);
});

test('a lead without a design is a no-op, an unknown event is a programming error', function () {
    $lead = Lead::factory()->create();

    expect(app(DesignService::class)->syncWithPipeline($lead->id, DesignService::EVENT_DEAL_CONFIRMED))->toBeNull()
        ->and(fn () => app(DesignService::class)->syncWithPipeline($lead->id, 'bogus'))->toThrow(InvalidArgumentException::class);
});
