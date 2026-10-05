<?php

use App\Enums\LeadStatus;
use App\Enums\PaymentTermTrigger;
use App\Enums\QuotationStatus;
use App\Enums\QuotationType;
use App\Exports\QuotationExport;
use App\Models\Design;
use App\Models\Lead;
use App\Models\LeadSurvey;
use App\Models\Notification;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\QuotationRevision;
use App\Models\User;
use App\Services\QuotationService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 12 Sub 3 — quotation types (Jasa Survey / Jasa Desain / Proyek),
 * Marketing's request (DIMINTA → DRAFT), the Excel-format RAB (sections,
 * dimensions, discount, rounding) and the DP/termin scheme.
 */

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->marketing = rabUser('MARKETING');
    $this->estimator = rabUser('ESTIMATOR');
    $this->lead = Lead::factory()->create(['assigned_to' => $this->marketing->id, 'status' => LeadStatus::FollowUp->value]);
});

function rabUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function draftRab(array $attributes = []): Quotation
{
    return Quotation::factory()->create(['status' => QuotationStatus::Draft->value] + $attributes);
}

/** Two sections: Dapur 2 × 1.500.000 + 1,5 × 1.000.000; Kamar 1 × 2.000.000 → 6.500.000. */
function rabSections(): array
{
    return [
        ['name' => 'Dapur', 'items' => [
            ['description' => 'Kitchen set bawah', 'dim_length' => 3, 'dim_width_height' => 0.9, 'qty' => 2, 'unit_id' => unitId('m'), 'unit_price' => 1_500_000],
            ['description' => 'Backsplash', 'qty' => 1.5, 'unit_id' => unitId('m2'), 'unit_price' => 1_000_000],
        ]],
        ['name' => 'Kamar Utama', 'items' => [
            ['description' => 'Lemari', 'qty' => 1, 'unit_id' => unitId('unit'), 'unit_price' => 2_000_000],
        ]],
    ];
}

// ── Permintaan Marketing → DIMINTA → DRAFT ───────────────────────────────

test('Marketing asks for a RAB from the lead: DIMINTA, note kept, Estimator notified, lead moves on', function (string $requestType, QuotationType $type) {
    $this->actingAs($this->marketing)->post(route('crm.leads.submitRequest', $this->lead), [
        'type' => $requestType,
        'note' => 'Kitchen set 3 m, HPL',
    ])->assertSessionHasNoErrors();

    $quotation = Quotation::sole();

    expect($quotation->type)->toBe($type)
        ->and($quotation->status)->toBe(QuotationStatus::Diminta)
        ->and($quotation->requested_by)->toBe($this->marketing->id)
        ->and($quotation->request_note)->toBe('Kitchen set 3 m, HPL')
        ->and(Notification::where('user_id', $this->estimator->id)->where('type', 'quotation_requested')->exists())->toBeTrue()
        ->and($this->lead->fresh()->status)->toBe(LeadStatus::DealDesain);
})->with([
    ['RAB_SURVEY', QuotationType::Survey],
    ['RAB_DESAIN', QuotationType::Desain],
    ['RAB_PROYEK', QuotationType::Proyek],
]);

test('a RAB request needs a note for the Estimator', function () {
    $this->actingAs($this->marketing)->post(route('crm.leads.submitRequest', $this->lead), ['type' => 'RAB_DESAIN'])
        ->assertSessionHasErrors(['note' => 'Catatan untuk Estimator wajib diisi.']);

    expect(Quotation::count())->toBe(0);
});

test('only one running RAB per type per lead', function () {
    $service = app(QuotationService::class);
    $service->request($this->lead, QuotationType::Desain, 'Desain 2 kamar', $this->marketing);

    expect(fn () => $service->request($this->lead, QuotationType::Desain, 'lagi', $this->marketing))
        ->toThrow(ValidationException::class);

    // Another type is fine.
    $service->request($this->lead, QuotationType::Survey, 'Survey Bangkinang', $this->marketing);
    expect(Quotation::count())->toBe(2);
});

test('a RAB Jasa Survey is linked to the outside-Pekanbaru survey waiting for payment', function () {
    $survey = LeadSurvey::factory()->outsidePekanbaru()->create(['lead_id' => $this->lead->id]);

    $quotation = app(QuotationService::class)->request($this->lead, QuotationType::Survey, 'Survey Bangkinang', $this->marketing);

    expect($quotation->lead_survey_id)->toBe($survey->id)
        ->and($survey->fresh()->quotation_id)->toBe($quotation->id);
});

test('the Estimator picks a requested RAB up: DRAFT with a default 100% upfront scheme, requester notified', function () {
    $quotation = app(QuotationService::class)->request($this->lead, QuotationType::Survey, 'Survey Bangkinang', $this->marketing);

    $this->actingAs($this->estimator)->post(route('quotations.start', $quotation))->assertSessionHasNoErrors();

    $quotation->refresh();
    $term = $quotation->paymentTerms->sole();

    expect($quotation->status)->toBe(QuotationStatus::Draft)
        ->and($term->only(['sequence', 'label']))->toBe(['sequence' => 1, 'label' => 'Pembayaran penuh'])
        ->and((float) $term->percentage)->toBe(100.0)
        ->and($term->trigger)->toBe(PaymentTermTrigger::DiMuka)
        ->and(Notification::where('user_id', $this->marketing->id)->where('type', 'quotation_started')->exists())->toBeTrue();
});

test('only a DIMINTA RAB can be started', function () {
    expect(fn () => app(QuotationService::class)->startDraft(draftRab(), $this->estimator))
        ->toThrow(ValidationException::class);
});

test('a RAB Proyek needs no design, and a later design ACC reuses it', function () {
    $quotation = app(QuotationService::class)->request($this->lead, QuotationType::Proyek, 'Klien bawa desain sendiri', $this->marketing);

    expect($this->lead->fresh()->quotation->id)->toBe($quotation->id);

    $design = Design::factory()->create(['lead_id' => $this->lead->id, 'client_acc' => true]);

    expect(app(QuotationService::class)->createFromDesign($design, $this->estimator)->id)->toBe($quotation->id)
        ->and(Quotation::count())->toBe(1);
});

test('Lead::quotation stays the project RAB when the lead also has service RABs', function () {
    $proyek = draftRab(['lead_id' => $this->lead->id, 'type' => 'PROYEK']);
    draftRab(['lead_id' => $this->lead->id, 'type' => 'SURVEY']);

    expect($this->lead->fresh()->quotation->id)->toBe($proyek->id)
        ->and(Lead::with('quotation:id,lead_id,type')->find($this->lead->id)->quotation->id)->toBe($proyek->id)
        ->and($this->lead->fresh()->quotations)->toHaveCount(2);
});

// ── RAB: bagian, dimensi, diskon, pembulatan ─────────────────────────────

test('saving the RAB stores sections and dimensions and computes totals server-side', function () {
    $quotation = draftRab();

    $this->actingAs($this->estimator)->put(route('quotations.items.update', $quotation), [
        'sections' => rabSections(),
        'discount_amount' => 500_000,
        'rounded_total' => null,
    ])->assertSessionHasNoErrors();

    $quotation->refresh()->load('sections', 'items');

    expect($quotation->sections->pluck('name')->all())->toBe(['Dapur', 'Kamar Utama'])
        ->and($quotation->items)->toHaveCount(3)
        ->and($quotation->items->first()->section_id)->toBe($quotation->sections->first()->id)
        ->and($quotation->items->first()->dim_length)->toBe(3.0)
        ->and($quotation->items->first()->dim_width_height)->toBe(0.9)
        ->and((float) $quotation->items_total)->toBe(6_500_000.0)
        ->and((float) $quotation->discount_amount)->toBe(500_000.0)
        ->and($quotation->rounded_total)->toBeNull()
        ->and((float) $quotation->total_amount)->toBe(6_000_000.0);
});

test('a rounded total overrides total minus discount', function () {
    $quotation = app(QuotationService::class)->saveRab(draftRab(), [
        'sections' => rabSections(),
        'discount_amount' => 123_456,
        'rounded_total' => 6_350_000,
    ]);

    expect((float) $quotation->total_amount)->toBe(6_350_000.0)
        ->and((float) $quotation->items_total)->toBe(6_500_000.0);
});

test('a discount above the RAB total is refused', function () {
    expect(fn () => app(QuotationService::class)->saveRab(draftRab(), [
        'sections' => rabSections(),
        'discount_amount' => 7_000_000,
    ]))->toThrow(ValidationException::class);
});

test('a section needs a name and at least one item', function () {
    $quotation = draftRab();

    $this->actingAs($this->estimator)->put(route('quotations.items.update', $quotation), [
        'sections' => [['name' => '', 'items' => []]],
    ])->assertSessionHasErrors(['sections.0.name', 'sections.0.items']);
});

test('items without a section are grouped as "Umum" before the named sections', function () {
    $quotation = draftRab();
    QuotationItem::factory()->create(['quotation_id' => $quotation->id, 'qty' => 1, 'unit_price' => 100_000, 'total_price' => 100_000]);
    $section = $quotation->sections()->create(['name' => 'Dapur', 'sort_order' => 0]);
    QuotationItem::factory()->create(['quotation_id' => $quotation->id, 'section_id' => $section->id, 'qty' => 2, 'unit_price' => 50_000, 'total_price' => 100_000]);

    $groups = $quotation->fresh(['items', 'sections'])->rabGroups();

    expect($groups->pluck('name')->all())->toBe(['Umum', 'Dapur'])
        ->and($groups->pluck('subtotal')->all())->toBe([100_000, 100_000]);
});

// ── Skema DP/termin ──────────────────────────────────────────────────────

test('payment terms add up to the total exactly, the last row taking the rounding remainder', function () {
    $quotation = app(QuotationService::class)->saveRab(draftRab(), ['sections' => rabSections(), 'rounded_total' => 1_000_000]);

    $this->actingAs($this->estimator)->put(route('quotations.paymentTerms.update', $quotation), ['terms' => [
        ['label' => 'DP', 'percentage' => 33.33, 'trigger' => 'DI_MUKA'],
        ['label' => 'Termin 1', 'percentage' => 33.33, 'trigger' => 'MILESTONE', 'milestone_name' => 'Rangka terpasang'],
        ['label' => 'Pelunasan', 'percentage' => 33.34, 'trigger' => 'TANGGAL', 'due_date' => '2026-12-01'],
    ]])->assertSessionHasNoErrors();

    $terms = $quotation->paymentTerms()->get();

    expect($terms->pluck('amount')->map(fn ($amount) => (float) $amount)->all())->toBe([333_300.0, 333_300.0, 333_400.0])
        ->and($terms->sum(fn ($term) => (float) $term->amount))->toBe(1_000_000.0)
        ->and($terms[1]->milestone_name)->toBe('Rangka terpasang')
        ->and($terms[2]->due_date->toDateString())->toBe('2026-12-01');
});

test('changing the RAB recalculates the scheme amounts', function () {
    $service = app(QuotationService::class);
    $quotation = $service->saveRab(draftRab(), ['sections' => rabSections()]);
    $service->savePaymentTerms($quotation, [
        ['label' => 'DP', 'percentage' => 50, 'trigger' => 'DI_MUKA'],
        ['label' => 'Pelunasan', 'percentage' => 50, 'trigger' => 'PROYEK_SELESAI'],
    ]);

    $service->saveRab($quotation->fresh(), ['sections' => rabSections(), 'rounded_total' => 6_000_000]);

    expect($quotation->paymentTerms()->pluck('amount')->map(fn ($amount) => (float) $amount)->all())->toBe([3_000_000.0, 3_000_000.0]);
});

test('payment terms must total exactly 100%', function () {
    $quotation = draftRab();

    $this->actingAs($this->estimator)->put(route('quotations.paymentTerms.update', $quotation), ['terms' => [
        ['label' => 'DP', 'percentage' => 30, 'trigger' => 'DI_MUKA'],
        ['label' => 'Pelunasan', 'percentage' => 60, 'trigger' => 'PROYEK_SELESAI'],
    ]])->assertSessionHasErrors('terms');
});

test('at most six payment rows, DP included', function () {
    $quotation = draftRab();
    $terms = array_map(fn (int $i) => ['label' => "T{$i}", 'percentage' => $i === 7 ? 10 : 15, 'trigger' => 'DI_MUKA'], range(1, 7));

    $this->actingAs($this->estimator)->put(route('quotations.paymentTerms.update', $quotation), ['terms' => $terms])
        ->assertSessionHasErrors('terms');
});

test('a dated row needs its date and a milestone row its milestone', function (array $term, string $error) {
    expect(fn () => app(QuotationService::class)->savePaymentTerms(draftRab(), [
        ['label' => 'DP', 'percentage' => 50, 'trigger' => 'DI_MUKA'],
        ['label' => 'Termin', 'percentage' => 50] + $term,
    ]))->toThrow(ValidationException::class, $error);
})->with([
    [['trigger' => 'TANGGAL'], 'Tanggal jatuh tempo wajib diisi'],
    [['trigger' => 'MILESTONE'], 'Nama milestone pemicu wajib diisi'],
]);

test('the scheme can only change while DRAFT', function () {
    $quotation = Quotation::factory()->create(['status' => QuotationStatus::Submitted->value]);

    expect(fn () => app(QuotationService::class)->savePaymentTerms($quotation, [
        ['label' => 'Penuh', 'percentage' => 100, 'trigger' => 'DI_MUKA'],
    ]))->toThrow(ValidationException::class);
});

// ── Snapshot revisi ──────────────────────────────────────────────────────

test('a rejected version keeps sections, dimensions, discount, rounding and the scheme in its snapshot', function () {
    $service = app(QuotationService::class);
    $quotation = $service->saveRab(draftRab(), ['sections' => rabSections(), 'discount_amount' => 500_000, 'rounded_total' => 5_950_000]);
    $service->savePaymentTerms($quotation, [
        ['label' => 'DP', 'percentage' => 40, 'trigger' => 'DI_MUKA'],
        ['label' => 'Pelunasan', 'percentage' => 60, 'trigger' => 'PROYEK_SELESAI'],
    ]);
    $service->submit($quotation->fresh());

    reviewQuotation($quotation->fresh(), rabUser('PM'), [], 'Diskon terlalu besar');

    $revision = QuotationRevision::sole();

    expect($revision->items[0])->toMatchArray(['section' => 'Dapur', 'dim_length' => 3.0, 'dim_width_height' => 0.9])
        ->and($revision->items[2]['section'])->toBe('Kamar Utama')
        ->and((float) $revision->details['discount_amount'])->toBe(500_000.0)
        ->and((float) $revision->details['rounded_total'])->toBe(5_950_000.0)
        ->and(collect($revision->details['payment_terms'])->pluck('label')->all())->toBe(['DP', 'Pelunasan']);
});

// ── RBAC ─────────────────────────────────────────────────────────────────

test('only the Estimator drafts: start, RAB and scheme are 403 for other roles', function (string $role) {
    $user = rabUser($role);
    $requested = Quotation::factory()->create(['status' => QuotationStatus::Diminta->value]);
    $draft = draftRab();

    $this->actingAs($user)->post(route('quotations.start', $requested))->assertForbidden();
    $this->actingAs($user)->put(route('quotations.items.update', $draft), ['sections' => rabSections()])->assertForbidden();
    $this->actingAs($user)->put(route('quotations.paymentTerms.update', $draft), ['terms' => [
        ['label' => 'Penuh', 'percentage' => 100, 'trigger' => 'DI_MUKA'],
    ]])->assertForbidden();
})->with(['MARKETING', 'CEO', 'PM', 'DESIGNER', 'FINANCE']);

test('only Marketing and CEO ask for a RAB', function (string $role, int $status) {
    $this->actingAs(rabUser($role))->post(route('crm.leads.submitRequest', $this->lead), [
        'type' => 'RAB_PROYEK',
        'note' => 'Penawaran pekerjaan',
    ])->assertStatus($status);
})->with([['MARKETING', 302], ['CEO', 302], ['ESTIMATOR', 403], ['DESIGNER', 403], ['PM', 403]]);

// ── Index, detail, PDF, Excel ────────────────────────────────────────────

test('the quotation index filters by type', function () {
    draftRab(['type' => 'SURVEY']);
    draftRab(['type' => 'PROYEK']);

    $this->actingAs($this->estimator)->get(route('quotations.index', ['type' => 'SURVEY']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('quotations.data', 1)
            ->where('quotations.data.0.type', 'SURVEY')
            ->where('filters.type', 'SURVEY'));
});

test('the detail page sends sections, payment terms, requester and the row limit', function () {
    $quotation = app(QuotationService::class)->request($this->lead, QuotationType::Desain, 'Desain 2 kamar', $this->marketing);

    $this->actingAs($this->estimator)->get(route('quotations.show', $quotation))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Quotation/Show')
            ->where('quotation.status', 'DIMINTA')
            ->where('quotation.type', 'DESAIN')
            ->where('quotation.requester.name', $this->marketing->name)
            ->has('quotation.sections')
            ->has('quotation.payment_terms')
            ->where('maxPaymentTerms', QuotationService::MAX_PAYMENT_TERMS));
});

test('the lead page lists every RAB of the lead', function () {
    draftRab(['lead_id' => $this->lead->id, 'type' => 'SURVEY']);
    draftRab(['lead_id' => $this->lead->id, 'type' => 'PROYEK']);

    $this->actingAs($this->marketing)->get(route('crm.leads.show', $this->lead))
        ->assertInertia(fn (Assert $page) => $page->has('lead.quotations', 2)->where('lead.quotation.status', 'DRAFT'));
});

test('the PDF and Excel exports render a sectioned RAB with its scheme', function () {
    $service = app(QuotationService::class);
    $quotation = $service->saveRab(draftRab(['lead_id' => $this->lead->id]), ['sections' => rabSections(), 'discount_amount' => 500_000]);
    $service->savePaymentTerms($quotation, [
        ['label' => 'DP', 'percentage' => 50, 'trigger' => 'DI_MUKA'],
        ['label' => 'Pelunasan', 'percentage' => 50, 'trigger' => 'PROYEK_SELESAI'],
    ]);

    $this->actingAs($this->marketing)->get(route('quotations.pdf', $quotation))->assertOk();

    $response = $this->actingAs($this->marketing)->get(route('quotations.excel', $quotation))->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('.xlsx');

    $rows = collect((new QuotationExport($quotation->fresh()))->array());
    $labels = $rows->pluck(1)->filter()->values();

    expect($labels)->toContain('Dapur', 'Subtotal Dapur', 'Kamar Utama', 'Diskon', 'GRAND TOTAL', 'DP', 'Pelunasan')
        ->and($rows->firstWhere(1, 'GRAND TOTAL')[7])->toBe(6_000_000.0)
        ->and($rows->firstWhere(1, 'Diskon')[7])->toBe(-500_000.0);
});

test('only the quotation readers export Excel', function (string $role, int $status) {
    $quotation = draftRab();
    QuotationItem::factory()->create(['quotation_id' => $quotation->id]);

    $this->actingAs(rabUser($role))->get(route('quotations.excel', $quotation))->assertStatus($status);
})->with([['ESTIMATOR', 200], ['FINANCE', 200], ['QA', 403], ['LOGISTICS', 403], ['FIELD_STAFF', 403]]);

// ── Migrasi ──────────────────────────────────────────────────────────────

test('the Sprint 12 quotation migration backfills old rows as PROYEK and rolls back', function () {
    $quotation = Quotation::factory()->create(['total_amount' => 2_500_000]);
    $migration = require database_path('migrations/2026_10_04_224831_add_type_and_totals_to_quotations_table.php');
    $sections = require database_path('migrations/2026_10_04_224832_create_quotation_sections_table.php');
    $terms = require database_path('migrations/2026_10_04_224832_create_quotation_payment_terms_table.php');

    $terms->down();
    $sections->down();
    $migration->down();
    expect(Schema::hasColumn('quotations', 'type'))->toBeFalse();

    DB::table('quotations')->where('id', $quotation->id)->update(['total_amount' => 2_500_000]);

    $migration->up();
    $sections->up();
    $terms->up();

    $row = DB::table('quotations')->find($quotation->id);
    expect($row->type)->toBe('PROYEK')
        ->and((float) $row->items_total)->toBe(2_500_000.0)
        ->and(Schema::hasColumn('quotation_items', 'section_id'))->toBeTrue();
});
