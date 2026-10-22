import { RabReferenceList } from '@/Components/modules/quotation/RabReferenceList';
import { formatDate, formatDateTime, formatRupiah } from '@/lib/format';
import { INVOICE_TYPE_LABEL, InvoicePdfButton, InvoiceProofButton, InvoiceProofDialog, InvoiceStatusChip, IssueInvoiceDialog } from '@/Components/modules/finance/InvoiceDialogs';
import { MoreActions } from '@/Components/shared/MoreActions';
import { Notice } from '@/Components/shared/Notice';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import { SectionCard } from '@/Components/shared/SectionCard';
import { Button } from '@/Components/ui/button';
import { DropdownMenuItem, DropdownMenuSeparator } from '@/Components/ui/dropdown-menu';
import { CancelQuotationDialog } from '@/Components/modules/quotation/CancelQuotationDialog';
import { quotationTitle } from '@/Components/modules/quotation/labels';
import { ClientNotesCard } from '@/Components/modules/quotation/ClientNotesCard';
import { PaymentTermsEditor } from '@/Components/modules/quotation/PaymentTermsEditor';
import { RabBuilder } from '@/Components/modules/quotation/RabBuilder';
import { ShareLinkActions, ShareLinkPanel } from '@/Components/modules/quotation/ShareLinkPanel';
import { QuotationDecisionDialog } from '@/Components/modules/quotation/QuotationDecisionDialog';
import { QuotationExpiryNotice } from '@/Components/modules/quotation/QuotationExpiryNotice';
import { QuotationNextStepCard, SurveyScheduleDialog, type QuotationSurveyPanel } from '@/Components/modules/quotation/QuotationNextStepCard';
import { QuotationNoteDialog } from '@/Components/modules/quotation/QuotationNoteDialog';
import { QuotationNotesCard } from '@/Components/modules/quotation/QuotationNotesCard';
import { QuotationRevisionHistory } from '@/Components/modules/quotation/QuotationRevisionHistory';
import { DesignDiscussionPanel } from '@/Components/modules/design/DesignDiscussionPanel';
import AppLayout from '@/Layouts/AppLayout';
import type {
    Quotation,
    Invoice,
    QuotationApproval,
    QuotationItemReview,
    QuotationNote,
    QuotationRevisionReason,
    QuotationStatus,
    UnitOption,
    DesignDiscussionThread,
} from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    Ban,
    Banknote,
    CalendarClock,
    Calculator,
    FileClock,
    FileDown,
    FileSpreadsheet,
    FileText,
    Forward,
    Handshake,
    History,
    MessageSquarePlus,
    ReceiptText,
    Send,
    UserRound,
    Wallet,
} from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';

interface QuotationShowProps {
    quotation: Quotation & { lead: { id: number; client_name: string; phone?: string | null; email?: string | null } };
    /** ESTIMATOR — drafts and submits the RAB. */
    canManage: boolean;
    /** 'PM' when it's this viewer's turn to review (QuotationService::reviewStage()). Sprint 23 K8: no CEO stage. */
    reviewStage: 'PM' | null;
    /** Sprint 23 K7 — item id → the version whose ✔ it carries (while SUBMITTED). */
    carriedMarks: Record<number, number>;
    /** ✔/✘ marks of this version and the previous one. */
    itemReviews: QuotationItemReview[];
    /** Sprint 23 K9 / K11 — CEO, client and Marketing notes (Estimator, PM side, Marketing, CEO only). */
    notes: QuotationNote[];
    /** The CEO adds information-only notes. */
    canAddNote: boolean;
    /** CEO/Marketing — send to the client, record / forward the client's rejection, cancel. */
    canClientDecide: boolean;
    /** QuotationService::VALIDITY_DAYS */
    validityDays: number;
    /** Active Master Satuan units for the RAB builder (empty once the quotation left DRAFT). */
    units: UnitOption[];
    /** QuotationService::MAX_PAYMENT_TERMS */
    maxPaymentTerms: number;
    /** Sprint 15 K4 — the type's default "Catatan" (Pengaturan Situs), used when the RAB has none. */
    defaultClientNotes: string;
    /** Sprint 12 #13 — this version's public link (CEO / Marketing only), once sent. */
    shareUrl: string | null;
    /** Sprint 12 #20 — invoices billed from this RAB. */
    invoices: (Pick<Invoice, 'id' | 'number' | 'type' | 'amount' | 'due_date' | 'status' | 'reject_reason'> & Partial<Pick<Invoice, 'status_label'>>)[];
    /** Sprint 17 Sub 07 — "Kirim Bukti Bayar" on the invoice rows (Marketing / Finance). */
    canSubmitProof: boolean;
    /** Marketing, on an approved Jasa Survey / Jasa Desain RAB without an invoice yet — or (Sprint 17 Sub 06) a RAB Proyek's DP before the project is opened. */
    canIssueInvoice: boolean;
    /** What "Terbitkan Invoice" bills (InvoiceService::issuableFor()). */
    issuableInvoice: { label: string; amount: string } | null;
    /** Sprint 12 #18 / D6 — the lead's Arsitek ↔ Estimator thread (null without a design / access). */
    discussion: DesignDiscussionThread | null;
    /** Sprint 19 — the lead page is one of this viewer's menus. */
    canOpenLead: boolean;
    /** Sprint 19 Sub 03 — a RAB Jasa Survey's survey (CEO / Marketing only). */
    surveyPanel: QuotationSurveyPanel | null;
}

const REVISION_REASON_TEXT: Record<QuotationRevisionReason, string> = {
    CEO_REJECTED: 'dikembalikan CEO',
    PM_REJECTED: 'dikembalikan PM / Asisten PM',
    CLIENT_REJECTED: 'ditolak klien',
};

/** Statuses a RAB can still be cancelled from (QuotationStatus::isOpen()). */
const CLOSED_STATUSES: QuotationStatus[] = ['CLIENT_APPROVED', 'CANCELLED', 'APPROVED', 'REJECTED'];

function approverLabel(approval: QuotationApproval): string {
    const name = approval.approver?.name ?? '—';

    if (approval.approver_role === 'CLIENT') {
        return `Klien (dicatat oleh ${name})`;
    }

    return `${name} (${approval.approver_role === 'PM' ? 'review PM' : 'CEO'})`;
}

/**
 * RAB builder (.claude/plan/sprint-02.md Week 4; Sprint 12 #11 — bagian
 * pekerjaan, dimensi, diskon, pembulatan; #12 — skema DP/termin) and its
 * flow (Sprint 23 §3C): Marketing's request waits for "Mulai Susun"; the
 * Estimator saves a draft and sends it to the PM (K5b); PM / Asisten PM
 * mark ✔/✘ inside the RAB table (K6, unchanged ✔ carried — K7) and their
 * approval goes straight to Marketing (K8/K10); the CEO only adds notes
 * (K9); Marketing sends the link; the client approves or says "Tidak
 * Setuju" (K11), which Marketing completes and forwards to the Estimator.
 * Sprint 23 K4b: every page action sits in the header, with one line
 * saying whose turn it is; the cards below only inform — except the RAB's
 * own Simpan / Kirim ke PM and the review decision, which stay with it.
 */
export default function QuotationShow({
    quotation,
    canManage,
    reviewStage,
    carriedMarks,
    itemReviews,
    notes,
    canAddNote,
    canClientDecide,
    validityDays,
    units,
    maxPaymentTerms,
    defaultClientNotes,
    shareUrl,
    invoices,
    canSubmitProof,
    canIssueInvoice,
    issuableInvoice,
    discussion,
    canOpenLead,
    surveyPanel,
}: QuotationShowProps) {
    const [clientRejectOpen, setClientRejectOpen] = useState(false);
    const [cancelOpen, setCancelOpen] = useState(false);
    const [issueOpen, setIssueOpen] = useState(false);
    const [noteOpen, setNoteOpen] = useState(false);
    const [forwardOpen, setForwardOpen] = useState(false);
    const [surveyOpen, setSurveyOpen] = useState(false);
    const [proofOpen, setProofOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    // Sprint 17 Sub 03 — "Perlu Tindakan → RAB disetujui klien" links here with ?action=invoice;
    // Sprint 19 — the "Survey Lunas" notification with ?survey=new.
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);

        if (canIssueInvoice && params.get('action') === 'invoice') {
            setIssueOpen(true);
        }
        if (surveyPanel?.canSchedule && params.get('survey') === 'new') {
            setSurveyOpen(true);
        }
    }, [quotation.id, canIssueInvoice, surveyPanel?.canSchedule]);

    const status = quotation.status;
    const isRequested = status === 'DIMINTA';
    const isDraft = status === 'DRAFT';
    const isSubmitted = status === 'SUBMITTED';
    const editable = canManage && isDraft;
    const revisions = quotation.revisions ?? [];
    // Sprint 12 #29 — an addendum is a PROYEK quotation on top of a project's RAB Fix.
    // Sprint 14 Sub 02 — a custom name wins (Quotation::title()).
    const typeLabel = quotationTitle(quotation);
    const canCancel = canClientDecide && !CLOSED_STATUSES.includes(status);
    // The version the Estimator is revising right now, and the items the reviewer marked ✘ on it.
    const lastRevision = isDraft ? revisions.find((revision) => revision.version === quotation.version - 1) : undefined;
    const findings = lastRevision
        ? itemReviews.filter((review) => review.version === lastRevision.version && review.verdict === 'SALAH')
        : [];
    // Sprint 23 K11 — the client's reason (and Marketing's addition) of the version being revised / declined.
    const previousNotes = lastRevision ? notes.filter((note) => note.version === lastRevision.version && note.kind !== 'CEO') : [];
    const clientDecline = status === 'CLIENT_REJECTED' ? notes.filter((note) => note.version === quotation.version && note.kind === 'CLIENT').at(-1) : undefined;
    const validUntil = quotation.valid_until ? formatDate(quotation.valid_until) : null;
    // The invoice still waiting for the client's transfer — its "Tandai Klien Sudah Bayar" goes in the header.
    const unpaidInvoice = status === 'CLIENT_APPROVED' ? invoices.find((invoice) => invoice.status === 'DITERBITKAN') : undefined;
    // Not billed yet, or billed but not verified by Finance.
    const paymentPending = status === 'CLIENT_APPROVED' && (invoices.length === 0 || invoices.some((invoice) => invoice.status !== 'TERVERIFIKASI'));

    function post(routeName: string) {
        router.post(
            route(routeName, { quotation: quotation.id }),
            {},
            { preserveScroll: true, onStart: () => setProcessing(true), onFinish: () => setProcessing(false) },
        );
    }

    // ── K4b: the header's actions and "whose turn" line ─────────────────
    const primary: ReactNode[] = [];
    let step: ReactNode = null;

    if (isRequested) {
        step = canManage ? 'Marketing meminta RAB ini — mulai susun.' : 'Menunggu Estimator mulai menyusun RAB.';
        if (canManage) {
            primary.push(
                <Button key="start" onClick={() => post('quotations.start')} disabled={processing}>
                    Mulai Susun RAB
                </Button>,
            );
        }
    } else if (isDraft) {
        step = canManage ? 'Lengkapi RAB, simpan sebagai draft, lalu kirim ke PM (tombol di bawah tabel).' : 'Estimator sedang menyusun RAB.';
    } else if (isSubmitted) {
        step = reviewStage
            ? 'Tugas Anda: tandai ✔/✘ item di tabel Rincian RAB, lalu Setujui atau Kembalikan ke Estimator.'
            : 'Menunggu review item oleh PM / Asisten PM.';
    } else if (status === 'READY_TO_SEND') {
        step = `Disetujui PM — Marketing mengirim link penawaran ke klien (berlaku ${validityDays} hari sejak dikirim).`;
        if (canClientDecide) {
            primary.push(
                <Button key="send" onClick={() => post('quotations.sendToClient')} disabled={processing}>
                    <Send className="size-4" />
                    Kirim ke Klien
                </Button>,
            );
        }
    } else if (status === 'SENT_TO_CLIENT') {
        step = `Menunggu keputusan klien lewat link${validUntil ? ` — berlaku sampai ${validUntil}` : ''}.`;
        if (shareUrl) {
            primary.push(
                <ShareLinkActions
                    key="share"
                    url={shareUrl}
                    clientName={quotation.lead.client_name}
                    phone={quotation.lead.phone ?? null}
                    typeLabel={typeLabel}
                    validUntil={validUntil}
                />,
            );
        }
    } else if (status === 'CLIENT_REJECTED') {
        step = canClientDecide
            ? 'Klien tidak setuju — tambahkan catatan yang kurang lalu teruskan ke Estimator, atau batalkan bila klien mundur.'
            : 'Klien tidak setuju — menunggu Marketing meneruskannya ke Estimator.';
        if (canClientDecide) {
            primary.push(
                <Button key="forward" onClick={() => setForwardOpen(true)}>
                    <Forward className="size-4" />
                    Teruskan ke Estimator
                </Button>,
            );
        }
    } else if (status === 'CLIENT_APPROVED') {
        if (canIssueInvoice && issuableInvoice) {
            step = 'Klien sudah setuju — terbitkan invoice-nya.';
            primary.push(
                <Button key="invoice" onClick={() => setIssueOpen(true)}>
                    <ReceiptText className="size-4" />
                    {quotation.type === 'PROYEK' ? 'Terbitkan Invoice DP' : 'Terbitkan Invoice'}
                </Button>,
            );
        } else if (unpaidInvoice) {
            step = unpaidInvoice.reject_reason
                ? `Finance mengembalikan konfirmasi pembayaran ${unpaidInvoice.number}: “${unpaidInvoice.reject_reason}” — kirim ulang bukti bayarnya.`
                : `Invoice ${unpaidInvoice.number} sudah terbit — tandai klien sudah bayar begitu transfernya masuk.`;
            if (canSubmitProof) {
                primary.push(
                    <Button key="proof" onClick={() => setProofOpen(true)}>
                        <Banknote className="size-4" />
                        Tandai Klien Sudah Bayar
                    </Button>,
                );
            }
        } else if (invoices.some((invoice) => invoice.status === 'MENUNGGU_VERIFIKASI')) {
            step = 'Bukti bayar sudah dikirim — menunggu Finance memverifikasi pembayarannya.';
        } else if (invoices.length > 0) {
            step =
                quotation.type === 'PROYEK'
                    ? 'Pembayaran DP sudah diverifikasi Finance — proyek dibuka oleh PM yang menyetujui RAB ini.'
                    : quotation.type === 'DESAIN'
                      ? 'Pembayaran sudah diverifikasi Finance — Kepala Desain menugaskan Arsitek.'
                      : 'Pembayaran sudah diverifikasi Finance.';
        }
        if (surveyPanel?.canSchedule) {
            step = 'Pembayaran survey sudah diverifikasi Finance — tentukan jadwal survey.';
            primary.push(
                <Button key="survey" onClick={() => setSurveyOpen(true)}>
                    <CalendarClock className="size-4" />
                    Jadwalkan Survey
                </Button>,
            );
        }
    }

    if (surveyPanel?.canReschedule) {
        primary.push(
            <Button key="reschedule" variant="outline" onClick={() => setSurveyOpen(true)}>
                <CalendarClock className="size-4" />
                Atur Ulang Jadwal
            </Button>,
        );
    }

    if (canAddNote) {
        primary.push(
            <Button key="note" variant="outline" onClick={() => setNoteOpen(true)}>
                <MessageSquarePlus className="size-4" />
                Tambah Catatan
            </Button>,
        );
    }

    /** "Disetujui Klien" — the client's approval and the invoices billed from it. */
    function clientApprovedCard(className: string) {
        return (
            <SectionCard title="Disetujui Klien" icon={Handshake} className={className}>
                <p className="text-sm text-daiku-muted">
                    {quotation.client_approved_at ? (
                        <>
                            Klien menyetujui versi {quotation.version} lewat link penawaran pada{' '}
                            <span className="font-medium text-foreground">{formatDateTime(quotation.client_approved_at)}</span>
                            {quotation.client_approved_ip && ` (IP ${quotation.client_approved_ip})`}.
                        </>
                    ) : (
                        'Disetujui klien (dikonfirmasi Marketing sebelum link persetujuan tersedia).'
                    )}{' '}
                    {quotation.type !== 'PROYEK' &&
                        (invoices.length > 0 ? 'Pembayarannya ditagih lewat invoice di bawah.' : 'Invoice-nya belum diterbitkan.')}
                    {/* Sprint 17 Sub 06 (K2) — the DP is billed while the project is being opened. */}
                    {quotation.type === 'PROYEK' &&
                        invoices.length > 0 &&
                        ' Invoice DP sudah terbit — saat proyek dibuka, invoice ini otomatis menjadi tagihan termin DP.'}
                    {quotation.type === 'PROYEK' && ' Proyeknya dibuka oleh PM yang menyetujui RAB ini.'}
                </p>
                {invoices.length > 0 && (
                    <div className="mt-4 flex flex-col gap-2 border-t border-border pt-4">
                        {invoices.map((invoice) => (
                            <div key={invoice.id} className="flex flex-wrap items-center gap-2 text-sm">
                                <span className="font-medium text-daiku-dark">{invoice.number}</span>
                                <span className="text-daiku-muted">
                                    {INVOICE_TYPE_LABEL[invoice.type]} · {formatRupiah(invoice.amount)} · jatuh tempo {formatDate(invoice.due_date)}
                                </span>
                                <InvoiceStatusChip invoice={invoice} />
                                <span className="flex flex-wrap items-center gap-2 sm:ml-auto">
                                    <InvoicePdfButton invoiceId={invoice.id} />
                                    {/* The open invoice's "Tandai Klien Sudah Bayar" sits in the page header. */}
                                    {invoice.id !== unpaidInvoice?.id && (
                                        <InvoiceProofButton invoice={{ ...invoice, lead: quotation.lead }} canSubmit={canSubmitProof} />
                                    )}
                                </span>
                            </div>
                        ))}
                    </div>
                )}
            </SectionCard>
        );
    }

    return (
        <AppLayout breadcrumbs={[{ label: quotation.lead.client_name }, { label: `${typeLabel} · Versi ${quotation.version}` }]}>
            <Head title={`${typeLabel} — ${quotation.lead.client_name}`} />

            <PageHeader
                title={`${typeLabel}: ${quotation.lead.client_name}`}
                icon={FileText}
                description={
                    <span className="inline-flex flex-wrap items-center gap-2">
                        <StatusChip domain="quotation" status={status} />
                        <span>
                            {quotation.valid_until
                                ? `Versi ${quotation.version} · berlaku sampai ${formatDate(quotation.valid_until)}.`
                                : quotation.requester
                                  ? `Versi ${quotation.version} · diminta oleh ${quotation.requester.name}.`
                                  : `Versi ${quotation.version} · dibuat dari desain yang sudah di-ACC klien.`}
                        </span>
                    </span>
                }
                step={step}
                actions={
                    <>
                        {primary}
                        <MoreActions>
                            <DropdownMenuItem asChild>
                                <a href={route('quotations.pdf', { quotation: quotation.id })} target="_blank" rel="noopener noreferrer">
                                    <FileDown className="size-4" />
                                    Unduh PDF
                                </a>
                            </DropdownMenuItem>
                            <DropdownMenuItem asChild>
                                <a href={route('quotations.excel', { quotation: quotation.id })}>
                                    <FileSpreadsheet className="size-4" />
                                    Unduh Excel
                                </a>
                            </DropdownMenuItem>
                            {canOpenLead && (
                                <DropdownMenuItem asChild>
                                    <Link href={route('crm.leads.show', { lead: quotation.lead.id })}>
                                        <UserRound className="size-4" />
                                        Buka Halaman Lead
                                    </Link>
                                </DropdownMenuItem>
                            )}
                            {status === 'SENT_TO_CLIENT' && canClientDecide && (
                                <DropdownMenuItem onSelect={() => setClientRejectOpen(true)}>
                                    <Handshake className="size-4" />
                                    Klien Menolak (WA / telepon)
                                </DropdownMenuItem>
                            )}
                            {canCancel && (
                                <>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem variant="destructive" onSelect={() => setCancelOpen(true)}>
                                        <Ban className="size-4" />
                                        Batalkan RAB
                                    </DropdownMenuItem>
                                </>
                            )}
                        </MoreActions>
                    </>
                }
            />

            <QuotationExpiryNotice quotation={quotation} className="mb-6" />

            {/* Sprint 23 — at the top while the payment is still open, so Marketing sees it first; back below once Finance verified it. */}
            {paymentPending && clientApprovedCard('mb-6')}

            {status === 'CANCELLED' && (
                <Notice tone="error" className="mb-6">
                    RAB ini dibatalkan — lihat alasannya di Log Audit. Minta RAB baru dari halaman lead bila perlu.
                </Notice>
            )}

            {clientDecline && (
                <Notice tone="warning" className="mb-6">
                    Klien tidak menyetujui versi {quotation.version} lewat link penawaran ({formatDateTime(clientDecline.created_at)}): “{clientDecline.note}”
                </Notice>
            )}

            {quotation.parent_quotation_id && quotation.project && (
                <Notice tone="info" className="mb-6">
                    RAB Tambahan untuk proyek{' '}
                    <Link href={route('projects.show', { project: quotation.project.id })} className="font-medium underline decoration-daiku-yellow underline-offset-2">
                        {quotation.project.name}
                    </Link>
                    {quotation.parent && ` (menambah RAB Fix versi ${quotation.parent.version})`}. Setelah disetujui klien, nilai, termin, dan
                    itemnya ditambahkan ke proyek — tidak membuka proyek baru.
                </Notice>
            )}

            {quotation.request_note && (
                <Notice tone={isRequested ? 'warning' : 'info'} className="mb-6">
                    <span className="whitespace-pre-line">
                        {quotation.requester?.name ?? 'Marketing'} meminta {typeLabel}: “{quotation.request_note}”
                    </span>
                </Notice>
            )}

            {quotation.references && quotation.references.length > 0 && (
                <RabReferenceList references={quotation.references} requester={quotation.requester?.name} />
            )}

            {lastRevision && (
                <Notice tone={findings.length > 0 || previousNotes.length > 0 ? 'warning' : 'info'} className="mb-6">
                    <p>
                        Versi {quotation.version} adalah revisi: versi {lastRevision.version} {REVISION_REASON_TEXT[lastRevision.reason]}
                        {lastRevision.note && previousNotes.length === 0 ? ` — “${lastRevision.note}”` : ''}. Perbarui RAB lalu kirim ulang ke PM untuk
                        review.
                    </p>
                    {(findings.length > 0 || previousNotes.length > 0) && (
                        <ul className="mt-2 list-disc space-y-0.5 pl-5">
                            {previousNotes.map((note) => (
                                <li key={`note-${note.id}`}>
                                    <span className="font-medium">{note.kind === 'CLIENT' ? 'Klien' : `Marketing (${note.author?.name ?? '—'})`}:</span> {note.note}
                                </li>
                            ))}
                            {findings.map((finding) => (
                                <li key={finding.id}>
                                    <span className="font-medium">
                                        {finding.section_name ? `${finding.section_name} · ` : ''}
                                        {finding.item_description}
                                    </span>{' '}
                                    — {finding.note} <span className="text-xs">({finding.stage === 'PM' ? 'review PM' : 'CEO'})</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Notice>
            )}

            {!isRequested && (
                <>
                    <SectionCard
                        title="Rincian RAB"
                        icon={Calculator}
                        description={
                            isSubmitted
                                ? 'Bagian pekerjaan dan item — kolom Review: ✔ cocok / ✘ kurang cocok (✘ wajib diberi catatan).'
                                : 'Bagian pekerjaan, item, dimensi, volume, dan harga — lalu diskon dan pembulatan.'
                        }
                    >
                        <RabBuilder
                            // A new version / status re-reads the saved lines (and the review's item ids).
                            key={`${quotation.version}-${status}`}
                            quotation={quotation}
                            units={units}
                            editable={editable}
                            onSubmitForReview={() => post('quotations.submit')}
                            findings={findings}
                            review={isSubmitted ? { canDecide: reviewStage === 'PM', carried: carriedMarks } : null}
                        />
                        {!isDraft && !isSubmitted && (
                            <p className="mt-4 text-sm text-daiku-muted">RAB sudah melewati review — isinya tidak bisa diubah lagi.</p>
                        )}
                    </SectionCard>

                    <SectionCard
                        title="Skema Pembayaran"
                        icon={Wallet}
                        description={`DP dan termin — maksimal ${maxPaymentTerms} baris, total 100% dari grand total.`}
                        className="mt-6"
                    >
                        <PaymentTermsEditor quotation={quotation} editable={editable} maxTerms={maxPaymentTerms} />
                    </SectionCard>

                    <ClientNotesCard quotationId={quotation.id} notes={quotation.client_notes ?? null} defaultNotes={defaultClientNotes} editable={editable} />
                </>
            )}

            <QuotationNotesCard notes={notes} currentVersion={quotation.version} />

            {status === 'SENT_TO_CLIENT' && (
                <SectionCard title="Keputusan Klien" icon={Handshake} description={`Penawaran berlaku ${validityDays} hari sejak dikirim ke klien.`} className="mt-6">
                    <div className="space-y-3">
                        <p className="text-sm text-daiku-muted">
                            Dikirim ke klien — berlaku sampai <span className="font-medium text-foreground">{formatDate(quotation.valid_until)}</span>. Klien
                            memilih sendiri lewat link: Setuju, atau Tidak Setuju dengan catatan (masuk ke antrean Marketing). Permintaan revisi lewat
                            WhatsApp / telepon dicatat dengan "Klien Menolak" di menu Lainnya.
                        </p>
                        {shareUrl && <ShareLinkPanel url={shareUrl} phone={quotation.lead.phone ?? null} />}
                    </div>
                </SectionCard>
            )}

            {status === 'CLIENT_APPROVED' && !paymentPending && clientApprovedCard('mt-6')}

            {/* Sprint 19 Sub 03 — the client and, for a paid RAB Jasa Survey, its survey (actions in the header). */}
            <QuotationNextStepCard lead={quotation.lead} canOpenLead={canOpenLead} surveyPanel={surveyPanel} />

            {quotation.approvals && quotation.approvals.length > 0 && (
                <SectionCard title="Riwayat Approval" icon={History} className="mt-6" contentClassName="space-y-3">
                    {quotation.approvals.map((approval) => (
                        <div key={approval.id} className="flex items-start justify-between gap-4 border-b border-border pb-3 last:border-0 last:pb-0">
                            <div>
                                <p className="text-sm font-medium text-daiku-dark">{approverLabel(approval)}</p>
                                {approval.note && <p className="text-sm text-daiku-muted">{approval.note}</p>}
                                <p className="text-xs text-daiku-muted">
                                    Versi {approval.version} · {formatDateTime(approval.created_at)}
                                </p>
                            </div>
                            <StatusChip
                                status={approval.status}
                                label={approval.status === 'REJECTED' ? (approval.approver_role === 'CLIENT' ? 'Ditolak' : 'Dikembalikan') : 'Disetujui'}
                            />
                        </div>
                    ))}
                </SectionCard>
            )}

            {revisions.length > 0 && (
                <SectionCard
                    title="Riwayat Revisi"
                    icon={FileClock}
                    description="Versi yang dikembalikan atau ditolak beserta RAB-nya saat itu, dibandingkan dengan versi saat ini."
                    className="mt-6"
                    flush
                >
                    <QuotationRevisionHistory revisions={revisions} currentTotal={quotation.total_amount} currentVersion={quotation.version} />
                </SectionCard>
            )}

            {discussion && <DesignDiscussionPanel thread={discussion} quotationId={quotation.id} className="mt-6" />}

            <QuotationDecisionDialog open={clientRejectOpen} onOpenChange={setClientRejectOpen} quotation={quotation} role="CLIENT" decision="reject" />
            {canIssueInvoice && issuableInvoice && (
                <IssueInvoiceDialog
                    open={issueOpen}
                    onOpenChange={setIssueOpen}
                    action={route('quotations.invoices.store', { quotation: quotation.id })}
                    label={issuableInvoice.label}
                    amount={issuableInvoice.amount}
                />
            )}
            {canSubmitProof && unpaidInvoice && proofOpen && (
                <InvoiceProofDialog open onOpenChange={setProofOpen} invoice={{ ...unpaidInvoice, lead: quotation.lead }} />
            )}
            {surveyPanel && (surveyPanel.canSchedule || surveyPanel.canReschedule) && (
                <SurveyScheduleDialog open={surveyOpen} onOpenChange={setSurveyOpen} lead={quotation.lead} surveyPanel={surveyPanel} />
            )}
            {canAddNote && (
                <QuotationNoteDialog
                    open={noteOpen}
                    onOpenChange={setNoteOpen}
                    action={route('quotations.notes.store', { quotation: quotation.id })}
                    title="Tambah Catatan CEO"
                    description="Catatan ini hanya informasi untuk Estimator, PM, dan Marketing — RAB tidak dikembalikan dan alurnya tidak berubah."
                    label="Catatan"
                    placeholder="mis. Pastikan klien tahu jadwal produksi ±6 minggu."
                    submitLabel="Simpan Catatan"
                />
            )}
            {status === 'CLIENT_REJECTED' && canClientDecide && (
                <QuotationNoteDialog
                    open={forwardOpen}
                    onOpenChange={setForwardOpen}
                    action={route('quotations.forwardToEstimator', { quotation: quotation.id })}
                    title="Teruskan ke Estimator"
                    description={`Versi ${quotation.version} disimpan di Riwayat Revisi, lalu RAB dibuka lagi sebagai versi ${quotation.version + 1} untuk Estimator — catatan klien dan catatan Anda tampil sebagai temuan.`}
                    label="Yang perlu diperbaiki"
                    placeholder="mis. Klien mau finishing HPL, budget maksimal Rp 20 jt, lemari dibuat 3 pintu."
                    submitLabel="Teruskan ke Estimator"
                />
            )}
            <CancelQuotationDialog open={cancelOpen} onOpenChange={setCancelOpen} quotation={quotation} label={typeLabel} />
        </AppLayout>
    );
}
