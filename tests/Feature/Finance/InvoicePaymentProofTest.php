<?php

use App\Enums\InvoiceStatus;
use App\Enums\PaymentTermTrigger;
use App\Enums\QuotationStatus;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\InvoicePaymentProof;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\Termin;
use App\Models\User;
use App\Services\ActionInboxService;
use App\Services\InvoiceService;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Sprint 23 Sub 02 — K12 (which payment: "Termin 2 dari 4 · 30%" + project),
 * K12b ("Menunggu Konfirmasi Pembayaran DP · Proyek X" in status, Finance
 * notification, Perlu Tindakan, Verifikasi dialog) and K12d (optional proof
 * photos on a private disk, append-only per attempt, authorised route).
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-14 10:00:00', 'Asia/Jakarta'));
    Storage::fake(InvoicePaymentProof::DISK);
    $this->seed(RoleSeeder::class);
    $this->marketing = ppUser('MARKETING');
    $this->finance = ppUser('FINANCE');
    $this->lead = Lead::factory()->create(['assigned_to' => $this->marketing->id, 'client_name' => 'Bu Sari']);
});

afterEach(fn () => Carbon::setTestNow());

function ppUser(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function ppDesignInvoice(object $test): Invoice
{
    $quotation = Quotation::factory()->create([
        'lead_id' => $test->lead->id,
        'type' => 'DESAIN',
        'status' => QuotationStatus::ClientApproved->value,
        'total_amount' => 5_000_000,
    ]);

    return app(InvoiceService::class)->issueForQuotation($quotation, ['due_date' => '2026-10-20'], $test->marketing);
}

/** A project "Kopi Oz" with a 4-row scheme: DP 30%, 30%, 20%, pelunasan 20%. */
function ppProjectTermins(object $test): array
{
    $quotation = Quotation::factory()->create(['lead_id' => $test->lead->id, 'type' => 'PROYEK', 'status' => QuotationStatus::ClientApproved->value, 'total_amount' => 100_000_000]);
    $project = Project::factory()->create(['lead_id' => $test->lead->id, 'name' => 'Kopi Oz', 'quotation_id' => $quotation->id]);

    return collect([[30, PaymentTermTrigger::DiMuka], [30, PaymentTermTrigger::Tanggal], [20, PaymentTermTrigger::Tanggal], [20, PaymentTermTrigger::ProyekSelesai]])
        ->map(fn (array $row, int $i) => Termin::factory()->create([
            'project_id' => $project->id,
            'quotation_id' => $quotation->id,
            'termin_number' => $i + 1,
            'trigger' => $row[1]->value,
            'percentage' => $row[0],
            'amount' => $row[0] * 1_000_000,
            'scheduled_date' => '2026-10-17',
        ]))->all();
}

function ppPhoto(string $name = 'bukti.jpg', int $width = 800, int $height = 600): UploadedFile
{
    return UploadedFile::fake()->image($name, $width, $height);
}

// ── K12 / K12b — which payment ───────────────────────────────────────────

test('a termin invoice says which termin of how many, and the waiting status names the payment and the project', function () {
    [, $second] = ppProjectTermins($this);
    $invoice = app(InvoiceService::class)->issueForTermin($second, ['due_date' => '2026-10-20'], $this->marketing);

    expect($invoice->type->value)->toBe('TERMIN')
        ->and($invoice->fresh()->paymentTypeLabel())->toBe('Termin 2 dari 4 · 30%')
        ->and($invoice->fresh()->paymentLabel())->toBe('Termin 2 dari 4 · 30% · Proyek Kopi Oz');

    $this->actingAs($this->marketing)->post(route('finance.invoices.proof', $invoice), ['payment_note' => 'Transfer BCA'])
        ->assertSessionHasNoErrors();

    $sentence = 'Menunggu Konfirmasi Pembayaran Termin 2 dari 4 · 30% · Proyek Kopi Oz';

    // Finance notification title, the Perlu Tindakan item, and the list/dialog attribute — one sentence.
    expect(Notification::where('user_id', $this->finance->id)->where('type', 'invoice_awaiting_verification')->sole()->title)->toBe($sentence);

    $group = collect(app(ActionInboxService::class)->for($this->finance))->firstWhere('key', 'invoice-verify');
    expect($group['items'][0]['title'])->toBe($sentence)
        ->and($group['items'][0]['href'])->toBe(route('finance.invoices.verification', ['verify' => $invoice->id]));

    $this->actingAs($this->finance)->get(route('finance.invoices.verification', ['verify' => $invoice->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('invoices.data.0.payment_type_label', 'Termin 2 dari 4 · 30%')
            ->where('invoices.data.0.payment_label', 'Termin 2 dari 4 · 30% · Proyek Kopi Oz')
            ->where('invoices.data.0.status_label', $sentence)
            ->where('verifyInvoice.id', $invoice->id)
            ->where('verifyInvoice.status_label', $sentence));
});

test('DP, pelunasan and service invoices keep their plain names; without a project the client is named', function () {
    [$dp, , , $last] = ppProjectTermins($this);
    $service = app(InvoiceService::class);

    expect($service->issueForTermin($dp, ['due_date' => '2026-10-20'], $this->marketing)->fresh()->paymentLabel())->toBe('DP · Proyek Kopi Oz')
        ->and($service->issueForTermin($last, ['due_date' => '2026-10-20'], $this->marketing)->fresh()->paymentLabel())->toBe('Pelunasan · Proyek Kopi Oz');

    $design = $service->submitProof(ppDesignInvoice($this), [], $this->marketing);
    expect($design->confirmationLabel())->toBe('Menunggu Konfirmasi Pembayaran Jasa Desain · Bu Sari');
});

test('the status label is only sent while the payment waits for Finance', function () {
    $invoice = ppDesignInvoice($this);

    $this->actingAs($this->finance)->get(route('finance.invoices.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('invoices.data.0.status_label', null)
            ->where('invoices.data.0.payment_type_label', 'Jasa Desain'));
});

test('?verify only opens an invoice that is waiting, and only for Finance', function () {
    $waiting = app(InvoiceService::class)->submitProof(ppDesignInvoice($this), [], $this->marketing);
    $issued = ppDesignInvoice($this);

    $this->actingAs($this->finance)->get(route('finance.invoices.verification', ['verify' => $issued->id]))
        ->assertInertia(fn (Assert $page) => $page->where('verifyInvoice', null));
    $this->actingAs(ppUser('CEO'))->get(route('finance.invoices.verification', ['verify' => $waiting->id]))
        ->assertInertia(fn (Assert $page) => $page->where('verifyInvoice', null));
});

// ── K12d — proof photos ──────────────────────────────────────────────────

test('Marketing attaches proof photos: shrunk, re-encoded as WebP on the private disk, recorded under attempt 1', function () {
    $invoice = ppDesignInvoice($this);

    $this->actingAs($this->marketing)->post(route('finance.invoices.proof', $invoice), [
        'payment_note' => 'Transfer BCA',
        'payment_photos' => [ppPhoto('IMG_0001.jpg', 3000, 1500), ppPhoto('layar.png', 400, 800)],
    ])->assertSessionHasNoErrors();

    $invoice->refresh();
    expect($invoice->status)->toBe(InvoiceStatus::MenungguVerifikasi)
        ->and($invoice->proof_attempt)->toBe(1);

    $proofs = InvoicePaymentProof::orderBy('id')->get();
    expect($proofs)->toHaveCount(2)
        ->and($proofs->pluck('attempt')->unique()->all())->toBe([1])
        ->and($proofs[0]->uploaded_by)->toBe($this->marketing->id)
        ->and($proofs[0]->width)->toBe(2000)->and($proofs[0]->height)->toBe(1000);

    $disk = Storage::disk(InvoicePaymentProof::DISK);
    foreach ($proofs as $proof) {
        // The client's file name never survives; the bytes are a fresh WebP.
        expect($proof->path)->toStartWith("invoice-proofs/{$invoice->id}/")->toEndWith('.webp')->not->toContain('IMG_0001')
            ->and($disk->exists($proof->path))->toBeTrue()
            ->and($disk->exists($proof->path_thumb))->toBeTrue()
            ->and(substr($disk->get($proof->path), 8, 4))->toBe('WEBP');
    }

    expect(AuditLog::where('action', 'finance.invoice_proof_submitted')->sole()->new_values)
        ->toMatchArray(['attempt' => 1, 'photo_ids' => $proofs->pluck('id')->all()])
        ->and(Notification::where('user_id', $this->finance->id)->sole()->message)
        ->toContain('Dilampiri 2 foto bukti bayar.')->not->toContain('Tanpa link bukti');
});

test('photos are optional, at most 3, JPG/PNG/WEBP up to 5 MB', function (array $photos, ?string $error) {
    $invoice = ppDesignInvoice($this);

    $response = $this->actingAs($this->marketing)->post(route('finance.invoices.proof', $invoice), ['payment_photos' => $photos]);

    if ($error === null) {
        $response->assertSessionHasNoErrors();
        expect($invoice->fresh()->status)->toBe(InvoiceStatus::MenungguVerifikasi);
    } else {
        $response->assertSessionHasErrors($error);
        expect($invoice->fresh()->status)->toBe(InvoiceStatus::Diterbitkan)
            ->and(InvoicePaymentProof::count())->toBe(0)
            ->and(Storage::disk(InvoicePaymentProof::DISK)->allFiles())->toBe([]);
    }
})->with([
    'none' => [[], null],
    'three' => [fn () => [ppPhoto(), ppPhoto(), ppPhoto()], null],
    'four' => [fn () => [ppPhoto(), ppPhoto(), ppPhoto(), ppPhoto()], 'payment_photos'],
    'a pdf' => [fn () => [UploadedFile::fake()->create('bukti.pdf', 100, 'application/pdf')], 'payment_photos.0'],
    'a gif' => [fn () => [UploadedFile::fake()->image('bukti.gif')], 'payment_photos.0'],
    'a pdf renamed .jpg' => [fn () => [UploadedFile::fake()->create('bukti.jpg', 100, 'application/pdf')], 'payment_photos.0'],
    'over 5 MB' => [fn () => [ppPhoto()->size(5121)], 'payment_photos.0'],
]);

test('a resubmission after Finance rejects is the next attempt; the earlier photos stay as history', function () {
    $invoice = ppDesignInvoice($this);

    $this->actingAs($this->marketing)->post(route('finance.invoices.proof', $invoice), ['payment_photos' => [ppPhoto(), ppPhoto()]]);
    app(InvoiceService::class)->reject($invoice->fresh(), 'Nominal kurang', $this->finance);
    $this->actingAs($this->marketing)->post(route('finance.invoices.proof', $invoice), ['payment_photos' => [ppPhoto()]])
        ->assertSessionHasNoErrors();

    expect($invoice->fresh()->proof_attempt)->toBe(2)
        ->and(InvoicePaymentProof::where('attempt', 1)->count())->toBe(2)
        ->and(InvoicePaymentProof::where('attempt', 2)->count())->toBe(1);

    $this->actingAs($this->finance)->get(route('finance.invoices.verification'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('invoices.data.0.proof_attempt', 2)
            ->has('invoices.data.0.payment_proofs', 3)
            // Newest attempt first; the storage path never leaves the server.
            ->where('invoices.data.0.payment_proofs.0.attempt', 2)
            ->has('invoices.data.0.payment_proofs.0.thumb_url')
            ->missing('invoices.data.0.payment_proofs.0.path')
            ->missing('invoices.data.0.payment_proofs.0.path_thumb'));
});

test('proof photos are append-only', function () {
    $invoice = ppDesignInvoice($this);
    $this->actingAs($this->marketing)->post(route('finance.invoices.proof', $invoice), ['payment_photos' => [ppPhoto()]]);
    $proof = InvoicePaymentProof::sole();

    expect(fn () => $proof->update(['attempt' => 9]))->toThrow(LogicException::class)
        ->and(fn () => $proof->delete())->toThrow(LogicException::class)
        ->and(InvoicePaymentProof::sole()->attempt)->toBe(1);
});

test('a photo is served only to Finance, CEO and Marketing, and only under its own invoice', function (string $role, int $status) {
    $invoice = ppDesignInvoice($this);
    $this->actingAs($this->marketing)->post(route('finance.invoices.proof', $invoice), ['payment_photos' => [ppPhoto()]]);
    $proof = InvoicePaymentProof::sole();

    $response = $this->actingAs(ppUser($role))->get(route('finance.invoices.proofs.show', ['invoice' => $invoice, 'proof' => $proof]));
    $response->assertStatus($status);

    if ($status === 200) {
        expect($response->headers->get('Content-Type'))->toBe('image/webp')
            ->and($response->headers->get('Cache-Control'))->toContain('private');
        $this->get(route('finance.invoices.proofs.show', ['invoice' => $invoice, 'proof' => $proof, 'thumb' => 1]))->assertOk();
    }
})->with([
    ['FINANCE', 200],
    ['CEO', 200],
    ['MARKETING', 200],
    ['PM', 403],
    ['ESTIMATOR', 403],
    ['DESIGNER', 403],
    ['FIELD_STAFF', 403],
]);

test('a photo cannot be fetched through another invoice, nor by a guest', function () {
    $invoice = ppDesignInvoice($this);
    $other = ppDesignInvoice($this);
    $this->actingAs($this->marketing)->post(route('finance.invoices.proof', $invoice), ['payment_photos' => [ppPhoto()]]);
    $proof = InvoicePaymentProof::sole();

    $this->actingAs($this->finance)->get(route('finance.invoices.proofs.show', ['invoice' => $other, 'proof' => $proof]))->assertNotFound();

    auth()->logout();
    $this->get(route('finance.invoices.proofs.show', ['invoice' => $invoice, 'proof' => $proof]))->assertRedirect(route('login'));
});

test('the invoice_payment_proofs and proof_attempt migrations roll back', function () {
    foreach (['2026_10_14_000054_add_proof_attempt_to_invoices_table.php', '2026_10_13_235815_create_invoice_payment_proofs_table.php'] as $file) {
        $migration = require database_path("migrations/{$file}");
        $migration->down();
    }

    expect(Schema::hasTable('invoice_payment_proofs'))->toBeFalse()
        ->and(Schema::hasColumn('invoices', 'proof_attempt'))->toBeFalse();

    foreach (['2026_10_13_235815_create_invoice_payment_proofs_table.php', '2026_10_14_000054_add_proof_attempt_to_invoices_table.php'] as $file) {
        (require database_path("migrations/{$file}"))->up();
    }

    expect(Schema::hasTable('invoice_payment_proofs'))->toBeTrue()
        ->and(Schema::hasColumn('invoices', 'proof_attempt'))->toBeTrue();
});
