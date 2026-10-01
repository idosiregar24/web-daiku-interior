<?php

use App\Enums\DesignStatus;
use App\Enums\QuotationStatus;
use App\Models\AuditLog;
use App\Models\Design;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Quotation;
use App\Models\QuotationApproval;
use App\Models\QuotationItem;
use App\Models\QuotationRevision;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\QuotationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Sprint 9 decision #3 — the client's rejection (PRD §6.2), the revision
 * history of every rejected version (PRD §4.3 "Versi Revisi") and the
 * 14-day validity period (PRD §4.3 "Validity Period").
 */
beforeEach(fn () => $this->seed(RoleSeeder::class));

function revisionUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** A quotation that already cleared CEO → PM, with two RAB items (total 7.000.000). */
function sentQuotation(array $attributes = []): Quotation
{
    $quotation = Quotation::factory()->create([
        'status' => QuotationStatus::SentToClient->value,
        'total_amount' => 7_000_000,
        'valid_until' => now('Asia/Jakarta')->addDays(10)->toDateString(),
        ...$attributes,
    ]);

    QuotationItem::factory()->create([
        'quotation_id' => $quotation->id,
        'description' => 'Kitchen Set',
        'qty' => 1,
        'unit' => 'set',
        'unit_price' => 5_000_000,
        'total_price' => 5_000_000,
        'sort_order' => 0,
    ]);
    QuotationItem::factory()->create([
        'quotation_id' => $quotation->id,
        'description' => 'Meja',
        'qty' => 2,
        'unit' => 'unit',
        'unit_price' => 1_000_000,
        'total_price' => 2_000_000,
        'sort_order' => 1,
    ]);

    return $quotation;
}

// ── Klien menolak penawaran (quotations.clientReject) ───────────────────

test('CEO and Marketing can record the client rejecting an offer', function (string $role) {
    $quotation = sentQuotation();

    $this->actingAs(revisionUser($role))
        ->post(route('quotations.clientReject', ['quotation' => $quotation->id]), ['note' => 'Klien minta harga turun.'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $quotation->refresh();
    expect($quotation->status)->toBe(QuotationStatus::Draft)
        ->and($quotation->version)->toBe(2)
        ->and($quotation->valid_until)->toBeNull();
})->with(['CEO', 'MARKETING']);

test('other roles cannot record a client rejection', function (string $role) {
    $quotation = sentQuotation();

    $this->actingAs(revisionUser($role))
        ->post(route('quotations.clientReject', ['quotation' => $quotation->id]), ['note' => 'x'])
        ->assertForbidden();

    expect($quotation->fresh()->status)->toBe(QuotationStatus::SentToClient);
})->with(['ESTIMATOR', 'PM', 'DESIGNER', 'FIELD_STAFF']);

test('a client rejection requires a reason', function () {
    $quotation = sentQuotation();

    $this->actingAs(revisionUser('MARKETING'))
        ->post(route('quotations.clientReject', ['quotation' => $quotation->id]), ['note' => ''])
        ->assertSessionHasErrors('note');

    expect($quotation->fresh()->status)->toBe(QuotationStatus::SentToClient)
        ->and(QuotationRevision::count())->toBe(0);
});

test('a client rejection is only possible while the offer is SENT_TO_CLIENT', function (string $status) {
    $quotation = Quotation::factory()->create(['status' => $status]);

    $this->actingAs(revisionUser('MARKETING'))
        ->post(route('quotations.clientReject', ['quotation' => $quotation->id]), ['note' => 'Terlalu mahal.'])
        ->assertSessionHasErrors('status');

    expect($quotation->fresh()->status->value)->toBe($status)
        ->and(QuotationApproval::count())->toBe(0);
})->with(['DRAFT', 'SUBMITTED', 'CEO_REVIEW', 'APPROVED']);

test('a client rejection records a CLIENT approval row, a revision snapshot, an audit entry and notifies the estimator and marketing', function () {
    $ceo = revisionUser('CEO');
    $quotation = sentQuotation();

    app(QuotationService::class)->clientReject($quotation, $ceo, 'Klien minta ganti material.');

    $approval = QuotationApproval::sole();
    expect($approval->approver_role)->toBe('CLIENT')
        ->and($approval->approver_id)->toBe($ceo->id)
        ->and($approval->status)->toBe('REJECTED')
        ->and($approval->version)->toBe(1)
        ->and($approval->note)->toBe('Klien minta ganti material.');

    $revision = QuotationRevision::sole();
    expect($revision->quotation_id)->toBe($quotation->id)
        ->and($revision->version)->toBe(1)
        ->and((float) $revision->total_amount)->toBe(7_000_000.0)
        ->and($revision->reason)->toBe(QuotationRevision::REASON_CLIENT_REJECTED)
        ->and($revision->note)->toBe('Klien minta ganti material.')
        ->and($revision->closed_by)->toBe($ceo->id)
        ->and($revision->items)->toBe([
            ['description' => 'Kitchen Set', 'qty' => 1, 'unit' => 'set', 'unit_price' => '5000000.00', 'total_price' => '5000000.00'],
            ['description' => 'Meja', 'qty' => 2, 'unit' => 'unit', 'unit_price' => '1000000.00', 'total_price' => '2000000.00'],
        ]);

    // The new DRAFT keeps the items so the Estimator revises from them.
    expect($quotation->fresh()->items)->toHaveCount(2);

    $log = AuditLog::where('action', 'quotation.client_rejected')->sole();
    expect($log->user_id)->toBe($ceo->id)
        ->and($log->old_values['status'])->toBe('SENT_TO_CLIENT')
        ->and($log->old_values['version'])->toBe(1)
        ->and($log->new_values['status'])->toBe('DRAFT')
        ->and($log->new_values['version'])->toBe(2)
        ->and($log->new_values['valid_until'])->toBeNull();

    foreach ([$quotation->creator, $quotation->lead->assignee] as $recipient) {
        $notification = Notification::where('user_id', $recipient->id)->where('type', 'quotation_rejected')->sole();
        expect($notification->title)->toBe('Penawaran Ditolak Klien')
            ->and($notification->metadata['quotation_id'])->toBe($quotation->id);
    }
});

test('the marketing user who records the rejection is not notified about their own action', function () {
    $marketing = revisionUser('MARKETING');
    $lead = Lead::factory()->create(['assigned_to' => $marketing->id]);
    $quotation = sentQuotation(['lead_id' => $lead->id]);

    app(QuotationService::class)->clientReject($quotation, $marketing, 'Klien menunda.');

    expect(Notification::where('user_id', $marketing->id)->exists())->toBeFalse()
        ->and(Notification::where('user_id', $quotation->created_by)->where('type', 'quotation_rejected')->exists())->toBeTrue();
});

test('a second client rejection of the same version is refused', function () {
    $marketing = revisionUser('MARKETING');
    $quotation = sentQuotation();
    $stale = Quotation::find($quotation->id);

    app(QuotationService::class)->clientReject($quotation, $marketing, 'Pertama.');

    expect(fn () => app(QuotationService::class)->clientReject($stale, $marketing, 'Kedua.'))
        ->toThrow(ValidationException::class);

    expect(QuotationRevision::count())->toBe(1)
        ->and($quotation->fresh()->version)->toBe(2);
});

// ── Riwayat revisi (CEO/PM rejections too) ──────────────────────────────

test('CEO and PM rejections also close the version into the revision history', function (string $gate, string $fromStatus, string $reason) {
    $quotation = sentQuotation(['status' => $fromStatus, 'valid_until' => null]);
    $service = app(QuotationService::class);

    $gate === 'CEO'
        ? $service->ceoDecision($quotation, 'reject', revisionUser('CEO'), 'Margin terlalu tipis.')
        : $service->pmDecision($quotation, 'reject', revisionUser('PM'), 'Volume tidak realistis.');

    $revision = QuotationRevision::sole();
    expect($revision->reason)->toBe($reason)
        ->and($revision->version)->toBe(1)
        ->and($revision->items)->toHaveCount(2)
        ->and($quotation->fresh()->version)->toBe(2)
        ->and($quotation->fresh()->status)->toBe(QuotationStatus::Draft);
})->with([
    'CEO' => ['CEO', 'SUBMITTED', 'CEO_REJECTED'],
    'PM' => ['PM', 'CEO_REVIEW', 'PM_REJECTED'],
]);

test('approvals never create a revision or bump the version', function () {
    $quotation = sentQuotation(['status' => QuotationStatus::Submitted->value, 'valid_until' => null]);
    $service = app(QuotationService::class);

    $service->ceoDecision($quotation, 'approve', revisionUser('CEO'));
    $service->pmDecision($quotation, 'approve', revisionUser('PM'));

    expect(QuotationRevision::count())->toBe(0)
        ->and($quotation->fresh()->version)->toBe(1)
        ->and(QuotationApproval::pluck('version')->all())->toBe([1, 1]);
});

test('quotation revisions are append-only', function () {
    $quotation = sentQuotation();
    app(QuotationService::class)->clientReject($quotation, revisionUser('CEO'), 'Revisi.');
    $revision = QuotationRevision::sole();

    expect(fn () => $revision->update(['note' => 'diubah']))->toThrow(LogicException::class)
        ->and(fn () => $revision->delete())->toThrow(LogicException::class)
        ->and(QuotationRevision::sole()->note)->toBe('Revisi.');
});

test('the quotation page shows the revision history and client-decision permission', function () {
    $marketing = revisionUser('MARKETING');
    $quotation = sentQuotation();
    app(QuotationService::class)->clientReject($quotation, $marketing, 'Minta diskon.');

    $this->actingAs($marketing)
        ->get(route('quotations.show', ['quotation' => $quotation->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Quotation/Show')
            ->where('canClientDecide', true)
            ->where('validityDays', QuotationService::VALIDITY_DAYS)
            ->where('quotation.version', 2)
            ->has('quotation.revisions', 1)
            ->where('quotation.revisions.0.version', 1)
            ->where('quotation.revisions.0.reason', 'CLIENT_REJECTED')
            ->where('quotation.revisions.0.closer.name', $marketing->name)
            ->has('quotation.revisions.0.items', 2)
            ->where('quotation.approvals.0.approver_role', 'CLIENT'));
});

test('roles outside CEO/Marketing do not get the client-decision action', function (string $role) {
    $quotation = sentQuotation();

    $this->actingAs(revisionUser($role))
        ->get(route('quotations.show', ['quotation' => $quotation->id]))
        ->assertInertia(fn (Assert $page) => $page->where('canClientDecide', false));
})->with(['ESTIMATOR', 'PM', 'FINANCE']);

// ── Masa berlaku (valid_until) ──────────────────────────────────────────

test('PM approval sets valid_until to today + 14 days (Asia/Jakarta)', function () {
    // 05:00 WIB on 1 Oct is still 30 Sep in UTC — the Jakarta date must win.
    Carbon::setTestNow(Carbon::parse('2026-10-01 05:00:00', 'Asia/Jakarta'));

    $quotation = Quotation::factory()->create(['status' => QuotationStatus::CeoReview->value]);

    app(QuotationService::class)->pmDecision($quotation, 'approve', revisionUser('PM'));

    $quotation->refresh();
    expect($quotation->status)->toBe(QuotationStatus::SentToClient)
        ->and($quotation->valid_until->toDateString())->toBe('2026-10-15')
        ->and(QuotationService::VALIDITY_DAYS)->toBe(14);

    $log = AuditLog::where('action', 'quotation.pm_approved')->sole();
    expect($log->new_values['valid_until'])->toStartWith('2026-10-15');

    Carbon::setTestNow();
});

test('CEO approval does not start the validity period', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Submitted->value]);

    app(QuotationService::class)->ceoDecision($quotation, 'approve', revisionUser('CEO'));

    expect($quotation->fresh()->valid_until)->toBeNull();
});

test('an expired offer can still be confirmed as a deal (warning only)', function () {
    $marketing = revisionUser('MARKETING');
    $pm = revisionUser('PM');
    $lead = Lead::factory()->create(['status' => 'DEAL_DESAIN']);
    $quotation = sentQuotation(['lead_id' => $lead->id, 'valid_until' => now()->subDays(3)->toDateString()]);

    $this->actingAs($marketing)->post(route('crm.leads.confirmDeal', ['lead' => $lead->id]), [
        'name' => 'Proyek Kedaluwarsa',
        'pm_id' => $pm->id,
        'start_date' => now()->toDateString(),
        'contract_value' => 7_000_000,
    ])->assertSessionHasNoErrors();

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Approved);
});

test('an expired offer can still be rejected by the client', function () {
    $quotation = sentQuotation(['valid_until' => now()->subDay()->toDateString()]);

    app(QuotationService::class)->clientReject($quotation, revisionUser('CEO'), 'Sudah lewat, minta hitung ulang.');

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Draft);
});

test('the quotation PDF states the validity date, or the rule while still a draft', function () {
    $sent = sentQuotation(['valid_until' => '2026-10-14']);
    $draft = Quotation::factory()->create(['status' => QuotationStatus::Draft->value]);

    $render = fn (Quotation $quotation) => view('pdf.quotation', [
        'quotation' => $quotation->load(['lead', 'items']),
        'siteSettings' => SiteSetting::current(),
        'validityDays' => QuotationService::VALIDITY_DAYS,
    ])->render();

    expect($render($sent))->toContain('Berlaku Sampai')->toContain('14 Oktober 2026')
        ->and($render($draft))->toContain('14 hari sejak penawaran dikirim');
});

test('the CRM lead list carries the quotation state for the deal / client-rejection actions', function () {
    $quotation = sentQuotation();

    $this->actingAs(revisionUser('MARKETING'))
        ->get(route('crm.leads.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('CRM/Index')
            ->where('leads.data.0.quotation.id', $quotation->id)
            ->where('leads.data.0.quotation.status', 'SENT_TO_CLIENT')
            ->has('leads.data.0.quotation.valid_until'));
});

test('the design of a client-rejected offer steps back to PEMBUATAN_PENAWARAN', function () {
    $quotation = sentQuotation();
    $design = Design::factory()->create([
        'lead_id' => $quotation->lead_id,
        'client_acc' => true,
        'status' => DesignStatus::WaitingAccPenawaran->value,
    ]);

    app(QuotationService::class)->clientReject($quotation, revisionUser('CEO'), 'Revisi.');

    expect($design->fresh()->status)->toBe(DesignStatus::PembuatanPenawaran);
});
