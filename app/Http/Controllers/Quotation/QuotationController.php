<?php

namespace App\Http\Controllers\Quotation;

use App\Enums\InvoiceType;
use App\Enums\QuotationStatus;
use App\Exports\QuotationExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Quotation\CancelQuotationRequest;
use App\Http\Requests\Quotation\ClientRejectQuotationRequest;
use App\Http\Requests\Quotation\ReviewQuotationRequest;
use App\Http\Requests\Quotation\SaveClientNotesRequest;
use App\Http\Requests\Quotation\SavePaymentTermsRequest;
use App\Http\Requests\Quotation\UpdateQuotationItemsRequest;
use App\Models\Design;
use App\Models\Quotation;
use App\Models\QuotationItemReview;
use App\Models\SiteSetting;
use App\Models\Unit;
use App\Services\DesignService;
use App\Services\QuotationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * PRD §4.3. RAB builder (Sprint 2 Week 4; Sprint 12 sections & payment
 * scheme), PDF/Excel export, the Sprint 12 approval flow (item review by
 * PM / Asisten PM, then CEO for a RAB Proyek; Estimator → Marketing →
 * client) and the client's rejection, revision history and validity
 * period (Sprint 9) — see QuotationService's docblock for the state
 * machine. The client's acceptance is PublicQuotationController (Sprint 12 Sub 5).
 */
class QuotationController extends Controller
{
    public function index(Request $request): Response
    {
        $quotations = Quotation::query()
            ->with(['lead:id,client_name', 'requester:id,name'])
            ->byStatus($request->string('status')->value() ?: null)
            // Sprint 12 #6 — RAB Jasa Survey / Jasa Desain / Proyek.
            ->byType($request->string('type')->value() ?: null)
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Quotation/Index', [
            'quotations' => $quotations,
            'filters' => $request->only(['status', 'type']),
        ]);
    }

    public function show(Request $request, Quotation $quotation): Response
    {
        $quotation->load([
            'lead:id,client_name,phone,email',
            'items',
            // Sprint 12 #11–#12 — bagian pekerjaan, skema DP/termin, who asked for it.
            'sections:id,quotation_id,name,sort_order',
            'paymentTerms',
            'requester:id,name',
            // Sprint 14 Sub 01 — links & photos from the request (internal only).
            'references:id,quotation_id,kind,url,original_name,size',
            // Sprint 12 #29 — an addendum's project and RAB Fix.
            'project:id,name',
            'parent:id,version,total_amount',
            'approvals.approver:id,name',
            'revisions.closer:id,name',
        ]);

        $user = $request->user();
        $isReviewer = fn (string $stage) => $user->hasAnyRole([...QuotationService::REVIEW_ROLES[$stage], 'SUPERADMIN']);

        return Inertia::render('Quotation/Show', [
            'quotation' => $quotation,
            'canManage' => $user->hasAnyRole(['ESTIMATOR', 'SUPERADMIN']),
            // Sprint 12 #7–#8 — whose review it is right now (QuotationService::reviewStage()).
            'reviewStage' => match (true) {
                $quotation->status === QuotationStatus::Submitted && $isReviewer(QuotationItemReview::STAGE_PM) => QuotationItemReview::STAGE_PM,
                $quotation->status === QuotationStatus::WaitingCeo && $isReviewer(QuotationItemReview::STAGE_CEO) => QuotationItemReview::STAGE_CEO,
                default => null,
            },
            // ✔/✘ marks of this version and the one before (the ✘ the Estimator is fixing).
            'itemReviews' => $quotation->itemReviews()
                ->with('reviewer:id,name')
                ->where('version', '>=', $quotation->version - 1)
                ->get(),
            // Send to the client, record their rejection, cancel — the
            // `quotations.sendToClient` / `clientReject` / `cancel` roles.
            'canClientDecide' => $user->hasAnyRole(['CEO', 'MARKETING', 'SUPERADMIN']),
            'validityDays' => QuotationService::VALIDITY_DAYS,
            // Master Satuan dropdown for the RAB builder (only editable while DRAFT).
            'units' => $quotation->status === QuotationStatus::Draft ? Unit::options() : [],
            'maxPaymentTerms' => QuotationService::MAX_PAYMENT_TERMS,
            // Sprint 15 K4 — printed when the Estimator wrote no Catatan of their own.
            'defaultClientNotes' => SiteSetting::current()->defaultNoteFor($quotation->type->value),
            // Sprint 12 #13 — the client's link of this version, for Marketing to copy / WhatsApp.
            'shareUrl' => $user->hasAnyRole(['CEO', 'MARKETING', 'SUPERADMIN'])
                ? $quotation->currentShareLink()?->url()
                : null,
            // Sprint 12 #20 — the invoice of an approved Jasa Survey / Jasa Desain RAB.
            'invoices' => $quotation->invoices()->get(['id', 'quotation_id', 'number', 'type', 'amount', 'due_date', 'status']),
            'canIssueInvoice' => $user->hasAnyRole(['MARKETING', 'SUPERADMIN'])
                && $quotation->status === QuotationStatus::ClientApproved
                && InvoiceType::forQuotation($quotation->type) !== null
                && ! $quotation->invoices()->exists(),
            // Sprint 12 #18 / D6 — the lead's Arsitek ↔ Estimator thread, when it has a design.
            'discussion' => ($design = Design::where('lead_id', $quotation->lead_id)->first())
                ? app(DesignService::class)->threadFor($design, $user)
                : null,
        ]);
    }

    /** Sprint 12 #11 — the whole RAB: sections + items + discount + rounding (QuotationService::saveRab()). */
    public function updateItems(UpdateQuotationItemsRequest $request, Quotation $quotation, QuotationService $service): RedirectResponse
    {
        $service->saveRab($quotation, $request->validated());

        return back()->with('success', 'RAB berhasil disimpan.');
    }

    /** Sprint 12 #12 — the DP/termin scheme the client approves with the RAB. */
    public function updatePaymentTerms(SavePaymentTermsRequest $request, Quotation $quotation, QuotationService $service): RedirectResponse
    {
        $service->savePaymentTerms($quotation, $request->validated('terms'));

        return back()->with('success', 'Skema pembayaran berhasil disimpan.');
    }

    /** Sprint 15 K4 — the "Catatan" printed on the letter (DRAFT only). */
    public function updateClientNotes(SaveClientNotesRequest $request, Quotation $quotation, QuotationService $service): RedirectResponse
    {
        $service->saveClientNotes($quotation, $request->validated('client_notes'));

        return back()->with('success', 'Catatan untuk klien disimpan.');
    }

    /** Sprint 12 #7 — the Estimator picks up a RAB Marketing asked for (DIMINTA → DRAFT). */
    public function startDraft(Request $request, Quotation $quotation, QuotationService $service): RedirectResponse
    {
        $service->startDraft($quotation, $request->user());

        return back()->with('success', 'RAB mulai disusun.');
    }

    public function submit(Quotation $quotation, QuotationService $service): RedirectResponse
    {
        $service->submit($quotation);

        return back()->with('success', 'Quotation berhasil disubmit untuk review.');
    }

    /** Sprint 12 #8 — ✔/✘ per item, then approve or return (PM / Asisten PM, then CEO for a RAB Proyek). */
    public function review(ReviewQuotationRequest $request, Quotation $quotation, QuotationService $service): RedirectResponse
    {
        $quotation = $service->review($quotation, $request->validated(), $request->user());

        return back()->with('success', match ($quotation->status) {
            QuotationStatus::Draft => "RAB dikembalikan ke Estimator sebagai versi {$quotation->version}.",
            QuotationStatus::WaitingCeo => 'RAB disetujui — diteruskan ke CEO.',
            default => 'RAB disetujui — Estimator bisa mengirimnya ke Marketing.',
        });
    }

    /** Sprint 12 #10 — the Estimator hands the internally approved RAB to Marketing. */
    public function sendToMarketing(Request $request, Quotation $quotation, QuotationService $service): RedirectResponse
    {
        $service->sendToMarketing($quotation, $request->user());

        return back()->with('success', 'RAB final dikirim ke Marketing.');
    }

    /** Sprint 12 #10 — Marketing sends the final RAB to the client (Sub 5 adds the approval link). */
    public function sendToClient(Request $request, Quotation $quotation, QuotationService $service): RedirectResponse
    {
        $quotation = $service->sendToClient($quotation, $request->user());

        return back()->with('success', 'RAB ditandai terkirim ke klien — berlaku sampai '.$quotation->valid_until->translatedFormat('d F Y').'.');
    }

    public function cancel(CancelQuotationRequest $request, Quotation $quotation, QuotationService $service): RedirectResponse
    {
        $service->cancel($quotation, $request->user(), $request->validated('reason'));

        return back()->with('success', 'RAB dibatalkan.');
    }

    /**
     * PRD §6.2 "SENT TO CLIENT → REJECTED (klien) → DRAFT (revisi)" — see
     * QuotationService::clientReject().
     */
    public function clientReject(ClientRejectQuotationRequest $request, Quotation $quotation, QuotationService $service): RedirectResponse
    {
        $quotation = $service->clientReject($quotation, $request->user(), $request->validated('note'));

        return back()->with('success', "Penolakan klien dicatat — quotation kembali ke DRAFT sebagai versi {$quotation->version} untuk direvisi.");
    }

    public function exportPdf(Quotation $quotation): HttpResponse
    {
        $quotation->load(['lead:id,client_name,address', 'items.unit', 'sections', 'paymentTerms']);

        $pdf = Pdf::loadView('pdf.quotation', [
            'quotation' => $quotation,
            'siteSettings' => SiteSetting::current(),
            'validityDays' => QuotationService::VALIDITY_DAYS,
        ]);

        return $pdf->stream(self::pdfName($quotation));
    }

    /** "penawaran-377-OFF-Daiku-IX-2026-budi.pdf", or "-draf-v2" before it is sent (Sprint 15). */
    public static function pdfName(Quotation $quotation): string
    {
        $number = $quotation->letter_number ? str_replace('/', '-', $quotation->letter_number) : "draf-v{$quotation->version}";

        return 'penawaran-'.$number.'-'.str($quotation->lead->client_name)->slug().'.pdf';
    }

    /** Sprint 12 #11 — the RAB in the Estimator's Excel layout (App\Exports\QuotationExport). */
    public function exportExcel(Quotation $quotation): BinaryFileResponse
    {
        $quotation->load(['lead:id,client_name', 'items', 'sections', 'paymentTerms']);

        $client = str($quotation->lead->client_name)->slug();

        return Excel::download(new QuotationExport($quotation), "rab-{$client}-v{$quotation->version}.xlsx");
    }
}
