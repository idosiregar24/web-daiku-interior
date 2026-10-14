<?php

use App\Enums\LeadStatus;
use App\Enums\NotificationPriority;
use App\Enums\NotificationType;
use App\Enums\QuotationStatus;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Quotation;
use App\Models\QuotationApproval;
use App\Models\QuotationItem;
use App\Models\QuotationItemReview;
use App\Models\QuotationNote;
use App\Models\QuotationRevision;
use App\Models\User;
use App\Services\ActionInboxService;
use App\Services\QuotationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 23 Sub 03–06 — volume from P / T/L (K5), the review inside the
 * RAB table with ✔ carried from earlier versions (K6–K7), no CEO stage
 * and no "Kirim ke Marketing" (K8–K10, CEO notes), and the client's
 * "Tidak Setuju" on the link forwarded by Marketing (K11).
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->estimator = rabFlowUser('ESTIMATOR');
    $this->pm = rabFlowUser('PM');
    $this->marketing = rabFlowUser('MARKETING');
    $this->ceo = rabFlowUser('CEO');
    $this->service = app(QuotationService::class);
    $this->lead = Lead::factory()->create(['status' => LeadStatus::DealDesain->value, 'assigned_to' => $this->marketing->id]);
});

function rabFlowUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** Kitchen Set · Meja · Lemari in section "Dapur" — the three lines every K7 test starts from. */
function rabFlowItems(array $overrides = []): array
{
    $items = [
        'Kitchen Set' => ['description' => 'Kitchen Set', 'dim_length' => 3, 'dim_width_height' => null, 'qty' => 3, 'qty_source' => 'P', 'unit_id' => unitId('m/lari'), 'unit_price' => 2_500_000],
        'Meja' => ['description' => 'Meja', 'qty' => 1, 'unit_id' => unitId('unit'), 'unit_price' => 1_000_000],
        'Lemari' => ['description' => 'Lemari', 'dim_length' => 2, 'dim_width_height' => 1.5, 'qty' => 3, 'qty_source' => 'PxTL', 'unit_id' => unitId('m2'), 'unit_price' => 900_000],
    ];

    foreach ($overrides as $name => $changes) {
        $items[$name] = [...$items[$name], ...$changes];
    }

    return ['sections' => [['name' => 'Dapur', 'items' => array_values($items)]]];
}

/** A drafted, submitted RAB Proyek of the lead (version 1). */
function rabFlowSubmitted(object $test): Quotation
{
    $quotation = Quotation::factory()->create([
        'lead_id' => $test->lead->id,
        'status' => QuotationStatus::Draft->value,
        'created_by' => $test->estimator->id,
        'requested_by' => $test->marketing->id,
    ]);
    $test->service->saveRab($quotation, rabFlowItems());

    return $test->service->submit($quotation->fresh());
}

/** The PM's marks for the items NOT carried (by description → verdict / note). */
function rabFlowMarks(Quotation $quotation, array $wrong = []): array
{
    $carried = app(QuotationService::class)->carriedMarks($quotation);

    return $quotation->items()->get()
        ->reject(fn (QuotationItem $item) => isset($carried[$item->id]))
        ->map(fn (QuotationItem $item) => [
            'item_id' => $item->id,
            'verdict' => isset($wrong[$item->description]) ? 'SALAH' : 'OK',
            'note' => $wrong[$item->description] ?? null,
        ])->values()->all();
}

/** Sends the RAB to the client and returns the token of its link. */
function rabFlowSend(object $test, Quotation $quotation): string
{
    $test->service->sendToClient($quotation->fresh(), $test->marketing);

    return $quotation->fresh()->currentShareLink()->token;
}

// ── K5: volume from P / T/L ──────────────────────────────────────────────

test('saveRab keeps a volume source only when it is true for the unit and the dimensions', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft->value]);

    $this->service->saveRab($quotation, ['sections' => [['name' => 'Umum', 'items' => [
        ['description' => 'Lis P', 'dim_length' => 3.5, 'qty' => 3.5, 'qty_source' => 'P', 'unit_id' => unitId('m/lari'), 'unit_price' => 100],
        ['description' => 'Lis TL', 'dim_length' => 9, 'dim_width_height' => 2, 'qty' => 2, 'qty_source' => 'TL', 'unit_id' => unitId('m/lari'), 'unit_price' => 100],
        ['description' => 'Lis PxTL', 'dim_length' => 2, 'dim_width_height' => 2, 'qty' => 4, 'qty_source' => 'PxTL', 'unit_id' => unitId('m/lari'), 'unit_price' => 100],
        ['description' => 'Plafon', 'dim_length' => 2.5, 'dim_width_height' => 0.6, 'qty' => 1.5, 'qty_source' => 'PxTL', 'unit_id' => unitId('m2'), 'unit_price' => 100],
        ['description' => 'Plafon diubah', 'dim_length' => 2.5, 'dim_width_height' => 0.6, 'qty' => 2, 'qty_source' => 'PxTL', 'unit_id' => unitId('m2'), 'unit_price' => 100],
        ['description' => 'Set tanpa dimensi', 'qty' => 1, 'qty_source' => 'PxTL', 'unit_id' => unitId('set'), 'unit_price' => 100],
        ['description' => 'Unit P saja', 'dim_length' => 2, 'qty' => 2, 'qty_source' => 'P', 'unit_id' => unitId('unit'), 'unit_price' => 100],
        ['description' => 'Manual', 'dim_length' => 2, 'dim_width_height' => 3, 'qty' => 5, 'unit_id' => unitId('m2'), 'unit_price' => 100],
    ]]]]);

    expect($quotation->items()->pluck('qty_source', 'description')->all())->toBe([
        'Lis P' => 'P',
        'Lis TL' => 'TL',
        'Lis PxTL' => null,
        'Plafon' => 'PxTL',
        'Plafon diubah' => null,
        'Set tanpa dimensi' => null,
        'Unit P saja' => null,
        'Manual' => null,
    ])
        // The server never rewrites the typed volume.
        ->and((float) $quotation->items()->where('description', 'Plafon diubah')->value('qty'))->toBe(2.0);
});

test('the RAB form rejects an unknown volume source', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft->value]);

    $this->actingAs($this->estimator)->put(route('quotations.items.update', $quotation), ['sections' => [['name' => 'A', 'items' => [
        ['description' => 'X', 'qty' => 1, 'qty_source' => 'LUAS', 'unit_id' => unitId('m2'), 'unit_price' => 100],
    ]]]])->assertSessionHasErrors('sections.0.items.0.qty_source');

    $this->actingAs($this->estimator)->put(route('quotations.items.update', $quotation), rabFlowItems())->assertSessionHasNoErrors();
    expect($quotation->items()->pluck('qty_source')->all())->toBe(['P', null, 'PxTL']);
});

// ── K7: ✔ carried to the next version ────────────────────────────────────

test('unchanged ✔ items are carried; new, changed and ✘ items are marked again', function () {
    $quotation = rabFlowSubmitted($this);
    reviewQuotation($quotation->fresh(), $this->pm, ['Meja' => 'Harga terlalu mahal']);

    // v2: Meja (was ✘) gets a new price, Lemari (was ✔) a new volume, Kitchen Set untouched.
    $this->service->saveRab($quotation->fresh(), rabFlowItems([
        'Meja' => ['unit_price' => 900_000],
        'Lemari' => ['dim_width_height' => 2, 'qty' => 4],
    ]));
    $quotation = $this->service->submit($quotation->fresh());
    $kitchen = $quotation->items()->where('description', 'Kitchen Set')->sole();

    expect($this->service->carriedMarks($quotation))->toBe([$kitchen->id => 1]);

    // Marking a carried item, or skipping a changed one, is refused.
    $all = $quotation->items()->get()->map(fn ($item) => ['item_id' => $item->id, 'verdict' => 'OK'])->all();
    expect(fn () => $this->service->review($quotation, ['decision' => 'approve', 'items' => $all], $this->pm))
        ->toThrow(ValidationException::class, 'sudah ✔ di versi 1');
    $onlyMeja = array_values(array_filter(rabFlowMarks($quotation), fn ($mark) => $mark['item_id'] !== $quotation->items()->where('description', 'Lemari')->value('id')));
    expect(fn () => $this->service->review($quotation, ['decision' => 'approve', 'items' => $onlyMeja], $this->pm))
        ->toThrow(ValidationException::class);

    $this->actingAs($this->pm)->post(route('quotations.review', $quotation), ['decision' => 'approve', 'items' => rabFlowMarks($quotation)])
        ->assertSessionHasNoErrors();

    $reviews = QuotationItemReview::where('version', 2)->get()->keyBy('item_description');
    expect($quotation->fresh()->status)->toBe(QuotationStatus::ReadyToSend)
        ->and($reviews)->toHaveCount(3)
        ->and($reviews['Kitchen Set']->carried_from_version)->toBe(1)
        ->and($reviews['Kitchen Set']->verdict)->toBe('OK')
        ->and($reviews['Meja']->carried_from_version)->toBeNull()
        ->and($reviews['Lemari']->carried_from_version)->toBeNull()
        ->and(AuditLog::where('action', 'quotation.pm_approved')->sole()->new_values['items_carried'])->toBe(1);
});

test('after a client rejection an unchanged RAB needs no marks, and carried ✔ keep the version they were checked in', function () {
    $quotation = rabFlowSubmitted($this);
    reviewQuotation($quotation->fresh(), $this->pm, ['Meja' => 'Harga terlalu mahal']);
    $this->service->saveRab($quotation->fresh(), rabFlowItems(['Meja' => ['unit_price' => 900_000]]));
    $this->service->submit($quotation->fresh());
    reviewQuotation($quotation->fresh(), $this->pm);
    rabFlowSend($this, $quotation);
    $this->service->clientReject($quotation->fresh(), $this->marketing, 'Minta skema DP lebih kecil.');

    // v3 — only the payment scheme would change; the items are identical to v2.
    $quotation = $this->service->submit($quotation->fresh());
    $carried = collect($this->service->carriedMarks($quotation))
        ->mapWithKeys(fn (int $version, int $itemId) => [QuotationItem::find($itemId)->description => $version])
        ->all();
    expect($carried)->toBe(['Kitchen Set' => 1, 'Meja' => 2, 'Lemari' => 1]);

    $this->service->review($quotation, ['decision' => 'approve', 'items' => []], $this->pm);
    expect($quotation->fresh()->status)->toBe(QuotationStatus::ReadyToSend)
        ->and(QuotationItemReview::where('version', 3)->whereNotNull('carried_from_version')->count())->toBe(3);
});

test('the quotation page carries the locked ✔ for the reviewer', function () {
    $quotation = rabFlowSubmitted($this);
    reviewQuotation($quotation->fresh(), $this->pm, ['Meja' => 'x']);
    $this->service->saveRab($quotation->fresh(), rabFlowItems(['Meja' => ['unit_price' => 1]]));
    $quotation = $this->service->submit($quotation->fresh());
    $kitchen = $quotation->items()->where('description', 'Kitchen Set')->value('id');

    $this->actingAs($this->pm)->get(route('quotations.show', $quotation))
        ->assertInertia(fn (Assert $page) => $page->where('reviewStage', 'PM')->where("carriedMarks.{$kitchen}", 1));
});

// ── K8: the Sprint 23 data migration ─────────────────────────────────────

test('the migration moves RAB waiting for the CEO or for "Kirim ke Marketing" to Marketing, audited, and back', function () {
    $migration = require database_path('migrations/2026_10_14_000308_move_quotations_off_ceo_approval.php');
    $waitingCeo = Quotation::factory()->create(['status' => 'WAITING_CEO']);
    $approved = Quotation::factory()->create(['status' => 'APPROVED_INTERNAL']);
    $draft = Quotation::factory()->create(['status' => 'DRAFT']);

    $migration->up();

    expect(DB::table('quotations')->find($waitingCeo->id)->status)->toBe('READY_TO_SEND')
        ->and(DB::table('quotations')->find($approved->id)->status)->toBe('READY_TO_SEND')
        ->and(DB::table('quotations')->find($draft->id)->status)->toBe('DRAFT');

    $logs = AuditLog::where('action', 'quotation.ceo_stage_removed')->get()->keyBy('model_id');
    expect($logs)->toHaveCount(2)
        ->and($logs[$waitingCeo->id]->old_values['status'])->toBe('WAITING_CEO')
        ->and($logs[$waitingCeo->id]->user_id)->toBeNull()
        ->and($logs[$approved->id]->new_values['status'])->toBe('READY_TO_SEND');

    $migration->down();
    expect(DB::table('quotations')->find($waitingCeo->id)->status)->toBe('WAITING_CEO')
        ->and(DB::table('quotations')->find($approved->id)->status)->toBe('APPROVED_INTERNAL')
        ->and(AuditLog::where('action', 'quotation.ceo_stage_removed')->count())->toBe(2);
});

// ── K9: the CEO's note ───────────────────────────────────────────────────

test('the CEO adds an information-only note that reaches Estimator, PM and Marketing', function () {
    $quotation = rabFlowSubmitted($this);
    reviewQuotation($quotation->fresh(), $this->pm);

    $this->actingAs($this->ceo)->post(route('quotations.notes.store', $quotation), ['note' => ''])->assertSessionHasErrors('note');
    $this->actingAs($this->ceo)->post(route('quotations.notes.store', $quotation), ['note' => 'Cek ulang ongkos kirim ke Siak.'])
        ->assertSessionHasNoErrors();

    $note = QuotationNote::sole();
    expect($note->only(['kind', 'version', 'author_id', 'note']))->toBe(['kind' => 'CEO', 'version' => 1, 'author_id' => $this->ceo->id, 'note' => 'Cek ulang ongkos kirim ke Siak.'])
        // Information only — the RAB stays where it was.
        ->and($quotation->fresh()->status)->toBe(QuotationStatus::ReadyToSend)
        ->and(Notification::where('type', 'quotation_note_added')->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$this->estimator->id, $this->pm->id, $this->marketing->id])->sort()->values()->all())
        ->and(fn () => $note->update(['note' => 'x']))->toThrow(LogicException::class)
        ->and(fn () => $note->delete())->toThrow(LogicException::class);
});

test('only the CEO writes notes; the people working the RAB read them', function () {
    $quotation = rabFlowSubmitted($this);
    $this->service->addCeoNote($quotation, 'Catatan CEO.', $this->ceo);

    foreach (['MARKETING', 'ESTIMATOR', 'PM'] as $role) {
        $this->actingAs(rabFlowUser($role))->post(route('quotations.notes.store', $quotation), ['note' => 'x'])->assertForbidden();
    }

    $this->actingAs($this->estimator)->get(route('quotations.show', $quotation))
        ->assertInertia(fn (Assert $page) => $page->has('notes', 1)->where('notes.0.author.name', $this->ceo->name)->where('canAddNote', false));
    $this->actingAs($this->ceo)->get(route('quotations.show', $quotation))
        ->assertInertia(fn (Assert $page) => $page->where('canAddNote', true));
    $this->actingAs(rabFlowUser('FINANCE'))->get(route('quotations.show', $quotation))
        ->assertInertia(fn (Assert $page) => $page->has('notes', 0));

    $quotation->update(['status' => QuotationStatus::Cancelled->value]);
    expect(fn () => $this->service->addCeoNote($quotation->fresh(), 'Telat.', $this->ceo))->toThrow(ValidationException::class);
});

// ── K11: "Tidak Setuju" on the client's link ─────────────────────────────

test('the client declines on the link with a mandatory reason — Marketing gets a P1 and a queue', function () {
    $quotation = rabFlowSubmitted($this);
    reviewQuotation($quotation->fresh(), $this->pm);
    $token = rabFlowSend($this, $quotation);

    $this->post(route('public.quotation.reject', $token), ['note' => ''])->assertSessionHasErrors('note');
    $this->post(route('public.quotation.reject', $token), ['note' => str_repeat('a', QuotationNote::MAX_LENGTH + 1)])->assertSessionHasErrors('note');
    expect($quotation->fresh()->status)->toBe(QuotationStatus::SentToClient);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->withHeader('User-Agent', 'Mozilla/5.0 (Android)')
        ->post(route('public.quotation.reject', $token), ['note' => 'Harga lemari terlalu tinggi.'])
        ->assertSessionHasNoErrors();

    expect($quotation->fresh()->status)->toBe(QuotationStatus::ClientRejected)
        ->and(QuotationNote::sole()->only(['kind', 'author_id', 'version', 'note']))->toBe(['kind' => 'CLIENT', 'author_id' => null, 'version' => 1, 'note' => 'Harga lemari terlalu tinggi.']);

    $log = AuditLog::where('action', 'quotation.client_declined')->sole();
    expect($log->user_id)->toBeNull()
        ->and($log->ip_address)->toBe('203.0.113.9')
        ->and($log->new_values['user_agent'])->toBe('Mozilla/5.0 (Android)')
        ->and($log->new_values['status'])->toBe('CLIENT_REJECTED');

    $notification = Notification::where('user_id', $this->marketing->id)->where('type', 'quotation_client_rejected')->sole();
    expect(NotificationType::QuotationClientRejected->priority())->toBe(NotificationPriority::ClientWaiting)
        ->and($notification->message)->toContain('Harga lemari terlalu tinggi.');

    $group = collect(app(ActionInboxService::class)->for($this->marketing, fresh: true))->firstWhere('key', 'quotation-client-rejected');
    expect($group['count'])->toBe(1)->and($group['routeName'])->toBe('quotations.index');
    $this->actingAs($this->marketing)->get(route('quotations.index', ['status' => 'CLIENT_REJECTED']))
        ->assertInertia(fn (Assert $page) => $page->where('quotations.total', 1));

    // The link now says so, and can neither approve nor decline again; the client's note isn't echoed back.
    auth()->logout();
    $this->get(route('public.quotation.show', $token))
        ->assertInertia(fn (Assert $page) => $page->where('state', 'rejected')->missing('quotation.note')->missing('quotation.notes'));
    $this->post(route('public.quotation.approve', $token), ['agree' => true])->assertSessionHasErrors('agree');
    $this->post(route('public.quotation.reject', $token), ['note' => 'Lagi.'])->assertSessionHasErrors('note');
});

test('an expired, approved or outdated link cannot decline', function () {
    $quotation = rabFlowSubmitted($this);
    reviewQuotation($quotation->fresh(), $this->pm);
    $token = rabFlowSend($this, $quotation);
    $quotation->update(['valid_until' => now('Asia/Jakarta')->subDay()->toDateString()]);

    $this->post(route('public.quotation.reject', $token), ['note' => 'Mahal.'])
        ->assertSessionHasErrors(['note' => 'Masa berlaku penawaran ini sudah habis — silakan hubungi Marketing kami.']);

    $quotation->update(['valid_until' => now('Asia/Jakarta')->addDays(5)->toDateString()]);
    $this->service->clientApprove($quotation->fresh()->currentShareLink(), true, '127.0.0.1', 'Test');
    $this->post(route('public.quotation.reject', $token), ['note' => 'Mahal.'])->assertSessionHasErrors('note');

    expect($quotation->fresh()->status)->toBe(QuotationStatus::ClientApproved)
        ->and(QuotationNote::count())->toBe(0);
});

test('the reject route is public, throttled and token-shaped like approve', function () {
    $route = Route::getRoutes()->getByName('public.quotation.reject');

    expect($route->gatherMiddleware())->toContain('throttle:30,1')
        ->and($route->uri())->toBe('penawaran/{token}/tolak')
        ->and($route->wheres['token'])->toBe('[A-Za-z0-9]{48}');
});

test('Marketing adds what is missing and forwards the rejection to the Estimator as a new version', function () {
    $quotation = rabFlowSubmitted($this);
    reviewQuotation($quotation->fresh(), $this->pm);
    $token = rabFlowSend($this, $quotation);
    $this->post(route('public.quotation.reject', $token), ['note' => 'Harga lemari terlalu tinggi.']);

    $this->actingAs($this->estimator)->post(route('quotations.forwardToEstimator', $quotation), ['note' => 'x'])->assertForbidden();
    $this->actingAs($this->marketing)->post(route('quotations.forwardToEstimator', $quotation), ['note' => ''])->assertSessionHasErrors('note');
    $this->actingAs($this->marketing)->post(route('quotations.forwardToEstimator', $quotation), ['note' => 'Klien mau HPL, budget maks 20 jt.'])
        ->assertSessionHasNoErrors();

    $quotation->refresh();
    $revision = QuotationRevision::sole();
    $approval = QuotationApproval::where('approver_role', 'CLIENT')->sole();
    expect($quotation->status)->toBe(QuotationStatus::Draft)
        ->and($quotation->version)->toBe(2)
        ->and($revision->reason)->toBe(QuotationRevision::REASON_CLIENT_REJECTED)
        ->and($revision->note)->toBe('Klien: Harga lemari terlalu tinggi. — Marketing: Klien mau HPL, budget maks 20 jt.')
        ->and($approval->only(['approver_id', 'status', 'version', 'note']))->toBe(['approver_id' => $this->marketing->id, 'status' => 'REJECTED', 'version' => 1, 'note' => 'Harga lemari terlalu tinggi.'])
        ->and(QuotationNote::orderBy('id')->pluck('kind')->all())->toBe(['CLIENT', 'MARKETING'])
        ->and(Notification::where('user_id', $this->estimator->id)->where('type', 'quotation_client_rejected')->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'quotation.client_rejection_forwarded')->sole()->user_id)->toBe($this->marketing->id);

    // The client's old link only says the offer was updated.
    $this->get(route('public.quotation.show', $token))->assertInertia(fn (Assert $page) => $page->where('state', 'outdated'));

    // Forwarding is only possible from CLIENT_REJECTED.
    $this->actingAs($this->marketing)->post(route('quotations.forwardToEstimator', $quotation), ['note' => 'Lagi.'])->assertSessionHasErrors('status');
});

test('Marketing can cancel a declined RAB when the client walks away', function () {
    $quotation = rabFlowSubmitted($this);
    reviewQuotation($quotation->fresh(), $this->pm);
    $token = rabFlowSend($this, $quotation);
    $this->service->clientDecline($quotation->fresh()->currentShareLink(), 'Tidak jadi.', 'Test');

    $this->actingAs($this->marketing)->post(route('quotations.cancel', $quotation), ['reason' => 'Klien mundur.'])->assertSessionHasNoErrors();

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Cancelled);
    $this->get(route('public.quotation.show', $token))->assertInertia(fn (Assert $page) => $page->where('state', 'unavailable'));
});
