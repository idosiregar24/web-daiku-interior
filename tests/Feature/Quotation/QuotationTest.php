<?php

use App\Enums\QuotationStatus;
use App\Enums\QuotationType;
use App\Models\AuditLog;
use App\Models\Design;
use App\Models\LeadSurvey;
use App\Models\Notification;
use App\Models\Quotation;
use App\Models\QuotationApproval;
use App\Models\QuotationItem;
use App\Models\QuotationItemReview;
use App\Models\QuotationRevision;
use App\Models\User;
use App\Services\QuotationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seed(RoleSeeder::class));

test('roles with read access can view the quotation index', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);
    Quotation::factory()->create();

    $this->actingAs($user)->get(route('quotations.index'))->assertOk();
})->with(['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'FINANCE']);

test('roles without access are forbidden from the quotation index', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)->get(route('quotations.index'))->assertForbidden();
})->with(['QA', 'LOGISTICS', 'FIELD_STAFF']);

test('quotation service refuses to create a quotation from a design that is not client-ACC\'d', function () {
    $design = Design::factory()->create(['client_acc' => false]);
    $actor = User::factory()->create();

    expect(fn () => app(QuotationService::class)->createFromDesign($design, $actor))
        ->toThrow(ValidationException::class);
});

test('a design ACC reuses the lead\'s existing project RAB instead of opening a second one', function () {
    // Sprint 12 #6: Marketing may have asked for the project RAB before the design was accepted.
    $design = Design::factory()->create(['client_acc' => true]);
    $existing = Quotation::factory()->create(['lead_id' => $design->lead_id]);
    $actor = User::factory()->create();

    expect(app(QuotationService::class)->createFromDesign($design, $actor)->id)->toBe($existing->id)
        ->and(Quotation::where('lead_id', $design->lead_id)->count())->toBe(1);
});

test('quotation service replaces items and recomputes the total server-side', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft->value]);
    QuotationItem::factory()->create(['quotation_id' => $quotation->id]);

    app(QuotationService::class)->replaceItems($quotation, [
        ['description' => 'Kitchen Set', 'qty' => 2, 'unit_id' => unitId('set'), 'unit_price' => 1_500_000],
        ['description' => 'Meja Kerja', 'qty' => 3, 'unit_id' => unitId('unit'), 'unit_price' => 800_000],
    ]);

    $quotation->refresh();
    expect($quotation->items)->toHaveCount(2)
        ->and((float) $quotation->total_amount)->toBe(2 * 1_500_000 + 3 * 800_000.0);
});

test('quotation service refuses to change items once past DRAFT', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Submitted->value]);

    expect(fn () => app(QuotationService::class)->replaceItems($quotation, [
        ['description' => 'Item', 'qty' => 1, 'unit_id' => unitId('unit'), 'unit_price' => 100_000],
    ]))->toThrow(ValidationException::class);
});

test('quotation service submits a draft with at least one item', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft->value]);
    QuotationItem::factory()->create(['quotation_id' => $quotation->id]);

    app(QuotationService::class)->submit($quotation);

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Submitted);
});

test('quotation service refuses to submit a draft with no items', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft->value]);

    expect(fn () => app(QuotationService::class)->submit($quotation))
        ->toThrow(ValidationException::class);
});

test('roles with read access can view a quotation', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);
    $quotation = Quotation::factory()->create();

    $this->actingAs($user)->get(route('quotations.show', ['quotation' => $quotation->id]))->assertOk();
})->with(['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'FINANCE']);

test('roles without access are forbidden from viewing a quotation', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);
    $quotation = Quotation::factory()->create();

    $this->actingAs($user)->get(route('quotations.show', ['quotation' => $quotation->id]))->assertForbidden();
})->with(['QA', 'LOGISTICS', 'FIELD_STAFF']);

test('estimator can save RAB items', function () {
    $estimator = User::factory()->create();
    $estimator->assignRole('ESTIMATOR');
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft->value]);

    $this->actingAs($estimator)->put(route('quotations.items.update', ['quotation' => $quotation->id]), [
        'items' => [
            ['description' => 'Kitchen Set', 'qty' => 1, 'unit_id' => unitId('set'), 'unit_price' => 5_000_000],
        ],
    ])->assertRedirect();

    expect($quotation->fresh()->items)->toHaveCount(1)
        ->and((float) $quotation->fresh()->total_amount)->toBe(5_000_000.0);
});

test('roles other than ESTIMATOR cannot save RAB items', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft->value]);

    $this->actingAs($ceo)->put(route('quotations.items.update', ['quotation' => $quotation->id]), [
        'items' => [
            ['description' => 'Kitchen Set', 'qty' => 1, 'unit_id' => unitId('set'), 'unit_price' => 5_000_000],
        ],
    ])->assertForbidden();
});

test('estimator can submit a quotation for review', function () {
    $estimator = User::factory()->create();
    $estimator->assignRole('ESTIMATOR');
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft->value]);
    QuotationItem::factory()->create(['quotation_id' => $quotation->id]);

    $this->actingAs($estimator)->post(route('quotations.submit', ['quotation' => $quotation->id]))
        ->assertRedirect();

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Submitted);
});

// ── Sprint 12 #7–#10: review PM / Asisten PM → CEO (RAB Proyek) → Marketing ──

/** A submitted quotation of `$type` with two items: Kitchen Set + Meja. */
function submittedQuotation(string $type = 'PROYEK', string $status = 'SUBMITTED'): Quotation
{
    $quotation = Quotation::factory()->create(['status' => $status, 'type' => $type]);
    QuotationItem::factory()->create(['quotation_id' => $quotation->id, 'description' => 'Kitchen Set']);
    QuotationItem::factory()->create(['quotation_id' => $quotation->id, 'description' => 'Meja']);

    return $quotation;
}

function reviewPayload(Quotation $quotation, string $decision, array $wrong = []): array
{
    return [
        'decision' => $decision,
        'items' => $quotation->items()->get()->map(fn (QuotationItem $item) => [
            'item_id' => $item->id,
            'verdict' => isset($wrong[$item->description]) ? 'SALAH' : 'OK',
            'note' => $wrong[$item->description] ?? null,
        ])->all(),
    ];
}

test('submitting sends the RAB to PM / Asisten PM for review', function () {
    $estimator = User::factory()->create();
    $estimator->assignRole('ESTIMATOR');
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $assistant = User::factory()->create();
    $assistant->assignRole('ASISTEN_PM');
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft->value]);
    QuotationItem::factory()->create(['quotation_id' => $quotation->id]);

    $this->actingAs($estimator)->post(route('quotations.submit', $quotation))->assertSessionHasNoErrors();

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Submitted)
        ->and(Notification::where('type', 'quotation_submitted')->pluck('user_id')->sort()->values()->all())
        ->toBe(collect([$pm->id, $assistant->id])->sort()->values()->all());
});

test('a RAB Jasa Survey / Desain needs only the PM review', function (string $type, string $role) {
    $reviewer = User::factory()->create();
    $reviewer->assignRole($role);
    $quotation = submittedQuotation($type);

    $this->actingAs($reviewer)->post(route('quotations.review', $quotation), reviewPayload($quotation, 'approve'))
        ->assertSessionHasNoErrors();

    expect($quotation->fresh()->status)->toBe(QuotationStatus::ApprovedInternal)
        ->and(QuotationItemReview::where('stage', 'PM')->where('verdict', 'OK')->count())->toBe(2)
        ->and(AuditLog::where('action', 'quotation.pm_approved')->exists())->toBeTrue();
})->with([['SURVEY', 'PM'], ['DESAIN', 'ASISTEN_PM']]);

test('a RAB Proyek goes PM → CEO → approved internally', function () {
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');
    $quotation = submittedQuotation();

    $this->actingAs($pm)->post(route('quotations.review', $quotation), reviewPayload($quotation, 'approve'))->assertSessionHasNoErrors();
    expect($quotation->fresh()->status)->toBe(QuotationStatus::WaitingCeo)
        ->and(Notification::where('user_id', $ceo->id)->where('type', 'quotation_awaiting_ceo')->exists())->toBeTrue();

    // The CEO may approve without marking anything.
    $this->actingAs($ceo)->post(route('quotations.review', $quotation), ['decision' => 'approve'])->assertSessionHasNoErrors();
    expect($quotation->fresh()->status)->toBe(QuotationStatus::ApprovedInternal)
        ->and(QuotationApproval::where('quotation_id', $quotation->id)->pluck('approver_role')->all())->toBe(['PM', 'CEO']);
});

test('the CEO cannot decide before the PM, nor the PM after', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $submitted = submittedQuotation();
    $waitingCeo = submittedQuotation('PROYEK', 'WAITING_CEO');

    $this->actingAs($ceo)->post(route('quotations.review', $submitted), reviewPayload($submitted, 'approve'))
        ->assertSessionHasErrors(['status' => 'RAB ini menunggu review PM / Asisten PM terlebih dahulu.']);
    $this->actingAs($pm)->post(route('quotations.review', $waitingCeo), reviewPayload($waitingCeo, 'approve'))
        ->assertSessionHasErrors(['status' => 'RAB ini menunggu keputusan CEO.']);

    expect($submitted->fresh()->status)->toBe(QuotationStatus::Submitted)
        ->and($waitingCeo->fresh()->status)->toBe(QuotationStatus::WaitingCeo);
});

test('the PM must mark every item, and a ✘ needs a note', function () {
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $quotation = submittedQuotation();
    $first = $quotation->items()->first();

    $this->actingAs($pm)->post(route('quotations.review', $quotation), [
        'decision' => 'approve',
        'items' => [['item_id' => $first->id, 'verdict' => 'OK']],
    ])->assertSessionHasErrors('items');

    $payload = reviewPayload($quotation, 'return', ['Meja' => 'x']);
    $payload['items'][1]['note'] = null;
    $this->actingAs($pm)->post(route('quotations.review', $quotation), $payload)->assertSessionHasErrors("items.{$payload['items'][1]['item_id']}");

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Submitted)
        ->and(QuotationItemReview::count())->toBe(0);
});

test('a ✘ forces the RAB back to the Estimator as a new version', function () {
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $quotation = submittedQuotation();

    $this->actingAs($pm)->post(route('quotations.review', $quotation), reviewPayload($quotation, 'approve', ['Meja' => 'Harga terlalu tinggi']))
        ->assertSessionHasErrors('decision');

    $this->actingAs($pm)->post(route('quotations.review', $quotation), reviewPayload($quotation, 'return', ['Meja' => 'Harga terlalu tinggi']))
        ->assertSessionHasNoErrors();

    $quotation->refresh();
    $wrong = QuotationItemReview::where('verdict', 'SALAH')->sole();

    expect($quotation->status)->toBe(QuotationStatus::Draft)
        ->and($quotation->version)->toBe(2)
        ->and($wrong->only(['version', 'stage', 'item_description', 'note']))->toBe(['version' => 1, 'stage' => 'PM', 'item_description' => 'Meja', 'note' => 'Harga terlalu tinggi'])
        ->and(AuditLog::where('action', 'quotation.pm_returned')->sole()->new_values['items_wrong'])->toBe(1)
        ->and(Notification::where('user_id', $quotation->created_by)->where('type', 'quotation_returned')->exists())->toBeTrue();
});

test('a return without any ✘ needs a note', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');
    $quotation = submittedQuotation('PROYEK', 'WAITING_CEO');

    $this->actingAs($ceo)->post(route('quotations.review', $quotation), ['decision' => 'return'])->assertSessionHasErrors('note');
    $this->actingAs($ceo)->post(route('quotations.review', $quotation), ['decision' => 'return', 'note' => 'Margin terlalu tipis.'])
        ->assertSessionHasNoErrors();

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Draft)
        ->and(QuotationRevision::sole()->reason)->toBe(QuotationRevision::REASON_CEO_REJECTED);
});

test('the Estimator sends the approved RAB to Marketing, Marketing to the client', function () {
    $estimator = User::factory()->create();
    $estimator->assignRole('ESTIMATOR');
    $marketing = User::factory()->create();
    $marketing->assignRole('MARKETING');
    $quotation = submittedQuotation('DESAIN', 'APPROVED_INTERNAL');
    $quotation->lead->update(['assigned_to' => $marketing->id]);

    $this->actingAs($marketing)->post(route('quotations.sendToClient', $quotation))->assertSessionHasErrors('status');

    $this->actingAs($estimator)->post(route('quotations.sendToMarketing', $quotation))->assertSessionHasNoErrors();
    expect($quotation->fresh()->status)->toBe(QuotationStatus::ReadyToSend)
        ->and(Notification::where('user_id', $marketing->id)->where('type', 'quotation_ready_to_send')->exists())->toBeTrue();

    $this->actingAs($marketing)->post(route('quotations.sendToClient', $quotation))->assertSessionHasNoErrors();
    $quotation->refresh();
    expect($quotation->status)->toBe(QuotationStatus::SentToClient)
        ->and($quotation->valid_until)->not->toBeNull()
        ->and($quotation->first_sent_at)->not->toBeNull()
        ->and(AuditLog::whereIn('action', ['quotation.sent_to_marketing', 'quotation.sent_to_client'])->count())->toBe(2);
});

test('Marketing cancels a running RAB with a reason, releasing its survey', function () {
    $marketing = User::factory()->create();
    $marketing->assignRole('MARKETING');
    $quotation = submittedQuotation('SURVEY');
    $survey = LeadSurvey::factory()->outsidePekanbaru()->create(['lead_id' => $quotation->lead_id, 'quotation_id' => $quotation->id]);

    $this->actingAs($marketing)->post(route('quotations.cancel', $quotation), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($marketing)->post(route('quotations.cancel', $quotation), ['reason' => 'Klien batal survey.'])->assertSessionHasNoErrors();

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Cancelled)
        ->and($survey->fresh()->quotation_id)->toBeNull()
        ->and(AuditLog::where('action', 'quotation.cancelled')->sole()->new_values['reason'])->toBe('Klien batal survey.');

    // A cancelled RAB no longer blocks a new request of the same type.
    app(QuotationService::class)->request($quotation->lead, QuotationType::Survey, 'Ulang', $marketing);
    expect(Quotation::where('type', 'SURVEY')->count())->toBe(2);
});

test('a closed RAB cannot be cancelled', function () {
    $quotation = Quotation::factory()->approved()->create();

    expect(fn () => app(QuotationService::class)->cancel($quotation, User::factory()->create(), 'x'))
        ->toThrow(ValidationException::class);
});

test('only reviewers review, only the Estimator sends to Marketing, only Marketing/CEO send to the client or cancel', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);
    $submitted = submittedQuotation();
    $approved = submittedQuotation('PROYEK', 'APPROVED_INTERNAL');
    $ready = submittedQuotation('PROYEK', 'READY_TO_SEND');

    $review = $this->actingAs($user)->post(route('quotations.review', $submitted), reviewPayload($submitted, 'approve'));
    $toMarketing = $this->actingAs($user)->post(route('quotations.sendToMarketing', $approved));
    $toClient = $this->actingAs($user)->post(route('quotations.sendToClient', $ready));
    $cancel = $this->actingAs($user)->post(route('quotations.cancel', $ready), ['reason' => 'x']);

    $expect = fn ($response, array $allowed) => in_array($role, $allowed, true) ? $response->assertRedirect() : $response->assertForbidden();
    $expect($review, ['PM', 'ASISTEN_PM', 'CEO']);
    $expect($toMarketing, ['ESTIMATOR']);
    $expect($toClient, ['MARKETING', 'CEO']);
    $expect($cancel, ['MARKETING', 'CEO']);
})->with(['ESTIMATOR', 'MARKETING', 'DESIGNER', 'FINANCE', 'PM', 'ASISTEN_PM', 'CEO']);

test('the detail page tells each reviewer whether it is their turn', function (string $status, string $role, ?string $stage) {
    $user = User::factory()->create();
    $user->assignRole($role);
    $quotation = submittedQuotation('PROYEK', $status);

    $this->actingAs($user)->get(route('quotations.show', $quotation))
        ->assertInertia(fn (Assert $page) => $page->where('reviewStage', $stage)->has('itemReviews'));
})->with([
    ['SUBMITTED', 'PM', 'PM'],
    ['SUBMITTED', 'ASISTEN_PM', 'PM'],
    ['SUBMITTED', 'CEO', null],
    ['WAITING_CEO', 'CEO', 'CEO'],
    ['WAITING_CEO', 'PM', null],
]);

test('item reviews are append-only', function () {
    $quotation = submittedQuotation();
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    reviewQuotation($quotation, $pm);

    $review = QuotationItemReview::first();
    expect(fn () => $review->update(['verdict' => 'SALAH']))->toThrow(LogicException::class)
        ->and(fn () => $review->delete())->toThrow(LogicException::class);
});

test('the Sprint 12 status migration moves old rows into the review flow and back', function () {
    $migration = require database_path('migrations/2026_10_05_082201_move_quotations_to_review_flow.php');
    $migration->down();

    $ceoReview = Quotation::factory()->create(['status' => 'CEO_REVIEW']);
    $approved = Quotation::factory()->create(['status' => 'APPROVED']);
    $sent = Quotation::factory()->create(['status' => 'SENT_TO_CLIENT']);
    QuotationApproval::create(['quotation_id' => $sent->id, 'version' => 1, 'approver_id' => User::factory()->create()->id, 'approver_role' => 'PM', 'status' => 'APPROVED']);

    $migration->up();

    expect(DB::table('quotations')->find($ceoReview->id)->status)->toBe('SUBMITTED')
        ->and(DB::table('quotations')->find($approved->id)->status)->toBe('CLIENT_APPROVED')
        ->and(DB::table('quotations')->find($sent->id)->first_sent_at)->not->toBeNull();

    $migration->down();
    expect(DB::table('quotations')->find($approved->id)->status)->toBe('APPROVED')
        ->and(Schema::hasColumn('quotations', 'sent_at'))->toBeFalse();
    $migration->up();
});

test('roles with read access can export a quotation PDF', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');
    $quotation = Quotation::factory()->create();
    QuotationItem::factory()->create(['quotation_id' => $quotation->id]);

    $response = $this->actingAs($ceo)->get(route('quotations.pdf', ['quotation' => $quotation->id]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
});
