<?php

use App\Enums\DesignStatus;
use App\Enums\InvoiceType;
use App\Enums\LeadStatus;
use App\Enums\NotificationPriority;
use App\Enums\NotificationType;
use App\Enums\QuotationStatus;
use App\Enums\QuotationType;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Design;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Quotation;
use App\Models\User;
use App\Services\ActionInboxService;
use App\Services\DesignService;
use App\Services\InvoiceService;
use App\Services\QuotationService;
use App\Support\NotificationTarget;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 12 Sub 8 — a design is born from an approved RAB Jasa Desain,
 * locked until Finance verifies its invoice, assigned by a Kepala Desain,
 * with an Arsitek ↔ Estimator thread (decisions #15–#18, D5, D6).
 * Sprint 23 G (K18–K21) — after the assignment everything with the client
 * runs in a WhatsApp group: the design keeps the group link + design links
 * and one action, "Desain Disetujui Klien", for the lead's Marketing or the
 * design team. Sprint 22's hand-over and "Minta Revisi" are gone.
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

/** A design being worked on by `$test->architect`, with a link saved. */
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

/** @return array<string, mixed>|null */
function designFlowInbox(User $user, string $key): ?array
{
    return collect(app(ActionInboxService::class)->for($user, fresh: true))->firstWhere('key', $key);
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
    $invoices->submitProof($invoice, ['payment_proof_url' => 'https://drive.google.com/bukti'], $this->marketing);

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

// ── Kepala Desain assigns (dialog unchanged — K18) ──────────────────────

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

test('assigning a design already approved by the client is refused', function () {
    $design = workedDesign($this, DesignStatus::AccDesain);

    expect(fn () => app(DesignService::class)->assign($design, [
        'pic_id' => $this->architect->id,
        'start_date' => '2026-10-06',
        'target_hari' => 3,
    ], $this->head))->toThrow(ValidationException::class);
});

// ── Membership (decision #15) ───────────────────────────────────────────

test('an architect only sees designs they work on; a Kepala Desain sees them all', function () {
    $mine = workedDesign($this);
    $assisting = Design::factory()->fromRabDesain(DesignStatus::Desain)->create(['pic_id' => $this->head->id]);
    $assisting->staff()->attach($this->architect->id);
    $other = Design::factory()->fromRabDesain(DesignStatus::Desain)->create(['pic_id' => $this->head->id]);

    $this->actingAs($this->architect)->get(route('design.show', $mine))->assertOk();
    $this->actingAs($this->architect)->get(route('design.show', $assisting))->assertOk();
    $this->actingAs($this->architect)->get(route('design.show', $other))->assertForbidden();
    $this->actingAs($this->architect)->put(route('design.links.update', $other), ['design_urls' => ['https://figma.com/x']])->assertForbidden();

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

// ── Link Desain: WhatsApp group + design links (K18, K19b) ──────────────

test("the lead's Marketing saves the WhatsApp group and design links; who saved them is kept", function () {
    $design = workedDesign($this);
    $design->update(['design_urls' => null]);

    $this->actingAs($this->marketing)->put(route('design.links.update', $design), [
        'whatsapp_group_url' => '  https://chat.whatsapp.com/AbCdEf123  ',
        'design_urls' => ['https://figma.com/file/v1', 'https://drive.google.com/render'],
    ])->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Link grup WhatsApp & link desain disimpan.');

    $design->refresh();
    expect($design->whatsapp_group_url)->toBe('https://chat.whatsapp.com/AbCdEf123')
        ->and($design->design_urls)->toBe(['https://figma.com/file/v1', 'https://drive.google.com/render'])
        ->and($design->links_updated_by)->toBe($this->marketing->id)
        ->and($design->links_updated_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($design->status)->toBe(DesignStatus::Desain)
        ->and(AuditLog::where('action', 'design.links_updated')->where('model_id', $design->id)->sole()->user_id)->toBe($this->marketing->id);

    // The architect saves next: the "terakhir disimpan oleh" moves to them; wa.me is accepted too.
    Carbon::setTestNow(now()->addHour());
    $this->actingAs($this->architect)->put(route('design.links.update', $design), [
        'whatsapp_group_url' => 'https://wa.me/6281234567890',
        'design_urls' => ['https://figma.com/file/v2'],
    ])->assertSessionHasNoErrors();

    expect($design->fresh()->links_updated_by)->toBe($this->architect->id)
        ->and($design->fresh()->whatsapp_group_url)->toBe('https://wa.me/6281234567890');
});

test('a save that changes nothing records nothing', function () {
    $design = workedDesign($this);
    $design->update(['whatsapp_group_url' => 'https://chat.whatsapp.com/AbC']);

    $this->actingAs($this->architect)->put(route('design.links.update', $design), [
        'whatsapp_group_url' => 'https://chat.whatsapp.com/AbC',
        'design_urls' => ['https://figma.com/file/abc'],
    ])->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Tidak ada perubahan link.');

    expect($design->fresh()->links_updated_by)->toBeNull()
        ->and(AuditLog::where('action', 'design.links_updated')->exists())->toBeFalse();
});

test('the WhatsApp link must be a WhatsApp group or chat, design links must be web links', function (array $payload, string $field) {
    $design = workedDesign($this);

    $this->actingAs($this->marketing)->put(route('design.links.update', $design), $payload)
        ->assertSessionHasErrors($field);

    expect($design->fresh()->links_updated_by)->toBeNull();
})->with([
    'another host' => [['whatsapp_group_url' => 'https://t.me/joinchat/abc'], 'whatsapp_group_url'],
    'a look-alike host' => [['whatsapp_group_url' => 'https://chat.whatsapp.com.evil.test/abc'], 'whatsapp_group_url'],
    'not a URL' => [['whatsapp_group_url' => 'grup WA klien'], 'whatsapp_group_url'],
    'javascript scheme' => [['whatsapp_group_url' => 'javascript:alert(1)'], 'whatsapp_group_url'],
    'a design link without http' => [['design_urls' => ['ftp://figma.com/file']], 'design_urls.0'],
    'an empty design link' => [['design_urls' => ['']], 'design_urls.0'],
]);

test("only the lead's Marketing and the design team save the links", function () {
    $design = workedDesign($this);
    $assistant = designFlowUser('DESIGNER');
    $design->staff()->attach($assistant->id);
    $payload = fn (string $code) => ['whatsapp_group_url' => "https://chat.whatsapp.com/{$code}", 'design_urls' => ['https://figma.com/file/abc']];

    foreach (['MARKETING', 'DESIGNER', 'ESTIMATOR', 'CEO', 'PM'] as $role) {
        $this->actingAs(designFlowUser($role))->put(route('design.links.update', $design), $payload('X'))->assertForbidden();
    }
    expect($design->fresh()->whatsapp_group_url)->toBeNull();

    $this->actingAs($assistant)->put(route('design.links.update', $design), $payload('A'))->assertSessionHasNoErrors();
    $this->actingAs($this->head)->put(route('design.links.update', $design), $payload('B'))->assertSessionHasNoErrors();
    expect($design->fresh()->whatsapp_group_url)->toBe('https://chat.whatsapp.com/B');
});

test('links are saved only once the design is assigned, and an approved design keeps a design link', function () {
    $waiting = designAt($this, DesignStatus::MenungguPenugasan);
    $this->actingAs($this->marketing)->put(route('design.links.update', $waiting), ['whatsapp_group_url' => 'https://chat.whatsapp.com/AbC'])
        ->assertSessionHasErrors(['design_urls' => 'Link baru bisa disimpan setelah Kepala Desain menugaskan arsitek.']);

    $approved = Design::factory()->fromRabDesain(DesignStatus::AccDesain)->create([
        'pic_id' => $this->architect->id, 'client_acc' => true, 'design_urls' => ['https://figma.com/final'],
    ]);
    $this->actingAs($this->architect)->put(route('design.links.update', $approved), ['design_urls' => []])
        ->assertSessionHasErrors(['design_urls' => 'Desain yang sudah disetujui klien harus tetap punya minimal satu link desain.']);
    $this->actingAs($this->architect)->put(route('design.links.update', $approved), ['design_urls' => ['https://figma.com/final-v2']])
        ->assertSessionHasNoErrors();

    expect($waiting->fresh()->whatsapp_group_url)->toBeNull()
        ->and($approved->fresh()->design_urls)->toBe(['https://figma.com/final-v2']);
});

test('a Sprint 12 design has no brief form any more; a locked one neither', function () {
    $design = workedDesign($this);

    $this->actingAs($this->architect)->put(route('design.update', $design), [
        'brief_note' => 'Nuansa kayu.',
        'design_urls' => ['https://figma.com/file/v2'],
    ])->assertSessionHasErrors('status');

    $locked = Design::factory()->fromRabDesain(DesignStatus::MenungguPenugasan)->create();
    $this->actingAs($this->head)->put(route('design.update', $locked), ['brief_note' => 'x'])
        ->assertSessionHasErrors('status');

    expect($design->fresh()->design_urls)->toBe(['https://figma.com/file/abc'])
        ->and($design->fresh()->brief_note)->toBeNull();
});

test('a Kepala Desain who assigned themselves as PIC can then save the links alone', function () {
    $design = Design::factory()->fromRabDesain(DesignStatus::MenungguPenugasan)->create();

    $this->actingAs($this->head)->post(route('design.assign', $design), [
        'pic_id' => $this->head->id,
        'start_date' => '2026-10-09',
        'target_hari' => 14,
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->head)->put(route('design.links.update', $design), [
        'whatsapp_group_url' => 'https://chat.whatsapp.com/Kopi',
        'design_urls' => [],
    ])->assertSessionHasNoErrors();

    $design->refresh();
    expect($design->pic_id)->toBe($this->head->id)
        ->and($design->status)->toBe(DesignStatus::Desain)
        ->and($design->target_hari)->toBe(14)
        ->and($design->whatsapp_group_url)->toBe('https://chat.whatsapp.com/Kopi');
});

// ── "Desain Disetujui Klien" (K19) ──────────────────────────────────────

test("the lead's Marketing records the client's approval straight from DESAIN: RAB Proyek requested, who pressed it kept", function () {
    $design = workedDesign($this);

    $this->actingAs($this->marketing)->post(route('design.markClientApproved', $design))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Desain disetujui klien. Permintaan RAB Proyek otomatis dikirim ke Estimator.');

    $design->refresh();
    $projectRab = Quotation::where('lead_id', $this->lead->id)->where('type', 'PROYEK')->sole();
    $audit = AuditLog::where('action', 'design.client_approved')->sole();
    expect($design->status)->toBe(DesignStatus::AccDesain)
        ->and($design->client_acc)->toBeTrue()
        ->and($design->acc_date->toDateString())->toBe('2026-10-05')
        ->and($design->client_acc_by)->toBe($this->marketing->id)
        ->and($design->client_acc_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($projectRab->status)->toBe(QuotationStatus::Diminta)
        ->and($projectRab->requested_by)->toBe($this->marketing->id)
        ->and($projectRab->requested_via)->toBe(Quotation::VIA_DESIGN_ACC)
        ->and($projectRab->request_note)->toBe('Desain disetujui klien — susun RAB Proyek dari desain ini.')
        ->and(Notification::where('user_id', $this->estimator->id)->where('type', 'quotation_requested')->exists())->toBeTrue()
        ->and($audit->user_id)->toBe($this->marketing->id)
        ->and($audit->old_values)->toBe(['status' => 'DESAIN'])
        ->and($this->lead->pipelineLogs()->latest('id')->first()->note)->toBe('Desain disetujui klien — RAB Proyek otomatis diminta ke Estimator.')
        ->and(Notification::where('user_id', $this->architect->id)->where('type', 'design_client_approved')->sole()->message)
        ->toContain("dicatat oleh {$this->marketing->name}")
        // The Marketing pressed it: nothing to tell themself.
        ->and(Notification::where('user_id', $this->marketing->id)->exists())->toBeFalse();
});

test("the architect may record it too: the lead's Marketing is told, both pages show the request and who recorded it", function () {
    $design = workedDesign($this);

    $this->actingAs($this->architect)->post(route('design.markClientApproved', $design))->assertSessionHasNoErrors();

    $projectRab = Quotation::where('lead_id', $this->lead->id)->where('type', 'PROYEK')->sole();
    $told = Notification::where('user_id', $this->marketing->id)->where('type', 'project_rab_auto_requested')->sole();
    expect($design->fresh()->client_acc_by)->toBe($this->architect->id)
        ->and($told->metadata)->toMatchArray(['quotation_id' => $projectRab->id, 'lead_id' => $this->lead->id])
        ->and($told->message)->toContain("dicatat oleh {$this->architect->name}")
        // One ring is enough: no second "disetujui klien" to the same Marketing.
        ->and(Notification::where('user_id', $this->marketing->id)->where('type', 'design_client_approved')->exists())->toBeFalse();

    $assertNotice = fn (Assert $page) => $page
        ->where('projectRab.id', $projectRab->id)
        ->where('projectRab.status', 'DIMINTA')
        ->where('projectRab.auto', true)
        ->where('projectRab.pending_auto', true);
    $this->actingAs($this->marketing)->get(route('crm.leads.show', $this->lead))
        ->assertInertia(fn (Assert $page) => $assertNotice($page)
            ->where('lead.design.client_approver.name', $this->architect->name)
            ->whereNot('lead.design.client_acc_at', null));
    $this->actingAs($this->marketing)->get(route('design.show', $design))
        ->assertInertia(fn (Assert $page) => $assertNotice($page)
            ->where('design.client_approver.name', $this->architect->name)
            ->where('canApprove', false));

    // The server keeps refusing a second RAB Proyek meanwhile (the button is disabled on the lead page).
    expect(fn () => app(QuotationService::class)->request($this->lead->fresh(), QuotationType::Proyek, 'Lagi', $this->marketing))
        ->toThrow(ValidationException::class);

    // Submitted for review: still running (no new request), but the notice is gone.
    $projectRab->update(['status' => QuotationStatus::Submitted->value]);
    $this->actingAs($this->marketing)->get(route('crm.leads.show', $this->lead))
        ->assertInertia(fn (Assert $page) => $page->where('projectRab.pending_auto', false)->where('projectRab.auto', true));
});

test('an already running RAB Proyek is not requested twice; the Marketing hears of an approval it did not press', function () {
    $design = workedDesign($this);
    $running = Quotation::factory()->create(['lead_id' => $this->lead->id, 'type' => 'PROYEK', 'status' => QuotationStatus::Draft->value]);

    $this->actingAs($this->head)->post(route('design.markClientApproved', $design))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Desain disetujui klien. RAB Proyek untuk klien ini sudah berjalan (DRAFT) — tidak diminta ulang.');

    expect(Quotation::where('lead_id', $this->lead->id)->where('type', 'PROYEK')->count())->toBe(1)
        ->and($running->fresh()->requested_via)->toBeNull()
        ->and(Notification::where('user_id', $this->estimator->id)->where('type', 'design_acc')->exists())->toBeTrue()
        ->and(Notification::where('type', 'project_rab_auto_requested')->exists())->toBeFalse()
        ->and(Notification::where('type', 'design_client_approved')->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$this->architect->id, $this->marketing->id])->sort()->values()->all())
        ->and($this->lead->pipelineLogs()->latest('id')->first()->note)->toBe('Desain disetujui klien — RAB Proyek sudah berjalan, tidak diminta ulang.');

    // Asked for by a person, not automatically: no "already requested automatically" notice.
    $this->actingAs($this->marketing)->get(route('design.show', $design))
        ->assertInertia(fn (Assert $page) => $page->where('projectRab.auto', false)->where('projectRab.pending_auto', false));
});

test('older rows still waiting for the client or in revision can be approved', function (DesignStatus $status) {
    $design = workedDesign($this, $status);
    $design->update(['ready_for_client_at' => now(), 'revision_count' => 2]);

    $this->actingAs($this->marketing)->post(route('design.markClientApproved', $design))->assertSessionHasNoErrors();

    expect($design->fresh()->status)->toBe(DesignStatus::AccDesain)
        ->and(AuditLog::where('action', 'design.client_approved')->sole()->old_values)->toBe(['status' => $status->value]);
})->with([DesignStatus::WaitingAccDesain, DesignStatus::RevisiDesain]);

test('the approval needs a saved design link, an assigned design, and happens once', function () {
    $design = workedDesign($this);
    $design->update(['design_urls' => [], 'whatsapp_group_url' => 'https://chat.whatsapp.com/AbC']);

    $this->actingAs($this->marketing)->post(route('design.markClientApproved', $design))
        ->assertSessionHasErrors(['status' => 'Simpan minimal satu link desain dulu sebelum mencatat desain disetujui klien.']);
    expect($design->fresh()->status)->toBe(DesignStatus::Desain);

    $design->update(['design_urls' => ['https://figma.com/file/abc']]);
    $this->actingAs($this->marketing)->post(route('design.markClientApproved', $design))->assertSessionHasNoErrors();
    $this->actingAs($this->marketing)->post(route('design.markClientApproved', $design))
        ->assertSessionHasErrors(['status' => 'Desain ini sudah disetujui klien.']);

    $lead = Lead::factory()->create(['status' => LeadStatus::DealDesain->value, 'assigned_to' => $this->marketing->id]);
    $locked = Design::factory()->fromRabDesain(DesignStatus::MenungguPenugasan)->create(['lead_id' => $lead->id, 'design_urls' => ['https://figma.com/x']]);
    $this->actingAs($this->marketing)->post(route('design.markClientApproved', $locked))->assertSessionHasErrors('status');

    expect(AuditLog::where('action', 'design.client_approved')->count())->toBe(1)
        ->and(Quotation::where('type', 'PROYEK')->count())->toBe(1);
});

test("only the lead's Marketing and the design team record the approval", function (Closure $user) {
    $design = workedDesign($this);

    $this->actingAs($user($this))->post(route('design.markClientApproved', $design))->assertForbidden();
    // The old Client ACC route is no back door for a Sprint 12 design.
    $this->actingAs($user($this))->post(route('design.clientAcc', $design))->assertForbidden();

    expect($design->fresh()->status)->toBe(DesignStatus::Desain)
        ->and($design->fresh()->client_acc_by)->toBeNull();
})->with([
    'another Marketing' => [fn ($test) => designFlowUser('MARKETING')],
    'an architect off the team' => [fn ($test) => designFlowUser('DESIGNER')],
    'the Estimator' => [fn ($test) => $test->estimator],
    'the CEO' => [fn ($test) => designFlowUser('CEO')],
    'a PM' => [fn ($test) => designFlowUser('PM')],
]);

test('an assistant on the team may record the approval', function () {
    $design = workedDesign($this);
    $assistant = designFlowUser('DESIGNER');
    $design->staff()->attach($assistant->id);

    $this->actingAs($assistant)->post(route('design.markClientApproved', $design))->assertSessionHasNoErrors();

    expect($design->fresh()->client_acc_by)->toBe($assistant->id);
});

test('the old Client ACC on a Sprint 12 design goes through "Desain Disetujui Klien"', function () {
    $design = workedDesign($this, DesignStatus::WaitingAccDesain);

    $result = app(DesignService::class)->clientAcc($design, $this->marketing);

    expect($result->status)->toBe(DesignStatus::AccDesain)
        ->and($result->client_acc_by)->toBe($this->marketing->id)
        ->and(Quotation::where('lead_id', $this->lead->id)->where('type', 'PROYEK')->sole()->status)->toBe(QuotationStatus::Diminta);
});

// ── What the design page offers (K18, K19, K4b) ─────────────────────────

test('the design page offers the links and the approval to the lead Marketing and the team only', function () {
    $design = workedDesign($this);
    $design->update(['whatsapp_group_url' => 'https://chat.whatsapp.com/AbC', 'links_updated_by' => $this->architect->id, 'links_updated_at' => now()]);

    $this->actingAs($this->marketing)->get(route('design.show', $design))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canEditLinks', true)->where('canApprove', true)->where('canAssign', false)
            ->where('canClientAcc', false)->where('canManage', false)
            ->where('design.whatsapp_group_url', 'https://chat.whatsapp.com/AbC')
            ->where('design.links_updater.name', $this->architect->name)
            ->missing('canMarketingActions')->missing('canMarkReady')->missing('clientPhone')
            ->missing('design.lead.phone'));
    $this->actingAs($this->architect)->get(route('design.show', $design))
        ->assertInertia(fn (Assert $page) => $page->where('canEditLinks', true)->where('canApprove', true)->where('canAssign', false)->where('discussion.canPost', true));
    $this->actingAs($this->head)->get(route('design.show', $design))
        ->assertInertia(fn (Assert $page) => $page->where('canEditLinks', true)->where('canApprove', true)->where('canAssign', true));

    foreach ([designFlowUser('MARKETING'), $this->estimator, designFlowUser('CEO')] as $viewer) {
        $this->actingAs($viewer)->get(route('design.show', $design))
            ->assertInertia(fn (Assert $page) => $page->where('canEditLinks', false)->where('canApprove', false));
    }

    // Before the assignment nothing is editable yet; after the approval only the links stay editable.
    $locked = Design::factory()->fromRabDesain(DesignStatus::MenungguPenugasan)->create();
    $this->actingAs($this->head)->get(route('design.show', $locked))
        ->assertInertia(fn (Assert $page) => $page->where('canEditLinks', false)->where('canApprove', false));
    app(DesignService::class)->markClientApproved($design, $this->marketing);
    $this->actingAs($this->marketing)->get(route('design.show', $design))
        ->assertInertia(fn (Assert $page) => $page->where('canEditLinks', true)->where('canApprove', false));
});

// ── Sprint 22 / "Minta Revisi" removed (K20) ────────────────────────────

test('the hand-over, send-to-client and revision routes are gone', function () {
    $design = workedDesign($this);

    expect(Route::has('design.markReady'))->toBeFalse()
        ->and(Route::has('design.sendToClient'))->toBeFalse()
        ->and(Route::has('design.requestRevision'))->toBeFalse();

    $this->actingAs($this->architect)->post("/design/{$design->id}/ready-to-send")->assertNotFound();
    $this->actingAs($this->marketing)->post("/design/{$design->id}/send-to-client")->assertNotFound();
    $this->actingAs($this->marketing)->post("/design/{$design->id}/request-revision", ['note' => 'x'])->assertNotFound();

    expect($design->fresh()->status)->toBe(DesignStatus::Desain);
});

test('no Marketing "send design" queue; the architect keeps one work queue until the client approves', function () {
    $design = workedDesign($this);
    $revision = Design::factory()->fromRabDesain(DesignStatus::RevisiDesain)->create(['pic_id' => $this->architect->id]);
    // A Sprint 22 mark on an old row no longer hides it from the architect.
    $revision->update(['ready_for_client_at' => now()]);

    expect(designFlowInbox($this->marketing, 'design-send'))->toBeNull()
        ->and(designFlowInbox($this->architect, 'design-revision'))->toBeNull()
        ->and(designFlowInbox($this->architect, 'design-work')['count'])->toBe(2)
        ->and(designFlowInbox($this->architect, 'design-work')['description'])->toContain('Desain Disetujui Klien');

    app(DesignService::class)->markClientApproved($design, $this->marketing);

    expect(designFlowInbox($this->architect, 'design-work')['count'])->toBe(1);
});

test('notifications of the retired steps still render, as plain Info on the design', function () {
    $old = Notification::factory()->create(['type' => 'design_ready_to_send', 'metadata' => ['design_id' => 7, 'lead_id' => 2]]);

    expect($old->notificationType())->toBe(NotificationType::DesignReadyToSend)
        ->and($old->priority)->toBe(NotificationPriority::Info->value)
        ->and(NotificationTarget::for($old))->toBe(route('design.show', 7))
        ->and(NotificationType::DesignRevisionRequested->priority())->toBe(NotificationPriority::Info);
});

test('the design list no longer filters "siap dikirim"', function () {
    workedDesign($this);
    Design::factory()->fromRabDesain(DesignStatus::Desain)->create(['ready_for_client_at' => now()]);

    $this->actingAs($this->marketing)->get(route('design.index', ['ready' => 1]))
        ->assertInertia(fn (Assert $page) => $page->has('designs.data', 2)->missing('filters.ready'));
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

// ── Diskusi Desain (D6, Sprint 22 K8 — unchanged, K21) ──────────────────

test('the Estimator and the architects talk in the design thread, the others are notified', function () {
    $design = workedDesign($this);
    $assistant = designFlowUser('DESIGNER');
    $design->staff()->attach($assistant->id);

    $this->actingAs($this->estimator)->post(route('design.discussions.store', $design), [
        'body' => 'Partisi pakai kaca atau gypsum?',
        'quotation_id' => $design->quotation_id,
    ])->assertSessionHasNoErrors();

    // Sprint 22 — the lead's Marketing is in the conversation too.
    expect(Notification::where('type', 'design_discussion')->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$this->architect->id, $assistant->id, $this->marketing->id])->sort()->values()->all());

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
    // Sprint 22 — a Marketing who doesn't own the lead stays out; so do other roles.
    $this->actingAs(designFlowUser('MARKETING'))->post(route('design.discussions.store', $design), ['body' => 'Halo'])->assertForbidden();
    $this->actingAs(designFlowUser('PM'))->post(route('design.discussions.store', $design), ['body' => 'Halo'])->assertForbidden();
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

test("the lead's Marketing joins the design thread and tells the architects and the Estimator", function () {
    $design = workedDesign($this);
    Quotation::factory()->create(['lead_id' => $this->lead->id, 'created_by' => $this->estimator->id]);

    $this->actingAs($this->marketing)->get(route('design.show', $design))
        ->assertInertia(fn (Assert $page) => $page->where('discussion.canPost', true));
    $this->actingAs(designFlowUser('MARKETING'))->get(route('design.show', $design))
        ->assertInertia(fn (Assert $page) => $page->where('discussion.canPost', false));

    $this->actingAs($this->marketing)->post(route('design.discussions.store', $design), [
        'body' => 'Klien tanya: bisa tambah rak buku di sudut?',
    ])->assertSessionHasNoErrors();

    expect(Notification::where('type', 'design_discussion')->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$this->architect->id, $this->estimator->id])->sort()->values()->all())
        ->and($design->discussions()->sole()->user_id)->toBe($this->marketing->id);
});

test('a manual status change can never pick the payment-flow states', function () {
    $design = Design::factory()->create(['pic_id' => $this->architect->id]);

    $this->actingAs($this->architect)->put(route('design.update', $design), [
        'pic_id' => $this->architect->id,
        'status' => DesignStatus::MenungguPenugasan->value,
    ])->assertSessionHasErrors('status');
});
