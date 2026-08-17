<?php

use App\Enums\QuotationStatus;
use App\Models\Design;
use App\Models\Quotation;
use App\Models\QuotationApproval;
use App\Models\QuotationItem;
use App\Models\User;
use App\Services\QuotationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Validation\ValidationException;

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

test('quotation service refuses a second quotation for the same lead', function () {
    $design = Design::factory()->create(['client_acc' => true]);
    Quotation::factory()->create(['lead_id' => $design->lead_id]);
    $actor = User::factory()->create();

    expect(fn () => app(QuotationService::class)->createFromDesign($design, $actor))
        ->toThrow(ValidationException::class);
});

test('quotation service replaces items and recomputes the total server-side', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Draft->value]);
    QuotationItem::factory()->create(['quotation_id' => $quotation->id]);

    app(QuotationService::class)->replaceItems($quotation, [
        ['description' => 'Kitchen Set', 'qty' => 2, 'unit' => 'set', 'unit_price' => 1_500_000],
        ['description' => 'Meja Kerja', 'qty' => 3, 'unit' => 'unit', 'unit_price' => 800_000],
    ]);

    $quotation->refresh();
    expect($quotation->items)->toHaveCount(2)
        ->and((float) $quotation->total_amount)->toBe(2 * 1_500_000 + 3 * 800_000.0);
});

test('quotation service refuses to change items once past DRAFT', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Submitted->value]);

    expect(fn () => app(QuotationService::class)->replaceItems($quotation, [
        ['description' => 'Item', 'qty' => 1, 'unit' => 'unit', 'unit_price' => 100_000],
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
            ['description' => 'Kitchen Set', 'qty' => 1, 'unit' => 'set', 'unit_price' => 5_000_000],
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
            ['description' => 'Kitchen Set', 'qty' => 1, 'unit' => 'set', 'unit_price' => 5_000_000],
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

test('CEO can approve a submitted quotation, advancing it to CEO_REVIEW', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Submitted->value]);

    $this->actingAs($ceo)->post(route('quotations.ceoDecision', ['quotation' => $quotation->id]), [
        'decision' => 'approve',
    ])->assertRedirect();

    expect($quotation->fresh()->status)->toBe(QuotationStatus::CeoReview)
        ->and(QuotationApproval::where('quotation_id', $quotation->id)->where('approver_role', 'CEO')->exists())->toBeTrue();
});

test('CEO rejecting a quotation requires a note and sends it back to DRAFT', function () {
    $ceo = User::factory()->create();
    $ceo->assignRole('CEO');
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Submitted->value]);

    $this->actingAs($ceo)->post(route('quotations.ceoDecision', ['quotation' => $quotation->id]), [
        'decision' => 'reject',
    ])->assertSessionHasErrors('note');

    $this->actingAs($ceo)->post(route('quotations.ceoDecision', ['quotation' => $quotation->id]), [
        'decision' => 'reject',
        'note' => 'Harga terlalu tinggi.',
    ])->assertRedirect();

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Draft);
});

test('PM cannot approve a quotation before CEO has', function () {
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Submitted->value]);

    $this->actingAs($pm)->post(route('quotations.pmDecision', ['quotation' => $quotation->id]), [
        'decision' => 'approve',
    ])->assertSessionHasErrors('status');

    expect($quotation->fresh()->status)->toBe(QuotationStatus::Submitted);
});

test('PM approving after CEO marks the quotation SENT_TO_CLIENT', function () {
    $pm = User::factory()->create();
    $pm->assignRole('PM');
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::CeoReview->value]);

    $this->actingAs($pm)->post(route('quotations.pmDecision', ['quotation' => $quotation->id]), [
        'decision' => 'approve',
    ])->assertRedirect();

    expect($quotation->fresh()->status)->toBe(QuotationStatus::SentToClient);
});

test('roles other than CEO/PM cannot make quotation approval decisions', function () {
    $estimator = User::factory()->create();
    $estimator->assignRole('ESTIMATOR');
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Submitted->value]);

    $this->actingAs($estimator)->post(route('quotations.ceoDecision', ['quotation' => $quotation->id]), [
        'decision' => 'approve',
    ])->assertForbidden();
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
