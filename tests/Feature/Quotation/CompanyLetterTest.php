<?php

use App\Enums\InvoiceStatus;
use App\Enums\QuotationStatus;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\LetterNumberService;
use App\Services\QuotationService;
use App\Support\Letters\QuotationLetter;
use App\Support\Terbilang;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SiteSettingSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 15 — offers and invoices as the company's letter: letterhead &
 * signer from Pengaturan Situs, OFF/INV letter numbers, "Catatan", total in
 * words; the same letter on the PDF and the client's link.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-09-15 10:00', 'Asia/Jakarta'));
    Storage::fake(SiteSetting::DISK);
    $this->seed(RoleSeeder::class);
    $this->marketing = letterUser('MARKETING');
    $this->estimator = letterUser('ESTIMATOR');
    $this->lead = Lead::factory()->create(['client_name' => 'Egika Desla', 'assigned_to' => $this->marketing->id]);
});

afterEach(fn () => Carbon::setTestNow());

function letterUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** A Jasa Desain RAB ready for Marketing to send. */
function readyDesignRab(object $test, array $attributes = []): Quotation
{
    $quotation = Quotation::factory()->create([
        'lead_id' => $test->lead->id,
        'type' => 'DESAIN',
        'status' => QuotationStatus::ReadyToSend->value,
        'total_amount' => 5_000_000,
        'items_total' => 5_000_000,
        ...$attributes,
    ]);
    QuotationItem::factory()->create([
        'quotation_id' => $quotation->id, 'description' => 'Jasa desain showroom',
        'dim_length' => 15, 'dim_width_height' => 10, 'qty' => 1, 'unit_price' => 5_000_000, 'total_price' => 5_000_000,
    ]);
    $quotation->paymentTerms()->create(['sequence' => 1, 'label' => 'Pembayaran penuh', 'percentage' => 100, 'amount' => 5_000_000, 'trigger' => 'DI_MUKA']);

    return $quotation;
}

// ── Terbilang & nomor surat ─────────────────────────────────────────────

test('amounts are written out in Indonesian', function (int $amount, string $words) {
    expect(Terbilang::rupiah($amount))->toBe($words);
})->with([
    [5_000_000, 'LIMA JUTA RUPIAH'],
    [1_500, 'SERIBU LIMA RATUS RUPIAH'],
    [111_000, 'SERATUS SEBELAS RIBU RUPIAH'],
    [2_750_250_000, 'DUA MILIAR TUJUH RATUS LIMA PULUH JUTA DUA RATUS LIMA PULUH RIBU RUPIAH'],
    [0, 'NOL RUPIAH'],
]);

test('offers and invoices share one running letter number per year', function () {
    $numbers = app(LetterNumberService::class);

    expect($numbers->next('OFF'))->toBe('1/OFF/Daiku/IX/2026')
        ->and($numbers->next('INV'))->toBe('2/INV/Daiku/IX/2026')
        ->and($numbers->next('OFF', Carbon::parse('2026-12-01')))->toBe('3/OFF/Daiku/XII/2026')
        ->and($numbers->next('INV', Carbon::parse('2027-01-02')))->toBe('1/INV/Daiku/I/2027');
});

test('an offer gets its number when sent, and a revised version gets a new one', function () {
    $quotation = readyDesignRab($this);
    $service = app(QuotationService::class);

    $sent = $service->sendToClient($quotation, $this->marketing);
    expect($sent->letter_number)->toBe('1/OFF/Daiku/IX/2026');

    // The client asks for changes → next version reopens as a DRAFT without a number.
    $service->clientReject($sent, $this->marketing, 'Minta revisi luas ruang.');
    expect($sent->fresh())->status->toBe(QuotationStatus::Draft)->letter_number->toBeNull();
});

// ── Catatan per RAB ─────────────────────────────────────────────────────

test('the Estimator writes the RAB notes while it is a draft; empty falls back to the default', function () {
    $quotation = readyDesignRab($this, ['status' => QuotationStatus::Draft->value]);

    $this->actingAs($this->estimator)
        ->put(route('quotations.clientNotes.update', $quotation), ['client_notes' => "Paket Premium.\n3 kali revisi."])
        ->assertSessionHasNoErrors();
    expect($quotation->fresh()->client_notes)->toBe("Paket Premium.\n3 kali revisi.");

    $letter = QuotationLetter::for($quotation->fresh()->load(['lead', 'items.unit', 'sections', 'paymentTerms']), SiteSetting::current(), 14);
    expect($letter['notes'])->toContain('Paket Premium.')->toContain('3 kali revisi.');

    $this->actingAs($this->estimator)->put(route('quotations.clientNotes.update', $quotation), ['client_notes' => ''])->assertSessionHasNoErrors();
    $letter = QuotationLetter::for($quotation->fresh()->load(['lead', 'items.unit', 'sections', 'paymentTerms']), SiteSetting::current(), 14);
    expect($letter['notes'])->toContain(SiteSetting::DEFAULT_NOTES['DESAIN']);
});

test('the notes are locked once the RAB left the draft, and only the Estimator writes them', function () {
    $quotation = readyDesignRab($this);

    $this->actingAs($this->estimator)->put(route('quotations.clientNotes.update', $quotation), ['client_notes' => 'x'])
        ->assertSessionHasErrors('status');
    $this->actingAs($this->marketing)->put(route('quotations.clientNotes.update', $quotation), ['client_notes' => 'x'])
        ->assertForbidden();
});

// ── PDF & link klien ────────────────────────────────────────────────────

test('the offer PDF is the company letter', function () {
    SiteSetting::current()->update(['signer_name' => 'Fendra Budiono', 'company_legal_name' => 'PT Daiku Shankara Kreasitech']);
    $quotation = readyDesignRab($this, ['letter_number' => '377/OFF/Daiku/IX/2026', 'status' => QuotationStatus::SentToClient->value]);

    $html = view('pdf.quotation', ['quotation' => $quotation, 'siteSettings' => SiteSetting::current(), 'validityDays' => 14])->render();

    expect($html)
        ->toContain('377/OFF/Daiku/IX/2026')
        ->toContain('Penawaran Jasa Desain Egika Desla')
        ->toContain('URAIAN PEKERJAAN')
        ->toContain('TERBILANG: LIMA JUTA RUPIAH')
        ->toContain('Fendra Budiono')
        ->toContain('DAIKU | INTERIOR FURNISHING | ARCHITECTURAL DESIGN | BUILDING CONSTRUCTION | 2026');

    $this->actingAs($this->marketing)->get(route('quotations.pdf', $quotation))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

test('a draft offer prints DRAF instead of a number', function () {
    $quotation = readyDesignRab($this, ['status' => QuotationStatus::Draft->value]);

    $html = view('pdf.quotation', ['quotation' => $quotation, 'siteSettings' => SiteSetting::current(), 'validityDays' => 14])->render();

    expect($html)->toContain('DRAF')->toContain('nomor diterbitkan saat penawaran dikirim');
});

test('the invoice PDF carries the INV number on the company letterhead, as a bill (Sprint 17 K3)', function () {
    $quotation = readyDesignRab($this, ['status' => QuotationStatus::ClientApproved->value]);
    $invoice = Invoice::create([
        'number' => '12/INV/Daiku/IX/2026', 'lead_id' => $this->lead->id, 'quotation_id' => $quotation->id,
        'type' => 'JASA_DESAIN', 'amount' => 5_000_000, 'due_date' => '2026-09-20',
        'status' => InvoiceStatus::Diterbitkan->value, 'issued_by' => $this->marketing->id, 'issued_at' => now(),
    ]);

    $html = view('pdf.invoice', ['invoice' => $invoice, 'siteSettings' => SiteSetting::current(), 'bankAccounts' => collect()])->render();

    // Sprint 17 Sub 05 — a summary row, not the RAB's items (they stay in the offer).
    expect($html)->toContain('12/INV/Daiku/IX/2026')
        ->toContain('Invoice Jasa Desain Egika Desla')
        ->toContain('Jasa Desain')
        ->not->toContain('JASA DESAIN SHOWROOM')
        ->toContain('LIMA JUTA RUPIAH');

    $this->actingAs($this->marketing)->get(route('finance.invoices.pdf', $invoice))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

test('the client link shows the same letter and offers its PDF; nothing internal leaks', function () {
    $quotation = readyDesignRab($this, ['request_note' => 'CATATAN-INTERNAL']);
    $sent = app(QuotationService::class)->sendToClient($quotation, $this->marketing);
    $token = $sent->currentShareLink()->token;

    $this->get(route('public.quotation.show', $token))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('quotation.letter.number', $sent->letter_number)
            ->where('quotation.letter.totalInWords', 'LIMA JUTA RUPIAH')
            ->where('quotation.letter.groups.0.rows.0.p', '15'))
        ->assertDontSee('CATATAN-INTERNAL', false);

    $this->get(route('public.quotation.pdf', $token))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    // A revised version: the old link no longer hands out a PDF.
    app(QuotationService::class)->clientReject($sent, $this->marketing, 'Revisi.');
    $this->get(route('public.quotation.pdf', $token))->assertNotFound();
});

// ── Riwayat RAB ─────────────────────────────────────────────────────────

test('the client history shows each RAB its number and own client link — links only for CEO/Marketing', function () {
    $sent = app(QuotationService::class)->sendToClient(readyDesignRab($this), $this->marketing);

    $this->actingAs($this->marketing)->get(route('crm.leads.show', $this->lead))
        ->assertInertia(fn (Assert $page) => $page
            ->where('lead.quotations.0.letter_number', $sent->letter_number)
            ->where('lead.quotations.0.client_url', $sent->currentShareLink()->url())
            ->missing('lead.quotations.0.share_links'));

    $this->actingAs($this->estimator)->get(route('crm.leads.show', $this->lead))
        ->assertInertia(fn (Assert $page) => $page->where('lead.quotations.0.client_url', null));
});

// ── Pengaturan Situs ────────────────────────────────────────────────────

test('the CEO sets the letterhead, signer, notes and signature; the signature is never public', function () {
    $ceo = letterUser('CEO');

    $this->actingAs($ceo)->put(route('settings.update'), [
        'site_name' => 'Daiku Interior',
        'company_instagram' => 'DaikuInterior',
        'company_legal_name' => 'PT Daiku Shankara Kreasitech',
        'signer_name' => 'Fendra Budiono',
        'note_desain' => 'Paket desain lengkap.',
    ])->assertSessionHasNoErrors();

    $this->actingAs($ceo)->post(route('settings.assets.store', ['asset' => 'signature']), [
        'file' => UploadedFile::fake()->image('ttd.png', 600, 200),
    ])->assertSessionHasNoErrors();

    $settings = SiteSetting::current();
    expect($settings->signer_name)->toBe('Fendra Budiono')
        ->and($settings->defaultNoteFor('DESAIN'))->toBe('Paket desain lengkap.')
        ->and($settings->signatureDataUri())->toStartWith('data:image/png;base64,');

    $this->get('/branding/signature')->assertNotFound();
});

test('only CEO and SUPERADMIN change the letter settings', function () {
    $this->actingAs(letterUser('HR'))->put(route('settings.update'), ['site_name' => 'X', 'signer_name' => 'Y'])->assertForbidden();
    $this->actingAs(letterUser('MARKETING'))->post(route('settings.assets.store', ['asset' => 'signature']), [
        'file' => UploadedFile::fake()->image('ttd.png', 600, 200),
    ])->assertForbidden();
});

test('the seeder fills the letterhead contact once and never overwrites the CEO', function () {
    SiteSetting::current()->update(['company_email' => 'kantor@daiku.test']);

    $this->seed(SiteSettingSeeder::class);
    $this->seed(SiteSettingSeeder::class);

    $settings = SiteSetting::current()->fresh();
    expect($settings->company_address)->toBe('Jl. Yos Sudarso, Rumbai, Pekanbaru')
        ->and($settings->company_phone)->toBe('0811 759 7766')
        ->and($settings->company_instagram)->toBe('DaikuInterior')
        ->and($settings->company_email)->toBe('kantor@daiku.test');

    $quotation = readyDesignRab($this, ['letter_number' => '377/OFF/Daiku/IX/2026', 'status' => QuotationStatus::SentToClient->value]);
    $html = view('pdf.quotation', ['quotation' => $quotation, 'siteSettings' => $settings, 'validityDays' => 14])->render();

    expect($html)
        ->toContain('Jl. Yos Sudarso, Rumbai, Pekanbaru')
        ->toContain('0811 759 7766')
        ->toContain('DaikuInterior')
        ->toContain('data:image/svg+xml;base64,');
});
