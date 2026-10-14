<?php

namespace App\Http\Controllers\Quotation;

use App\Enums\InvoiceStatus;
use App\Enums\LeadStatus;
use App\Enums\LeadSurveyStatus;
use App\Enums\QuotationStatus;
use App\Enums\QuotationType;
use App\Exports\QuotationExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Quotation\CancelQuotationRequest;
use App\Http\Requests\Quotation\ClientRejectQuotationRequest;
use App\Http\Requests\Quotation\ForwardClientRejectionRequest;
use App\Http\Requests\Quotation\ReviewQuotationRequest;
use App\Http\Requests\Quotation\SaveClientNotesRequest;
use App\Http\Requests\Quotation\SavePaymentTermsRequest;
use App\Http\Requests\Quotation\StoreQuotationNoteRequest;
use App\Http\Requests\Quotation\UpdateQuotationItemsRequest;
use App\Models\Design;
use App\Models\Quotation;
use App\Models\QuotationItemReview;
use App\Models\SiteSetting;
use App\Models\Unit;
use App\Models\User;
use App\Services\DesignService;
use App\Services\InvoiceService;
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
 * scheme; Sprint 23 K5 volume from P / T/L), PDF/Excel export, the
 * approval flow (Sprint 23: item review by PM / Asisten PM inside the RAB
 * table → straight to Marketing → client; the CEO only adds notes), the
 * client's rejection (recorded here, or left on the link and forwarded to
 * the Estimator by Marketing), revision history and validity period
 * (Sprint 9) — see QuotationService's docblock for the state machine. The
 * client's own decision is PublicQuotationController (Sprint 12 Sub 5,
 * Sprint 23 K11).
 */
class QuotationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $quotations = Quotation::query()
            ->with(['lead:id,client_name', 'requester:id,name'])
            ->byStatus($request->string('status')->value() ?: null)
            // Sprint 12 #6 — RAB Jasa Survey / Jasa Desain / Proyek.
            ->byType($request->string('type')->value() ?: null)
            // Sprint 17 Sub 03 — "Lihat semua" of the Perlu Tindakan queue
            // (ActionInboxService::invoiceToIssue()): a Marketing sees their own leads' only.
            ->when($request->boolean('awaiting_invoice'), fn ($query) => $query->awaitingInvoice($user->hasRole('MARKETING') ? $user : null))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Quotation/Index', [
            'quotations' => $quotations,
            'filters' => $request->only(['status', 'type', 'awaiting_invoice']),
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
        $service = app(QuotationService::class);
        $submitted = $quotation->status === QuotationStatus::Submitted;

        return Inertia::render('Quotation/Show', [
            'quotation' => $quotation,
            'canManage' => $user->hasAnyRole(['ESTIMATOR', 'SUPERADMIN']),
            // Sprint 23 K6 — the viewer marks ✔/✘ inside the RAB table now (QuotationService::reviewStage()).
            'reviewStage' => $submitted && $user->hasAnyRole([...QuotationService::REVIEWER_ROLES, 'SUPERADMIN'])
                ? QuotationItemReview::STAGE_PM
                : null,
            // Sprint 23 K7 — item id → the version whose ✔ it carries (locked in the review).
            'carriedMarks' => (object) ($submitted ? $service->carriedMarks($quotation) : []),
            // ✔/✘ marks of this version and the one before (the ✘ the Estimator is fixing).
            'itemReviews' => $quotation->itemReviews()
                ->with('reviewer:id,name')
                ->where('version', '>=', $quotation->version - 1)
                ->get(),
            // Sprint 23 K9 / K11 — CEO, client and Marketing notes, for the people working the RAB.
            'notes' => $user->hasAnyRole(self::NOTE_READERS)
                ? $quotation->notes()->with('author:id,name')->get()
                : [],
            'canAddNote' => $user->hasAnyRole(['CEO', 'SUPERADMIN']) && $quotation->status !== QuotationStatus::Cancelled,
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
            'invoices' => $quotation->invoices()->get(['id', 'quotation_id', 'number', 'type', 'amount', 'due_date', 'status', 'reject_reason']),
            // Sprint 17 Sub 07 — "Kirim Bukti Bayar" on the invoice rows here.
            'canSubmitProof' => $user->hasAnyRole(['MARKETING', 'FINANCE', 'SUPERADMIN']),
            // Sprint 17 Sub 06 (K2) — also a RAB Proyek's DP before the project is opened.
            'issuableInvoice' => $issuable = $user->hasAnyRole(['MARKETING', 'SUPERADMIN'])
                ? app(InvoiceService::class)->issuableFor($quotation)
                : null,
            'canIssueInvoice' => $issuable !== null,
            // Sprint 12 #18 / D6 — the lead's Arsitek ↔ Estimator thread, when it has a design.
            'discussion' => ($design = Design::where('lead_id', $quotation->lead_id)->first())
                ? app(DesignService::class)->threadFor($design, $user)
                : null,
            // Sprint 19 — "Klien & langkah berikutnya": back to the lead page.
            'canOpenLead' => $user->hasAnyRole(['CEO', 'MARKETING', 'DESIGNER', 'ESTIMATOR', 'PM', 'SUPERADMIN']),
            'surveyPanel' => $this->surveyPanel($quotation, $user),
        ]);
    }

    /** Sprint 23 K9 — who reads the RAB's notes (Estimator, PM side, Marketing, CEO). */
    private const NOTE_READERS = ['CEO', 'ESTIMATOR', 'PM', 'ASISTEN_PM', 'MARKETING', 'SUPERADMIN'];

    /**
     * Sprint 19 Sub 03 (K2) — a RAB Jasa Survey's survey, scheduled from
     * here once paid (posted to the lead's own `crm.surveys.*` routes, so
     * the same Form Request and LeadService rules apply). Only for whoever
     * writes surveys (`role:CEO|MARKETING`).
     *
     * @return array<string, mixed>|null
     */
    private function surveyPanel(Quotation $quotation, User $user): ?array
    {
        if ($quotation->type !== QuotationType::Survey || ! $user->hasAnyRole(['CEO', 'MARKETING', 'SUPERADMIN'])) {
            return null;
        }

        $lead = $quotation->lead()->first(['id', 'status', 'address']);
        $survey = $quotation->leadSurvey()->first(['id', 'lead_id', 'sequence', 'scheduled_at', 'address', 'maps_url', 'is_outside_pekanbaru', 'status']);
        $survey = $survey?->status === LeadSurveyStatus::Batal ? null : $survey;
        $paid = $quotation->invoices()->where('status', InvoiceStatus::Terverifikasi->value)->exists();
        $leadOpen = ! in_array($lead?->status, [LeadStatus::Lost, LeadStatus::Closing], true);

        return [
            'paid' => $paid,
            'survey' => $survey,
            'canSchedule' => $paid && $leadOpen && $survey === null,
            'canReschedule' => $survey !== null && $survey->status->isOpen(),
            'leadAddress' => $lead?->address,
        ];
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

    /** Sprint 12 #8 / Sprint 23 K6–K8 — ✔/✘ per item, then approve (straight to Marketing) or return. */
    public function review(ReviewQuotationRequest $request, Quotation $quotation, QuotationService $service): RedirectResponse
    {
        $quotation = $service->review($quotation, $request->validated(), $request->user());

        return back()->with('success', $quotation->status === QuotationStatus::Draft
            ? "RAB dikembalikan ke Estimator sebagai versi {$quotation->version}."
            : 'RAB disetujui dan langsung diteruskan ke Marketing untuk dikirim ke klien.');
    }

    /** Sprint 23 K9 — the CEO's information-only note (never returns the RAB). */
    public function storeNote(StoreQuotationNoteRequest $request, Quotation $quotation, QuotationService $service): RedirectResponse
    {
        $service->addCeoNote($quotation, $request->validated('note'), $request->user());

        return back()->with('success', 'Catatan disimpan dan dikirim ke Estimator, PM, dan Marketing.');
    }

    /** Sprint 23 K11 — Marketing completes the client's "Tidak Setuju" and hands it to the Estimator. */
    public function forwardToEstimator(ForwardClientRejectionRequest $request, Quotation $quotation, QuotationService $service): RedirectResponse
    {
        $quotation = $service->forwardClientRejection($quotation, $request->user(), $request->validated('note'));

        return back()->with('success', "Diteruskan ke Estimator — RAB dibuka lagi sebagai versi {$quotation->version} untuk direvisi.");
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
