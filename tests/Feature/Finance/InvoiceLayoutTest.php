<?php

use App\Enums\InvoiceStatus;
use App\Enums\QuotationStatus;
use App\Models\BankAccount;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\QuotationPaymentTerm;
use App\Models\QuotationSection;
use App\Models\SiteSetting;
use App\Models\Termin;
use App\Models\User;
use App\Services\InvoiceService;
use App\Support\Letters\InvoiceLetter;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/*
 * Sprint 17 Sub 05 (K3) — an invoice is a bill, not a second offer: big
 * INVOICE title, "Ditagihkan kepada" + invoice data, one summary row per RAB
 * group / termin, Total Tagihan, Cara Pembayaran, a status stamp — on the
 * same letterhead. The offer keeps its Sprint 15 letter.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-05 10:00', 'Asia/Jakarta'));
    Storage::fake(SiteSetting::DISK);
    $this->seed(RoleSeeder::class);
    $this->marketing = billingUser('MARKETING');
    $this->finance = billingUser('FINANCE');
    BankAccount::factory()->create(['bank_name' => 'BCA', 'account_no' => '5835123456', 'label' => 'BCA Utama', 'is_active' => true]);
    BankAccount::factory()->create(['bank_name' => 'BRI', 'account_no' => '777000111', 'label' => 'BRI Lama', 'is_active' => false]);
    SiteSetting::current()->update(['company_legal_name' => 'PT Daiku Uji Kreasi']);
    $this->lead = Lead::factory()->create([
        'client_name' => 'Rina Kartika', 'phone' => '081234567890', 'email' => 'rina@contoh.id',
        'address' => 'Jl. Sudirman 1', 'assigned_to' => $this->marketing->id,
    ]);
});

afterEach(fn () => Carbon::setTestNow());

function billingUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/** An approved two-section Jasa Desain RAB with a discount, and its invoice. */
function billedDesignRab(object $test, string $status = 'DITERBITKAN'): Invoice
{
    $quotation = Quotation::factory()->create([
        'lead_id' => $test->lead->id, 'type' => 'DESAIN', 'status' => QuotationStatus::ClientApproved->value,
        'letter_number' => '7/OFF/Daiku/X/2026', 'items_total' => 6_000_000, 'discount_amount' => 500_000, 'total_amount' => 5_500_000,
    ]);
    foreach ([['Desain Ruang Tamu', 'Gambar kerja ruang tamu', 4_000_000], ['Desain Dapur', 'Render 3D dapur', 2_000_000]] as $i => [$section, $item, $price]) {
        $row = QuotationSection::create(['quotation_id' => $quotation->id, 'name' => $section, 'sort_order' => $i + 1]);
        QuotationItem::factory()->create([
            'quotation_id' => $quotation->id, 'section_id' => $row->id, 'description' => $item,
            'qty' => 1, 'unit_price' => $price, 'total_price' => $price,
        ]);
    }

    return Invoice::create([
        'number' => '9/INV/Daiku/X/2026', 'lead_id' => $test->lead->id, 'quotation_id' => $quotation->id,
        'type' => 'JASA_DESAIN', 'amount' => 5_500_000, 'due_date' => '2026-10-12', 'status' => $status,
        'issued_by' => $test->marketing->id, 'issued_at' => now(), 'paid_date' => $status === 'TERVERIFIKASI' ? '2026-10-06' : null,
    ]);
}

/** Termin 1 (DP 30%) of a RAB Proyek, Rp 1.000.000 of it already paid. */
function dpTermin(object $test): Termin
{
    $rab = Quotation::factory()->create([
        'lead_id' => $test->lead->id, 'type' => 'PROYEK', 'status' => QuotationStatus::ClientApproved->value,
        'letter_number' => '5/OFF/Daiku/X/2026', 'items_total' => 30_000_000, 'total_amount' => 30_000_000,
    ]);
    $term = QuotationPaymentTerm::create([
        'quotation_id' => $rab->id, 'sequence' => 1, 'label' => 'DP', 'percentage' => 30, 'amount' => 9_000_000, 'trigger' => 'DI_MUKA',
    ]);
    $project = Project::factory()->create(['lead_id' => $test->lead->id, 'quotation_id' => $rab->id, 'name' => 'Proyek Rumah Rina']);

    return Termin::factory()->create([
        'project_id' => $project->id, 'quotation_id' => $rab->id, 'payment_term_id' => $term->id, 'trigger' => 'DI_MUKA',
        'termin_number' => 1, 'percentage' => 30, 'amount' => 9_000_000, 'dp_amount' => 1_000_000, 'scheduled_date' => '2026-10-10',
    ]);
}

test('a service invoice is a bill: summary per RAB group, total, payment box — not the offer letter', function () {
    $html = view('pdf.invoice', ['invoice' => billedDesignRab($this), 'siteSettings' => SiteSetting::current()])->render();

    expect($html)
        ->toContain('<div class="title">INVOICE</div>')
        // Ditagihkan kepada — the lead's contact, the phone formatted.
        ->toContain('Ditagihkan kepada')->toContain('Rina Kartika')->toContain('0812-3456-7890')
        ->toContain('rina@contoh.id')->toContain('Jl. Sudirman 1')
        // Invoice data.
        ->toContain('9/INV/Daiku/X/2026')->toContain('Jatuh Tempo')->toContain('12 Oktober 2026')
        ->toContain('Ref. Penawaran')->toContain('7/OFF/Daiku/X/2026')
        // One row per group, the discount, then what is billed.
        ->toContain('Desain Ruang Tamu')->toContain('4.000.000')->toContain('Desain Dapur')
        ->toContain('Diskon')->toContain('- 500.000')
        ->toContain('Total Tagihan')->toContain('Rp 5.500.000')->toContain('LIMA JUTA LIMA RATUS RIBU RUPIAH')
        // Cara Pembayaran — active accounts only.
        ->toContain('Cara Pembayaran')->toContain('5835123456')->toContain('PT Daiku Uji Kreasi')
        ->toContain('Cantumkan No. Invoice 9/INV/Daiku/X/2026 pada berita transfer.')
        ->not->toContain('777000111')
        ->toContain('BELUM DIBAYAR')
        ->toContain('Rincian item pekerjaan sesuai Penawaran No. 7/OFF/Daiku/X/2026.')
        // Not the offer: no item breakdown, no letter phrasing.
        ->not->toContain('Gambar kerja ruang tamu')
        ->not->toContain('Berlaku Sampai')
        ->not->toContain('Demikian penawaran')
        ->not->toContain('Dengan hormat')
        ->not->toContain('Perihal');

    $this->actingAs($this->marketing)->get(route('finance.invoices.pdf', Invoice::sole()))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

test('the status stamp follows the invoice status', function (string $status, string $stamp, string $tone) {
    $letter = InvoiceLetter::for(billedDesignRab($this, $status), SiteSetting::current());

    expect($letter['status'])->toBe(['label' => $stamp, 'tone' => $tone]);
})->with([
    'diterbitkan' => [InvoiceStatus::Diterbitkan->value, 'BELUM DIBAYAR', 'muted'],
    'menunggu verifikasi' => [InvoiceStatus::MenungguVerifikasi->value, 'MENUNGGU VERIFIKASI', 'warning'],
    'terverifikasi' => [InvoiceStatus::Terverifikasi->value, 'LUNAS', 'success'],
]);

test('a paid invoice says so', function () {
    $html = view('pdf.invoice', ['invoice' => billedDesignRab($this, 'TERVERIFIKASI'), 'siteSettings' => SiteSetting::current()])->render();

    expect($html)->toContain('stamp-success')->toContain('LUNAS')
        ->toContain('Pembayaran telah kami terima pada 6 Oktober 2026.')
        ->not->toContain('Mohon pembayaran paling lambat');
});

test('a termin invoice is one row for its share of the RAB, less what was already paid', function () {
    $termin = dpTermin($this);
    $invoice = app(InvoiceService::class)->issueForTermin($termin, ['due_date' => '2026-10-12'], $this->marketing);

    $html = view('pdf.invoice', ['invoice' => $invoice->fresh(), 'siteSettings' => SiteSetting::current()])->render();

    expect($html)
        ->toContain('Termin 1 — DP 30% dari RAB Proyek 5/OFF/Daiku/X/2026')
        ->toContain('9.000.000')
        ->toContain('Dikurangi pembayaran diterima')->toContain('- 1.000.000')
        ->toContain('Rp 8.000.000')->toContain('DELAPAN JUTA RUPIAH')
        ->toContain('Proyek Rumah Rina')->toContain('Termin ke-1')
        ->toContain("Cantumkan No. Invoice {$invoice->number} pada berita transfer.")
        ->toContain('Nilai tagihan mengikuti skema pembayaran pada Penawaran No. 5/OFF/Daiku/X/2026.')
        ->not->toContain('Demikian');
});

test('the termin PDF streams its invoice once issued, a DRAF bill before', function () {
    $termin = dpTermin($this);

    // Not invoiced yet: the same billing layout, no number, what is still owed.
    $html = view('pdf.termin', ['termin' => $termin->fresh(), 'siteSettings' => SiteSetting::current()])->render();
    expect($html)->toContain('<div class="title">INVOICE</div>')
        ->toContain('DRAF')->toContain('Rp 8.000.000')->toContain('DIBAYAR SEBAGIAN')
        ->toContain('Cara Pembayaran')->toContain('Cantumkan nama proyek dan termin ke-1 pada berita transfer.')
        ->not->toContain('Berlaku Sampai');

    $draft = $this->actingAs($this->finance)->get(route('finance.termins.pdf', $termin))
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($draft->headers->get('Content-Disposition'))->toContain('invoice-termin-1-');

    $invoice = app(InvoiceService::class)->issueForTermin($termin, ['due_date' => '2026-10-12'], $this->marketing);

    $issued = $this->actingAs($this->finance)->get(route('finance.termins.pdf', $termin->fresh()))->assertOk();
    expect($issued->headers->get('Content-Disposition'))->toContain('invoice-'.str_replace('/', '-', $invoice->number).'.pdf');
});

test('a PM opening an invoiced termin PDF gets the termin bill, never the invoice (finance.invoices.pdf excludes PM)', function () {
    $termin = dpTermin($this);
    app(InvoiceService::class)->issueForTermin($termin, ['due_date' => '2026-10-12'], $this->marketing);

    $response = $this->actingAs(billingUser('PM'))->get(route('finance.termins.pdf', $termin->fresh()))->assertOk();

    expect($response->headers->get('Content-Disposition'))->toContain('invoice-termin-1-');
});

test('the offer keeps its Sprint 15 letter', function () {
    $quotation = billedDesignRab($this)->quotation;
    $html = view('pdf.quotation', ['quotation' => $quotation, 'siteSettings' => SiteSetting::current(), 'validityDays' => 14])->render();

    expect($html)->toContain('Perihal')->toContain('Berlaku Sampai')->toContain('Demikian penawaran')
        ->toContain('GAMBAR KERJA RUANG TAMU')
        ->not->toContain('Total Tagihan')
        ->not->toContain('Cara Pembayaran');
});
