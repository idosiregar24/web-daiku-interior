<?php

namespace App\Http\Controllers\Finance;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\IssueInvoiceRequest;
use App\Http\Requests\Finance\RejectInvoiceRequest;
use App\Http\Requests\Finance\SubmitInvoiceProofRequest;
use App\Http\Requests\Finance\VerifyInvoiceRequest;
use App\Models\BankAccount;
use App\Models\Invoice;
use App\Models\InvoicePaymentProof;
use App\Models\Quotation;
use App\Models\SiteSetting;
use App\Models\Termin;
use App\Services\InvoiceService;
use App\Support\Letters\InvoiceLetter;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sprint 12 decisions #20–#21 — "Invoice" (Marketing issues and follows
 * up, Finance and CEO read) and "Verifikasi Pembayaran" (Finance's queue
 * of invoices with a payment proof). Rules in InvoiceService.
 */
class InvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->list($request, 'all', $request->string('status')->value() ?: null);
    }

    /** Finance's queue — the same list, fixed on MENUNGGU_VERIFIKASI, oldest proof first. */
    public function verification(Request $request): Response
    {
        return $this->list($request, 'verification', InvoiceStatus::MenungguVerifikasi->value);
    }

    public function storeForQuotation(IssueInvoiceRequest $request, Quotation $quotation, InvoiceService $service): RedirectResponse
    {
        $invoice = $service->issueForQuotation($quotation, $request->validated(), $request->user());

        return back()->with('success', "Invoice {$invoice->number} diterbitkan — kirim PDF-nya ke klien. Setelah klien membayar, tekan \"Tandai Klien Sudah Bayar\" di baris invoice ini.");
    }

    public function storeForTermin(IssueInvoiceRequest $request, Termin $termin, InvoiceService $service): RedirectResponse
    {
        $invoice = $service->issueForTermin($termin, $request->validated(), $request->user());

        return back()->with('success', "Invoice {$invoice->number} diterbitkan untuk termin {$termin->termin_number} — setelah klien membayar, tekan \"Tandai Klien Sudah Bayar\" di baris termin ini.");
    }

    public function submitProof(SubmitInvoiceProofRequest $request, Invoice $invoice, InvoiceService $service): RedirectResponse
    {
        $service->submitProof($invoice, $request->validated(), $request->user());

        return back()->with('success', 'Invoice ditandai sudah dibayar klien — menunggu konfirmasi pembayaran dari Finance.');
    }

    /**
     * Sprint 23 K12d — streams one payment proof photo (or its thumbnail,
     * `?thumb=1`) from the private disk. InvoicePolicy::viewPaymentProofs
     * (Finance, CEO, Marketing); the photo must belong to this invoice.
     */
    public function paymentProof(Request $request, Invoice $invoice, InvoicePaymentProof $proof): StreamedResponse
    {
        $this->authorize('viewPaymentProofs', $invoice);
        abort_unless($proof->invoice_id === $invoice->id, 404);

        $disk = Storage::disk(InvoicePaymentProof::DISK);
        $path = $request->boolean('thumb') ? $proof->path_thumb : $proof->path;
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, "bukti-bayar-{$proof->id}.webp", [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    public function verify(VerifyInvoiceRequest $request, Invoice $invoice, InvoiceService $service): RedirectResponse
    {
        $service->verify($invoice, $request->validated(), $request->user());

        return back()->with('success', 'Pembayaran terverifikasi dan dicatat sebagai pemasukan.');
    }

    public function reject(RejectInvoiceRequest $request, Invoice $invoice, InvoiceService $service): RedirectResponse
    {
        $service->reject($invoice, $request->validated('reason'), $request->user());

        return back()->with('success', 'Bukti bayar ditolak — Marketing mendapat notifikasi.');
    }

    public function exportPdf(Invoice $invoice): HttpResponse
    {
        return self::streamPdf($invoice);
    }

    /**
     * Sprint 17 Sub 05 — the invoice's billing layout (InvoiceLetter: the
     * client's contact, the RAB groups or termin, the bank accounts). Also
     * what an invoiced termin's PDF streams (TerminController::exportPdf()).
     */
    public static function streamPdf(Invoice $invoice): HttpResponse
    {
        $invoice->load(InvoiceLetter::RELATIONS);

        return Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'siteSettings' => SiteSetting::current(),
            // "1/INV/Daiku/X/2026" → "invoice-1-INV-Daiku-X-2026.pdf" (no slashes in a file name).
        ])->stream('invoice-'.str_replace('/', '-', $invoice->number).'.pdf');
    }

    private function list(Request $request, string $mode, ?string $status): Response
    {
        $user = $request->user();
        $canVerify = $user->hasAnyRole(['FINANCE', 'SUPERADMIN']);
        $canSubmitProof = $user->hasAnyRole(['MARKETING', 'FINANCE', 'SUPERADMIN']);
        // Sprint 17 Sub 07 — "Invoice menunggu konfirmasi bayar" (same scope as the Perlu Tindakan queue).
        $awaitingProof = $mode === 'all' && $request->boolean('awaiting_proof');
        $marketingScope = $user->hasRole('MARKETING') && ! $user->hasAnyRole(['CEO', 'FINANCE', 'SUPERADMIN']) ? $user : null;
        // Sprint 23 K12d — the proof photos, for the roles of InvoicePolicy::viewPaymentProofs.
        $canSeeProofs = $user->can('viewPaymentProofs', Invoice::class);

        $invoices = Invoice::query()
            // Sprint 23 K12/K12b — "Termin 2 dari 4 · 30%" + project / client, built once in Invoice.
            ->withPaymentLabelRelations()
            ->with(['quotation:id,type,version', 'issuer:id,name', 'verifier:id,name', 'bankAccount:id,label'])
            ->when($canSeeProofs, fn ($query) => $query->with('paymentProofs'))
            ->when($awaitingProof, fn ($query) => $query->awaitingProof($marketingScope))
            ->byStatus($status)
            ->byType($request->string('type')->value() ?: null)
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($q) => $q
                ->where('number', 'like', '%'.$request->string('search').'%')
                ->orWhereHas('lead', fn ($lead) => $lead->where('client_name', 'like', '%'.$request->string('search').'%'))
                ->orWhereHas('project', fn ($project) => $project->where('name', 'like', '%'.$request->string('search').'%'))))
            ->when($mode === 'verification', fn ($query) => $query->orderBy('proof_submitted_at'), fn ($query) => $query->latest('issued_at'))
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Invoice $invoice) => $invoice->append(Invoice::PAYMENT_LABELS));

        return Inertia::render('Finance/Invoices/Index', [
            'mode' => $mode,
            'invoices' => $invoices,
            'filters' => $request->only(['status', 'type', 'search', 'awaiting_proof']),
            'canVerify' => $canVerify,
            'canSubmitProof' => $canSubmitProof,
            // Sprint 17 Sub 07 — `?proof={id}` (Perlu Tindakan, "bukti ditolak" notification)
            // opens "Tandai Klien Sudah Bayar" for that invoice, whatever page of the list it is on.
            'proofInvoice' => $canSubmitProof && $request->integer('proof') > 0
                ? Invoice::query()->withPaymentLabelRelations()->whereKey($request->integer('proof'))
                    ->where('status', InvoiceStatus::Diterbitkan->value)
                    ->first()
                    ?->append(Invoice::PAYMENT_LABELS)
                    ->only(['id', 'number', 'amount', 'lead', 'reject_reason', 'proof_attempt', ...Invoice::PAYMENT_LABELS])
                : null,
            // Sprint 23 K12b — `?verify={id}` (Perlu Tindakan, the Finance notification) opens
            // the Verifikasi dialog for that invoice, whatever page of the queue it is on.
            'verifyInvoice' => $canVerify && $request->integer('verify') > 0
                ? Invoice::query()->withPaymentLabelRelations()->with('paymentProofs')->whereKey($request->integer('verify'))
                    ->where('status', InvoiceStatus::MenungguVerifikasi->value)
                    ->first()
                    ?->append(Invoice::PAYMENT_LABELS)
                : null,
            'bankAccounts' => $canVerify ? BankAccount::where('is_active', true)->orderBy('label')->get(['id', 'label']) : [],
        ]);
    }
}
