<?php

use App\Enums\DesignStatus;
use App\Enums\InvoiceType;
use App\Enums\LeadStatus;
use App\Enums\QuotationStatus;
use App\Enums\QuotationType;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Design;
use App\Models\DesignRevision;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Quotation;
use App\Models\User;
use App\Services\DesignService;
use App\Services\InvoiceService;
use App\Services\QuotationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 12 Sub 8 — a design is born from an approved RAB Jasa Desain,
 * locked until Finance verifies its invoice, assigned by a Kepala Desain,
 * sent / revised / approved by Marketing, with an Arsitek ↔ Estimator
 * thread (decisions #15–#18, D5, D6).
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00:00', 'Asia/Jakarta'));
    $this->seed(RoleSeeder::class);
    $this->marketing = designFlowUser('MARKETING');
    $this->head = designFlowUser('KEPALA_DESAIN');
    $this->architect = designFlowUser('DESIGNER');
    $this->estimator = designFlowUser('ESTIMATOR');
    $this->lead = Lead::factory()->create(['status' => LeadStatus::DealDesain->value, 'assigned_to' => $this->marketing->id]);
});

afterEach(fn () => Carbon::setTestNow());

function designFlowUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole(User::rolesFor($role));

    return $user;
}

function designAt(object $test, DesignStatus $status, array $attributes = []): Design
{
    return Design::factory()->fromRabDesain($status)->create(['lead_id' => $test->lead->id, ...$attributes]);
}

/** A design being worked on by `$test->architect`, with a link uploaded. */
function workedDesign(object $test, DesignStatus $status = DesignStatus::Desain): Design
{
    return designAt($test, $status, [
        'pic_id' => $test->architect->id,
        'design_urls' => ['https://figma.com/file/abc'],
        'start_date' => '2026-10-01',
        'target_hari' => 10,
        'deadline' => '2026-10-11',
    ]);
}

// ── Opened by the client's approval, locked until paid ──────────────────

test('the client approving a RAB Jasa Desain opens the design locked and tells the Kepala Desain', function () {
    $quotation = Quotation::factory()->sentToClient()->create([
        'lead_id' => $this->lead->id,
        'type' => 'DESAIN',
        'valid_until' => now()->addDays(5)->toDateString(),
        'request_note' => 'Desain kamar anak.',
    ]);

    $this->post(route('public.quotation.approve', shareLinkFor($quotation, $this->marketing)->token), ['agree' => true])
        ->assertSessionHasNoErrors();

    $design = Design::sole();
    expect($design->status)->toBe(DesignStatus::MenungguBayar)
        ->and($design->quotation_id)->toBe($quotation->id)
        ->and($design->pic_id)->toBeNull()
        ->and($design->brief_note)->toBe('Desain kamar anak.')
        ->and(Notification::where('user_id', $this->head->id)->where('type', 'design_awaiting_payment')->exists())->toBeTrue()
        ->and(Notification::where('user_id', $this->architect->id)->exists())->toBeFalse();
});

test('a RAB Jasa Survey / Proyek approval opens no design, nor does a second one for a lead that has one', function () {
    $service = app(DesignService::class);

    expect($service->openFromQuotation(Quotation::factory()->create(['lead_id' => $this->lead->id, 'type' => 'SURVEY'])))->toBeNull()
        ->and($service->openFromQuotation(Quotation::factory()->create(['lead_id' => $this->lead->id, 'type' => 'PROYEK'])))->toBeNull();

    $existing = Design::factory()->create(['lead_id' => $this->lead->id]);
    expect($service->openFromQuotation(Quotation::factory()->create(['lead_id' => $this->lead->id, 'type' => 'DESAIN'])))->toBeNull()
        ->and(Design::sole()->is($existing))->toBeTrue();
});

test('Finance verifying the Jasa Desain invoice unlocks the design for assignment', function () {
    $design = designAt($this, DesignStatus::MenungguBayar);
    $invoices = app(InvoiceService::class);
    $invoice = $invoices->issueForQuotation($design->quotation, ['due_date' => '2026-10-08'], $this->marketing);
    $invoices->submitProof($invoice, 'https://drive.google.com/bukti', $this->marketing);

    expect($design->fresh()->status)->toBe(DesignStatus::MenungguBayar);

    $invoices->verify($invoice, ['bank_account_id' => BankAccount::factory()->create(['is_active' => true])->id, 'paid_date' => '2026-10-05'], designFlowUser('FINANCE'));

    expect($design->fresh()->status)->toBe(DesignStatus::MenungguPenugasan)
        ->and(Notification::where('user_id', $this->head->id)->where('type', 'design_ready_to_assign')->exists())->toBeTrue();
});

test('only a Jasa Desain invoice unlocks a design', function () {
    $design = designAt($this, DesignStatus::MenungguBayar);
    $invoice = new Invoice(['quotation_id' => $design->quotation_id, 'type' => InvoiceType::JasaSurvey->value]);

    expect(app(DesignService::class)->unlockAfterPayment($invoice))->toBeNull()
        ->and($design->fresh()->status)->toBe(DesignStatus::MenungguBayar);
});

// ── Kepala Desain assigns ───────────────────────────────────────────────

test('a design cannot be assigned before its payment is verified', function () {
    $design = designAt($this, DesignStatus::MenungguBayar);

    $this->actingAs($this->head)->post(route('design.assign', $design), [
        'pic_id' => $this->architect->id,
        'start_date' => '2026-10-06',
        'target_hari' => 10,
    ])->assertSessionHasErrors(['pic_id' => 'Desain belum bisa ditugaskan — pembayaran jasa desain belum diverifikasi Finance.']);

    expect($design->fresh()->status)->toBe(DesignStatus::MenungguBayar);
});

test('the Kepala Desain assigns a PIC, assistants and the timeline', function () {
    $design = designAt($this, DesignStatus::MenungguPenugasan);
    $assistant = designFlowUser('DESIGNER');

    $this->actingAs($this->head)->post(route('design.assign', $design), [
        'pic_id' => $this->architect->id,
        'assistant_ids' => [$assistant->id],
        'start_date' => '2026-10-06',
        'target_hari' => 10,
    ])->assertSessionHasNoErrors();

    $design->refresh();
    expect($design->status)->toBe(DesignStatus::Desain)
        ->and($design->pic_id)->toBe($this->architect->id)
        ->and($design->staff()->pluck('users.id')->all())->toBe([$assistant->id])
        ->and($design->assigned_by)->toBe($this->head->id)
        ->and($design->deadline->toDateString())->toBe('2026-10-16')
        ->and(AuditLog::where('action', 'design.assigned')->where('model_id', $design->id)->exists())->toBeTrue()
        ->and(Notification::where('type', 'design_assigned')->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$this->architect->id, $assistant->id])->sort()->values()->all());
});

test('the Kepala Desain may pick themself as PIC', function () {
    $design = designAt($this, DesignStatus::MenungguPenugasan);

    $this->actingAs($this->head)->post(route('design.assign', $design), [
        'pic_id' => $this->head->id,
        'start_date' => '2026-10-06',
        'target_hari' => 7,
    ])->assertSessionHasNoErrors();

    expect($design->fresh()->pic_id)->toBe($this->head->id);
});

test('only a Kepala Desain assigns a design', function (string $role) {
    $design = designAt($this, DesignStatus::MenungguPenugasan);

    $this->actingAs(designFlowUser($role))->post(route('design.assign', $design), [
        'pic_id' => $this->architect->id,
        'start_date' => '2026-10-06',
        'target_hari' => 10,
    ])->assertForbidden();

    expect($design->fresh()->status)->toBe(DesignStatus::MenungguPenugasan);
})->with(['DESIGNER', 'MARKETING', 'CEO', 'PM', 'ESTIMATOR']);

test('the team must be active architects, the PIC not repeated as assistant', function (Closure $payload, string $field) {
    $design = designAt($this, DesignStatus::MenungguPenugasan);

    $this->actingAs($this->head)->post(route('design.assign', $design), [
        'start_date' => '2026-10-06',
        'target_hari' => 10,
        ...$payload($this),
    ])->assertSessionHasErrors($field);
})->with([
    'a non-architect PIC' => [fn ($test) => ['pic_id' => designFlowUser('PM')->id], 'pic_id'],
    'an inactive PIC' => [fn ($test) => ['pic_id' => tap(designFlowUser('DESIGNER'))->update(['is_active' => false])->id], 'pic_id'],
    'the PIC as assistant' => [fn ($test) => ['pic_id' => $test->architect->id, 'assistant_ids' => [$test->architect->id]], 'assistant_ids.0'],
    'no target' => [fn ($test) => ['pic_id' => $test->architect->id, 'target_hari' => null], 'target_hari'],
]);

test('the team can be reassigned while the design is worked on, keeping its status', function () {
    $design = workedDesign($this, DesignStatus::RevisiDesain);
    $other = designFlowUser('DESIGNER');

    $this->actingAs($this->head)->post(route('design.assign', $design), [
        'pic_id' => $other->id,
        'start_date' => '2026-10-01',
        'target_hari' => 20,
    ])->assertSessionHasNoErrors();

    expect($design->fresh()->status)->toBe(DesignStatus::RevisiDesain)
        ->and($design->fresh()->pic_id)->toBe($other->id);
});

// ── Membership (decision #15) ───────────────────────────────────────────

test('an architect only sees and edits designs they work on; a Kepala Desain sees them all', function () {
    $mine = workedDesign($this);
    $assisting = Design::factory()->fromRabDesain(DesignStatus::Desain)->create(['pic_id' => $this->head->id]);
    $assisting->staff()->attach($this->architect->id);
    $other = Design::factory()->fromRabDesain(DesignStatus::Desain)->create(['pic_id' => $this->head->id]);

    $this->actingAs($this->architect)->get(route('design.show', $mine))->assertOk();
    $this->actingAs($this->architect)->get(route('design.show', $assisting))->assertOk();
    $this->actingAs($this->architect)->get(route('design.show', $other))->assertForbidden();
    $this->actingAs($this->architect)->put(route('design.update', $other), ['brief_note' => 'x'])->assertForbidden();

    $this->actingAs($this->architect)->get(route('design.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('designs.data', fn ($designs) => collect($designs)->pluck('id')->sort()->values()->all() === collect([$mine->id, $assisting->id])->sort()->values()->all())
            ->where('queue', null));

    $this->actingAs($this->head)->get(route('design.show', $other))->assertOk();
    $this->actingAs($this->head)->get(route('design.index'))
        ->assertInertia(fn (Assert $page) => $page->has('designs.data', 3)->has('queue'));
});

test('the Kepala Desain queue lists the designs waiting for payment or assignment', function () {
    $unpaid = designAt($this, DesignStatus::MenungguBayar);
    $paid = Design::factory()->fromRabDesain(DesignStatus::MenungguPenugasan)->create();
    Design::factory()->fromRabDesain(DesignStatus::Desain)->create();

    $this->actingAs($this->head)->get(route('design.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('queue', 2)
            ->where('queue.0.id', $unpaid->id)
            ->where('queue.1.id', $paid->id)
            ->has('architects'));
});

test('a locked design cannot be edited, and a Sprint 12 design brief only edits the brief', function () {
    $locked = Design::factory()->fromRabDesain(DesignStatus::MenungguPenugasan)->create();

    $this->actingAs($this->head)->put(route('design.update', $locked), ['brief_note' => 'x'])
        ->assertSessionHasErrors('status');

    $design = workedDesign($this);
    $this->actingAs($this->architect)->put(route('design.update', $design), [
        'pic_id' => $this->head->id,
        'status' => DesignStatus::AccDesain->value,
        'brief_note' => 'Nuansa kayu.',
        'design_urls' => ['https://figma.com/file/v2'],
    ])->assertSessionHasNoErrors();

    $design->refresh();
    expect($design->status)->toBe(DesignStatus::Desain)
        ->and($design->pic_id)->toBe($this->architect->id)
        ->and($design->brief_note)->toBe('Nuansa kayu.')
        ->and($design->design_urls)->toBe(['https://figma.com/file/v2']);
});

// ── Marketing sends, asks revisions, records the approval (#17) ──────────

test('Marketing sends the design to the client once a link is uploaded', function () {
    $design = workedDesign($this);
    $design->update(['design_urls' => []]);

    $this->actingAs($this->marketing)->post(route('design.sendToClient', $design))
        ->assertSessionHasErrors(['status' => 'Arsitek belum mengunggah link desain.']);

    $design->update(['design_urls' => ['https://figma.com/file/abc']]);
    $this->actingAs($this->marketing)->post(route('design.sendToClient', $design))->assertSessionHasNoErrors();

    expect($design->fresh()->status)->toBe(DesignStatus::WaitingAccDesain)
        ->and($design->fresh()->sent_to_client_at)->not->toBeNull()
        ->and(Notification::where('user_id', $this->architect->id)->where('type', 'design_sent_to_client')->exists())->toBeTrue();
});

test('a revision is counted, kept with its note and audited', function () {
    $design = workedDesign($this, DesignStatus::WaitingAccDesain);

    $this->actingAs($this->marketing)->post(route('design.requestRevision', $design), ['note' => ''])
        ->assertSessionHasErrors('note');

    $this->actingAs($this->marketing)->post(route('design.requestRevision', $design), ['note' => 'Warna dinding lebih terang.'])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->marketing)->post(route('design.sendToClient', $design))->assertSessionHasNoErrors();
    $this->actingAs($this->marketing)->post(route('design.requestRevision', $design), ['note' => 'Tambah rak buku di sudut.'])
        ->assertSessionHasNoErrors();

    $design->refresh();
    expect($design->status)->toBe(DesignStatus::RevisiDesain)
        ->and($design->revision_count)->toBe(2)
        ->and($design->revisions()->orderBy('sequence')->pluck('note', 'sequence')->all())
        ->toBe([1 => 'Warna dinding lebih terang.', 2 => 'Tambah rak buku di sudut.'])
        ->and(AuditLog::where('action', 'design.revision_requested')->count())->toBe(2)
        ->and(Notification::where('user_id', $this->architect->id)->where('type', 'design_revision_requested')->count())->toBe(2);

    expect(fn () => DesignRevision::first()->update(['note' => 'x']))->toThrow(LogicException::class);
});

test('a revision can only be asked while the client is looking at the design', function () {
    $design = workedDesign($this);

    $this->actingAs($this->marketing)->post(route('design.requestRevision', $design), ['note' => 'Ganti warna.'])
        ->assertSessionHasErrors('status');

    expect($design->fresh()->revision_count)->toBe(0);
});

test('the client approving the design asks the Estimator for the RAB Proyek', function () {
    $design = workedDesign($this, DesignStatus::WaitingAccDesain);

    $this->actingAs($this->marketing)->post(route('design.markClientApproved', $design))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Desain disetujui klien. Permintaan RAB Proyek otomatis dikirim ke Estimator.');

    $design->refresh();
    $projectRab = Quotation::where('lead_id', $this->lead->id)->where('type', 'PROYEK')->sole();
    expect($design->status)->toBe(DesignStatus::AccDesain)
        ->and($design->client_acc)->toBeTrue()
        ->and($design->acc_date->toDateString())->toBe('2026-10-05')
        ->and($projectRab->status)->toBe(QuotationStatus::Diminta)
        ->and($projectRab->requested_by)->toBe($this->marketing->id)
        ->and($projectRab->requested_via)->toBe(Quotation::VIA_DESIGN_ACC)
        ->and(Notification::where('user_id', $this->estimator->id)->where('type', 'quotation_requested')->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'design.client_approved')->exists())->toBeTrue()
        // Sprint 17 Sub 04 — a lasting trace on the lead; the Marketing pressed it, so no notification to themself.
        ->and($this->lead->pipelineLogs()->latest('id')->first()->note)->toBe('Desain disetujui klien — RAB Proyek otomatis diminta ke Estimator.')
        ->and(Notification::where('user_id', $this->marketing->id)->where('type', 'project_rab_auto_requested')->exists())->toBeFalse();
});

test("another Marketing approving tells the lead's Marketing; both pages show the request until the Estimator submits", function () {
    $design = workedDesign($this, DesignStatus::WaitingAccDesain);
    $colleague = designFlowUser('MARKETING');

    $this->actingAs($colleague)->post(route('design.markClientApproved', $design))->assertSessionHasNoErrors();

    $projectRab = Quotation::where('lead_id', $this->lead->id)->where('type', 'PROYEK')->sole();
    expect(Notification::where('user_id', $this->marketing->id)->where('type', 'project_rab_auto_requested')->sole()->metadata)
        ->toMatchArray(['quotation_id' => $projectRab->id, 'lead_id' => $this->lead->id]);

    $assertNotice = fn (Assert $page) => $page
        ->where('projectRab.id', $projectRab->id)
        ->where('projectRab.status', 'DIMINTA')
        ->where('projectRab.auto', true)
        ->where('projectRab.pending_auto', true);
    $this->actingAs($this->marketing)->get(route('crm.leads.show', $this->lead))->assertInertia($assertNotice);
    $this->actingAs($this->marketing)->get(route('design.show', $design))->assertInertia($assertNotice);

    // The server keeps refusing a second RAB Proyek meanwhile (the button is disabled on the lead page).
    expect(fn () => app(QuotationService::class)->request($this->lead->fresh(), QuotationType::Proyek, 'Lagi', $this->marketing))
        ->toThrow(ValidationException::class);

    // Submitted for review: still running (no new request), but the notice is gone.
    $projectRab->update(['status' => QuotationStatus::Submitted->value]);
    $this->actingAs($this->marketing)->get(route('crm.leads.show', $this->lead))
        ->assertInertia(fn (Assert $page) => $page->where('projectRab.pending_auto', false)->where('projectRab.auto', true));
});

test('an already running RAB Proyek is not requested twice', function () {
    $design = workedDesign($this, DesignStatus::WaitingAccDesain);
    $running = Quotation::factory()->create(['lead_id' => $this->lead->id, 'type' => 'PROYEK', 'status' => QuotationStatus::Draft->value]);

    $this->actingAs($this->marketing)->post(route('design.markClientApproved', $design))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Desain disetujui klien. RAB Proyek untuk klien ini sudah berjalan (DRAFT) — tidak diminta ulang.');

    expect(Quotation::where('lead_id', $this->lead->id)->where('type', 'PROYEK')->count())->toBe(1)
        ->and($running->fresh()->requested_via)->toBeNull()
        ->and(Notification::where('user_id', $this->estimator->id)->where('type', 'design_acc')->exists())->toBeTrue()
        ->and(Notification::where('type', 'project_rab_auto_requested')->exists())->toBeFalse()
        ->and($this->lead->pipelineLogs()->latest('id')->first()->note)->toBe('Desain disetujui klien — RAB Proyek sudah berjalan, tidak diminta ulang.');

    // Asked for by a person, not automatically: no "already requested automatically" notice.
    $this->actingAs($this->marketing)->get(route('design.show', $design))
        ->assertInertia(fn (Assert $page) => $page->where('projectRab.auto', false)->where('projectRab.pending_auto', false));
});

test('only Marketing sends, asks revisions and records the approval', function (string $role) {
    $user = designFlowUser($role);
    $design = workedDesign($this, DesignStatus::WaitingAccDesain);

    $this->actingAs($user)->post(route('design.sendToClient', $design))->assertForbidden();
    $this->actingAs($user)->post(route('design.requestRevision', $design), ['note' => 'Ganti warna.'])->assertForbidden();
    $this->actingAs($user)->post(route('design.markClientApproved', $design))->assertForbidden();

    expect($design->fresh()->status)->toBe(DesignStatus::WaitingAccDesain);
})->with(['DESIGNER', 'KEPALA_DESAIN', 'CEO', 'ESTIMATOR']);

test('the design page offers each role its own actions', function () {
    $design = workedDesign($this, DesignStatus::WaitingAccDesain);

    $this->actingAs($this->marketing)->get(route('design.show', $design))
        ->assertInertia(fn (Assert $page) => $page->where('canMarketingActions', true)->where('canAssign', false)->where('canClientAcc', false)->where('canManage', false));
    $this->actingAs($this->head)->get(route('design.show', $design))
        ->assertInertia(fn (Assert $page) => $page->where('canMarketingActions', false)->where('canAssign', true)->where('canManage', true));
    $this->actingAs($this->architect)->get(route('design.show', $design))
        ->assertInertia(fn (Assert $page) => $page->where('canAssign', false)->where('canManage', true)->where('discussion.canPost', true));
});

// ── Pipeline & delay (old stages are history only) ──────────────────────

test('a Sprint 12 design is never moved along the old RAB / production stages', function () {
    $design = workedDesign($this, DesignStatus::AccDesain);
    $design->update(['client_acc' => true]);

    app(DesignService::class)->syncWithPipeline($this->lead->id, DesignService::EVENT_QUOTATION_DRAFTED);

    expect($design->fresh()->status)->toBe(DesignStatus::AccDesain);
});

test('the delay job ignores locked designs and Sprint 12 designs the client approved', function () {
    $approved = workedDesign($this, DesignStatus::AccDesain);
    $approved->update(['client_acc' => true, 'deadline' => '2026-09-01']);
    $locked = Design::factory()->fromRabDesain(DesignStatus::MenungguPenugasan)->create(['deadline' => '2026-09-01']);
    $late = Design::factory()->fromRabDesain(DesignStatus::Desain)->create(['deadline' => '2026-10-01']);

    app(DesignService::class)->recalculateDelays();

    expect($approved->fresh()->delay_hari)->toBe(0)
        ->and($locked->fresh()->delay_hari)->toBe(0)
        ->and($late->fresh()->delay_hari)->toBe(4);
});

// ── Arsitek ↔ Estimator thread (D6) ─────────────────────────────────────

test('the Estimator and the architects talk in the design thread, the others are notified', function () {
    $design = workedDesign($this);
    $assistant = designFlowUser('DESIGNER');
    $design->staff()->attach($assistant->id);

    $this->actingAs($this->estimator)->post(route('design.discussions.store', $design), [
        'body' => 'Partisi pakai kaca atau gypsum?',
        'quotation_id' => $design->quotation_id,
    ])->assertSessionHasNoErrors();

    expect(Notification::where('type', 'design_discussion')->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$this->architect->id, $assistant->id])->sort()->values()->all());

    $this->actingAs($this->architect)->post(route('design.discussions.store', $design), [
        'body' => 'Kaca tempered.',
        'attachment_url' => 'https://drive.google.com/detail',
    ])->assertSessionHasNoErrors();

    expect(Notification::where('user_id', $this->estimator->id)->where('type', 'design_discussion')->exists())->toBeTrue()
        ->and($design->discussions()->count())->toBe(2);
});

test('a thread message is checked: own designs only, http links, a RAB of the same lead', function () {
    $design = workedDesign($this);
    $outsider = designFlowUser('DESIGNER');

    $this->actingAs($outsider)->post(route('design.discussions.store', $design), ['body' => 'Halo'])->assertForbidden();
    $this->actingAs($this->marketing)->post(route('design.discussions.store', $design), ['body' => 'Halo'])->assertForbidden();
    $this->actingAs($this->architect)->post(route('design.discussions.store', $design), ['body' => 'Halo', 'attachment_url' => 'javascript:alert(1)'])
        ->assertSessionHasErrors('attachment_url');
    $this->actingAs($this->architect)->post(route('design.discussions.store', $design), ['body' => 'Halo', 'quotation_id' => Quotation::factory()->create()->id])
        ->assertSessionHasErrors('quotation_id');

    expect($design->discussions()->count())->toBe(0);
});

test('the thread shows on the design and on the lead quotations', function () {
    $design = workedDesign($this);
    app(DesignService::class)->discuss($design, ['body' => 'Cek ukuran dapur.'], $this->estimator);
    $projectRab = Quotation::factory()->create(['lead_id' => $this->lead->id, 'type' => 'PROYEK']);

    $this->actingAs($this->estimator)->get(route('quotations.show', $projectRab))
        ->assertInertia(fn (Assert $page) => $page
            ->where('discussion.designId', $design->id)
            ->where('discussion.messages.0.body', 'Cek ukuran dapur.')
            ->where('discussion.canPost', true));

    $this->actingAs(designFlowUser('PM'))->get(route('quotations.show', $projectRab))
        ->assertInertia(fn (Assert $page) => $page->where('discussion.canPost', false));

    $this->actingAs($this->architect)->get(route('design.show', $design))
        ->assertInertia(fn (Assert $page) => $page->has('discussion.messages', 1));
});

test('a manual status change can never pick the payment-flow states', function () {
    $design = Design::factory()->create(['pic_id' => $this->architect->id]);

    $this->actingAs($this->architect)->put(route('design.update', $design), [
        'pic_id' => $this->architect->id,
        'status' => DesignStatus::MenungguPenugasan->value,
    ])->assertSessionHasErrors('status');
});

test('the old Client ACC on a Sprint 12 design goes through the new approval', function () {
    $design = workedDesign($this, DesignStatus::WaitingAccDesain);

    $result = app(DesignService::class)->clientAcc($design, $this->marketing);

    expect($result->status)->toBe(DesignStatus::AccDesain)
        ->and(Quotation::where('lead_id', $this->lead->id)->where('type', 'PROYEK')->sole()->status)->toBe(QuotationStatus::Diminta);
});

test('assigning a design already approved by the client is refused', function () {
    $design = workedDesign($this, DesignStatus::AccDesain);

    expect(fn () => app(DesignService::class)->assign($design, [
        'pic_id' => $this->architect->id,
        'start_date' => '2026-10-06',
        'target_hari' => 3,
    ], $this->head))->toThrow(ValidationException::class);
});
