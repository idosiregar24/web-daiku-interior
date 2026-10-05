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
use App\Models\Quotation;
use App\Models\SiteSetting;
use App\Models\Termin;
use App\Services\InvoiceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

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

        return back()->with('success', "Invoice {$invoice->number} diterbitkan — kirim ke klien bersama PDF-nya.");
    }

    public function storeForTermin(IssueInvoiceRequest $request, Termin $termin, InvoiceService $service): RedirectResponse
    {
        $invoice = $service->issueForTermin($termin, $request->validated(), $request->user());

        return back()->with('success', "Invoice {$invoice->number} diterbitkan untuk termin {$termin->termin_number}.");
    }

    public function submitProof(SubmitInvoiceProofRequest $request, Invoice $invoice, InvoiceService $service): RedirectResponse
    {
        $service->submitProof($invoice, $request->validated('payment_proof_url'), $request->user());

        return back()->with('success', 'Bukti bayar dikirim — menunggu verifikasi Finance.');
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
        $invoice->load(['lead:id,client_name,address', 'quotation:id,type,version', 'quotation.items.unit', 'quotation.sections']);

        return Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'siteSettings' => SiteSetting::current(),
            'bankAccounts' => BankAccount::where('is_active', true)->orderBy('label')->get(),
        ])->stream("{$invoice->number}.pdf");
    }

    private function list(Request $request, string $mode, ?string $status): Response
    {
        $user = $request->user();
        $canVerify = $user->hasAnyRole(['FINANCE', 'SUPERADMIN']);

        $invoices = Invoice::query()
            ->with(['lead:id,client_name', 'quotation:id,type,version', 'issuer:id,name', 'verifier:id,name', 'bankAccount:id,label'])
            ->byStatus($status)
            ->byType($request->string('type')->value() ?: null)
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($q) => $q
                ->where('number', 'like', '%'.$request->string('search').'%')
                ->orWhereHas('lead', fn ($lead) => $lead->where('client_name', 'like', '%'.$request->string('search').'%'))))
            ->when($mode === 'verification', fn ($query) => $query->orderBy('proof_submitted_at'), fn ($query) => $query->latest('issued_at'))
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Finance/Invoices/Index', [
            'mode' => $mode,
            'invoices' => $invoices,
            'filters' => $request->only(['status', 'type', 'search']),
            'canVerify' => $canVerify,
            'canSubmitProof' => $user->hasAnyRole(['MARKETING', 'FINANCE', 'SUPERADMIN']),
            'bankAccounts' => $canVerify ? BankAccount::where('is_active', true)->orderBy('label')->get(['id', 'label']) : [],
        ]);
    }
}
