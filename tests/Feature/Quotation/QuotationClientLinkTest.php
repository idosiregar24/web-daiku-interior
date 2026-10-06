<?php

use App\Enums\LeadStatus;
use App\Enums\QuotationStatus;
use App\Events\QuotationClientApproved;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Quotation;
use App\Models\QuotationApproval;
use App\Models\QuotationItem;
use App\Models\QuotationItemReview;
use App\Models\User;
use App\Services\QuotationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 12 Sub 5 — the client's offer link (decisions #13–#14): opened
 * without login, approved with a mandatory tick, whitelisted data only.
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->marketing = linkUser('MARKETING');
    $this->estimator = linkUser('ESTIMATOR');
    $this->lead = Lead::factory()->create([
        'status' => LeadStatus::DealDesain->value,
        'assigned_to' => $this->marketing->id,
        'contact' => '0812-3456-7890',
        'address' => 'Jl. Tegal Sari 12',
    ]);
});

function linkUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** A RAB with two items, PM-reviewed, sent to the client by Marketing (the real path). */
function sentOffer(object $test, string $type = 'PROYEK'): Quotation
{
    $quotation = Quotation::factory()->create([
        'lead_id' => $test->lead->id,
        'type' => $type,
        'status' => QuotationStatus::ApprovedInternal->value,
        'created_by' => $test->estimator->id,
        'requested_by' => $test->marketing->id,
        'request_note' => 'CATATAN-INTERNAL-PERMINTAAN',
        'total_amount' => 7_000_000,
        'items_total' => 7_000_000,
    ]);
    QuotationItem::factory()->create(['quotation_id' => $quotation->id, 'description' => 'Kitchen Set', 'qty' => 1, 'unit_price' => 5_000_000, 'total_price' => 5_000_000]);
    QuotationItem::factory()->create(['quotation_id' => $quotation->id, 'description' => 'Meja', 'qty' => 2, 'unit_price' => 1_000_000, 'total_price' => 2_000_000]);
    // What submit() adds when the Estimator drafted no scheme.
    $quotation->paymentTerms()->create(['sequence' => 1, 'label' => 'Pembayaran penuh', 'percentage' => 100, 'amount' => 7_000_000, 'trigger' => 'DI_MUKA']);

    QuotationItemReview::create([
        'quotation_id' => $quotation->id, 'version' => 1, 'quotation_item_id' => $quotation->items()->first()->id,
        'item_description' => 'Kitchen Set', 'stage' => 'PM', 'reviewer_id' => linkUser('PM')->id,
        'verdict' => 'OK', 'note' => 'CATATAN-INTERNAL-REVIEW',
    ]);
    QuotationApproval::create(['quotation_id' => $quotation->id, 'version' => 1, 'approver_id' => linkUser('CEO')->id, 'approver_role' => 'CEO', 'status' => 'APPROVED', 'note' => 'CATATAN-INTERNAL-CEO']);

    $quotation->update(['status' => QuotationStatus::ReadyToSend->value]);

    return app(QuotationService::class)->sendToClient($quotation, $test->marketing);
}

// ── Link ─────────────────────────────────────────────────────────────────

test('sending to the client creates an unguessable link for that version', function () {
    $quotation = sentOffer($this);
    $link = $quotation->currentShareLink();

    expect($link->token)->toHaveLength(48)->toMatch('/^[A-Za-z0-9]{48}$/')
        ->and($link->version)->toBe(1)
        ->and($link->sent_by)->toBe($this->marketing->id)
        ->and($link->url())->toEndWith('/penawaran/'.$link->token)
        ->and($link->toArray())->not->toHaveKey('token');
});

test('links are append-only', function () {
    $link = sentOffer($this)->currentShareLink();

    expect(fn () => $link->update(['version' => 9]))->toThrow(LogicException::class)
        ->and(fn () => $link->delete())->toThrow(LogicException::class);
});

test('an unknown or malformed token is a 404', function (string $token) {
    sentOffer($this);

    $this->get('/penawaran/'.$token)->assertNotFound();
})->with([str_repeat('a', 48), 'pendek', str_repeat('b', 49)]);

// ── Halaman publik ───────────────────────────────────────────────────────

test('the client opens the offer without logging in, and it is never indexed', function () {
    $quotation = sentOffer($this);

    $this->get(route('public.quotation.show', $quotation->currentShareLink()->token))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Public/Quotation')
            ->where('state', 'open')
            ->where('auth.user', null)
            ->where('quotation.client.name', $this->lead->client_name)
            ->where('quotation.client.address', 'Jl. Tegal Sari 12')
            ->where('quotation.typeLabel', 'RAB Proyek')
            ->where('quotation.total', 7_000_000)
            ->has('quotation.sections.0.items', 2)
            ->has('quotation.paymentTerms', 1));
});

test('the page carries no internal data — only the whitelist', function () {
    $quotation = sentOffer($this);

    $response = $this->get(route('public.quotation.show', $quotation->currentShareLink()->token));
    $content = $response->getContent();

    foreach (['CATATAN-INTERNAL-PERMINTAAN', 'CATATAN-INTERNAL-REVIEW', 'CATATAN-INTERNAL-CEO', $this->estimator->email, $this->marketing->email, '0812-3456-7890'] as $secret) {
        expect($content)->not->toContain($secret);
    }

    $response->assertInertia(fn (Assert $page) => $page
        ->missing('quotation.request_note')
        ->missing('quotation.approvals')
        ->missing('quotation.item_reviews')
        ->missing('quotation.created_by')
        ->missing('quotation.lead_id')
        ->missing('quotation.sections.0.items.0.id')
        ->missing('quotation.sections.0.items.0.unit_id'));

    $keys = array_keys($response->viewData('page')['props']['quotation']);
    expect($keys)->toEqualCanonicalizing([
        'company', 'client', 'type', 'typeLabel', 'number', 'version', 'sentAt', 'validUntil', 'sections',
        'itemsTotal', 'discount', 'roundedTotal', 'total', 'paymentTerms', 'approvedAt',
        // Sprint 15 — the company letter (QuotationLetter), client-facing text only.
        'letter',
    ]);

    expect(array_keys($response->viewData('page')['props']['quotation']['letter']))->toEqualCanonicalizing([
        'kind', 'company', 'signer', 'number', 'draft', 'date', 'subject', 'recipient', 'meta', 'intro',
        'showGroups', 'groups', 'totals', 'total', 'totalInWords', 'notes', 'paymentTerms', 'closing', 'stamp',
    ]);
});

test('the public routes are throttled', function () {
    $token = sentOffer($this)->currentShareLink()->token;

    foreach (range(1, 30) as $i) {
        $this->get(route('public.quotation.show', $token))->assertOk();
    }

    $this->get(route('public.quotation.show', $token))->assertStatus(429);
});

// ── Persetujuan ──────────────────────────────────────────────────────────

test('approving needs the tick', function () {
    $quotation = sentOffer($this);
    $token = $quotation->currentShareLink()->token;

    $this->post(route('public.quotation.approve', $token), [])->assertSessionHasErrors(['agree' => 'Centang pernyataan persetujuan terlebih dahulu.']);
    $this->post(route('public.quotation.approve', $token), ['agree' => false])->assertSessionHasErrors('agree');

    expect($quotation->fresh()->status)->toBe(QuotationStatus::SentToClient);
});

test('the client approval is recorded with time, IP and device, audited and announced', function () {
    $quotation = sentOffer($this);
    $link = $quotation->currentShareLink();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->withHeader('User-Agent', 'Mozilla/5.0 (iPhone)')
        ->post(route('public.quotation.approve', $link->token), ['agree' => '1'])
        ->assertSessionHasNoErrors();

    $quotation->refresh();
    expect($quotation->status)->toBe(QuotationStatus::ClientApproved)
        ->and($quotation->client_approved_at)->not->toBeNull()
        ->and($quotation->client_approved_ip)->toBe('203.0.113.7')
        ->and($quotation->client_approved_user_agent)->toBe('Mozilla/5.0 (iPhone)')
        ->and($quotation->client_approved_link_id)->toBe($link->id);

    $log = AuditLog::where('action', 'quotation.client_approved')->sole();
    expect($log->user_id)->toBeNull()
        ->and($log->ip_address)->toBe('203.0.113.7')
        ->and($log->new_values['status'])->toBe('CLIENT_APPROVED');

    foreach ([$this->estimator, $this->marketing] as $user) {
        expect(Notification::where('user_id', $user->id)->where('type', 'quotation_client_approved')->exists())->toBeTrue();
    }

    $this->get(route('public.quotation.show', $link->token))
        ->assertInertia(fn (Assert $page) => $page->where('state', 'approved')->whereNot('quotation.approvedAt', null));
});

test('the approval dispatches QuotationClientApproved', function () {
    Event::fake([QuotationClientApproved::class]);
    $quotation = sentOffer($this, 'DESAIN');

    app(QuotationService::class)->clientApprove($quotation->currentShareLink(), true, '10.0.0.1', 'Test');

    Event::assertDispatched(QuotationClientApproved::class, fn (QuotationClientApproved $event) => $event->quotation->is($quotation));
});

test('only a RAB Proyek closes the lead', function (string $type, LeadStatus $expected) {
    $quotation = sentOffer($this, $type);

    app(QuotationService::class)->clientApprove($quotation->currentShareLink(), true, '10.0.0.1', 'Test');

    expect($this->lead->fresh()->status)->toBe($expected);
})->with([
    ['PROYEK', LeadStatus::Closing],
    ['SURVEY', LeadStatus::DealDesain],
    ['DESAIN', LeadStatus::DealDesain],
]);

test('a link cannot approve twice', function () {
    $link = sentOffer($this)->currentShareLink();
    $service = app(QuotationService::class);

    $service->clientApprove($link, true, '10.0.0.1', 'Test');

    expect(fn () => $service->clientApprove($link->fresh(), true, '10.0.0.1', 'Test'))
        ->toThrow(ValidationException::class, 'Penawaran ini sudah disetujui.');
});

test('an older version link says the offer was updated and cannot approve', function () {
    $quotation = sentOffer($this);
    $oldLink = $quotation->currentShareLink();

    // The client asked for a revision over WhatsApp — v2 is drafted, reviewed and sent again.
    app(QuotationService::class)->clientReject($quotation, $this->marketing, 'Minta ganti HPL.');
    $quotation->refresh()->update(['status' => QuotationStatus::ReadyToSend->value]);
    $quotation = app(QuotationService::class)->sendToClient($quotation, $this->marketing);

    $this->get(route('public.quotation.show', $oldLink->token))->assertInertia(fn (Assert $page) => $page->where('state', 'outdated'));
    $this->post(route('public.quotation.approve', $oldLink->token), ['agree' => true])->assertSessionHasErrors('agree');

    $newLink = $quotation->currentShareLink();
    expect($newLink->version)->toBe(2)->and($newLink->token)->not->toBe($oldLink->token);
    $this->get(route('public.quotation.show', $newLink->token))->assertInertia(fn (Assert $page) => $page->where('state', 'open'));
});

test('a cancelled offer is unavailable', function () {
    $quotation = sentOffer($this);
    app(QuotationService::class)->cancel($quotation, $this->marketing, 'Klien batal.');

    $this->get(route('public.quotation.show', $quotation->currentShareLink()->token))
        ->assertInertia(fn (Assert $page) => $page->where('state', 'unavailable'));
});

test('an expired offer shows it and cannot be approved', function () {
    $quotation = sentOffer($this);
    $quotation->update(['valid_until' => now('Asia/Jakarta')->subDay()->toDateString()]);

    $this->get(route('public.quotation.show', $quotation->currentShareLink()->token))
        ->assertInertia(fn (Assert $page) => $page->where('state', 'expired'));
});

// ── Halaman internal ─────────────────────────────────────────────────────

test('only CEO and Marketing get the link to copy / WhatsApp', function (string $role, bool $sees) {
    $quotation = sentOffer($this);

    $this->actingAs(linkUser($role))->get(route('quotations.show', $quotation))
        ->assertInertia(fn (Assert $page) => $sees
            ? $page->where('shareUrl', $quotation->currentShareLink()->url())->where('quotation.lead.contact', '0812-3456-7890')
            : $page->where('shareUrl', null));
})->with([['MARKETING', true], ['CEO', true], ['ESTIMATOR', false], ['PM', false], ['FINANCE', false]]);

test('the Sprint 12 link migration rolls back', function () {
    $migration = require database_path('migrations/2026_10_05_092849_create_quotation_share_links_table.php');

    $migration->down();
    expect(Schema::hasTable('quotation_share_links'))->toBeFalse()
        ->and(Schema::hasColumn('quotations', 'client_approved_at'))->toBeFalse();

    $migration->up();
    expect(Schema::hasTable('quotation_share_links'))->toBeTrue()
        ->and(DB::table('quotations')->count())->toBe(0);
});
