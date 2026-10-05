import { formatDate, formatDateTime, formatRupiah } from '@/lib/format';
import { INVOICE_TYPE_LABEL, IssueInvoiceDialog } from '@/Components/modules/finance/InvoiceDialogs';
import { Notice } from '@/Components/shared/Notice';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import { SectionCard } from '@/Components/shared/SectionCard';
import { Button } from '@/Components/ui/button';
import { CancelQuotationDialog } from '@/Components/modules/quotation/CancelQuotationDialog';
import { QUOTATION_TYPE_LABEL } from '@/Components/modules/quotation/labels';
import { PaymentTermsEditor } from '@/Components/modules/quotation/PaymentTermsEditor';
import { RabBuilder } from '@/Components/modules/quotation/RabBuilder';
import { ShareLinkPanel } from '@/Components/modules/quotation/ShareLinkPanel';
import { QuotationDecisionDialog } from '@/Components/modules/quotation/QuotationDecisionDialog';
import { QuotationExpiryNotice } from '@/Components/modules/quotation/QuotationExpiryNotice';
import { QuotationReviewPanel } from '@/Components/modules/quotation/QuotationReviewPanel';
import { QuotationRevisionHistory } from '@/Components/modules/quotation/QuotationRevisionHistory';
import AppLayout from '@/Layouts/AppLayout';
import type {
    Quotation,
    Invoice,
    QuotationApproval,
    QuotationItemReview,
    QuotationRevisionReason,
    QuotationStatus,
    UnitOption,
} from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    BadgeCheck,
    Ban,
    Calculator,
    FileClock,
    FileDown,
    FileSpreadsheet,
    FileText,
    Handshake,
    History,
    ReceiptText,
    Send,
    Wallet,
} from 'lucide-react';
import { type ReactNode, useState } from 'react';

interface QuotationShowProps {
    quotation: Quotation & { lead: { id: number; client_name: string; contact?: string | null } };
    /** ESTIMATOR — drafts, submits and sends the final RAB to Marketing. */
    canManage: boolean;
    /** Whose item review it is for this viewer right now (QuotationService::reviewStage()), if theirs. */
    reviewStage: 'PM' | 'CEO' | null;
    /** ✔/✘ marks of this version and the previous one. */
    itemReviews: QuotationItemReview[];
    /** CEO/Marketing — send to the client, record the client's rejection, cancel; confirm the deal on the lead. */
    canClientDecide: boolean;
    /** QuotationService::VALIDITY_DAYS */
    validityDays: number;
    /** Active Master Satuan units for the RAB builder (empty once the quotation left DRAFT). */
    units: UnitOption[];
    /** QuotationService::MAX_PAYMENT_TERMS */
    maxPaymentTerms: number;
    /** Sprint 12 #13 — this version's public link (CEO / Marketing only), once sent. */
    shareUrl: string | null;
    /** Sprint 12 #20 — invoices billed from this RAB. */
    invoices: Pick<Invoice, 'id' | 'number' | 'type' | 'amount' | 'due_date' | 'status'>[];
    /** Marketing, on an approved Jasa Survey / Jasa Desain RAB without an invoice yet. */
    canIssueInvoice: boolean;
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
 * Sprint 12 flow (#7–#10): Marketing's request waits for "Mulai Susun";
 * the Estimator submits to PM / Asisten PM, who mark every item ✔/✘
 * (QuotationReviewPanel); a RAB Proyek then goes to the CEO; the
 * Estimator hands the final RAB to Marketing, who sends it to the client.
 * Once SENT_TO_CLIENT — the client's side (Sprint 9): validity period,
 * "Klien Menolak" (back to DRAFT as a new version), revision history.
 */
export default function QuotationShow({
    quotation,
    canManage,
    reviewStage,
    itemReviews,
    canClientDecide,
    validityDays,
    units,
    maxPaymentTerms,
    shareUrl,
    invoices,
    canIssueInvoice,
}: QuotationShowProps) {
    const [clientRejectOpen, setClientRejectOpen] = useState(false);
    const [cancelOpen, setCancelOpen] = useState(false);
    const [issueOpen, setIssueOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const status = quotation.status;
    const isRequested = status === 'DIMINTA';
    const isDraft = status === 'DRAFT';
    const editable = canManage && isDraft;
    const revisions = quotation.revisions ?? [];
    const typeLabel = QUOTATION_TYPE_LABEL[quotation.type];
    const canCancel = canClientDecide && !CLOSED_STATUSES.includes(status);
    // The version the Estimator is revising right now, and the items the reviewer marked ✘ on it.
    const lastRevision = isDraft ? revisions.find((revision) => revision.version === quotation.version - 1) : undefined;
    const findings = lastRevision
        ? itemReviews.filter((review) => review.version === lastRevision.version && review.verdict === 'SALAH')
        : [];
    const currentReviews = itemReviews.filter((review) => review.version === quotation.version);

    function post(routeName: string) {
        router.post(
            route(routeName, { quotation: quotation.id }),
            {},
            { preserveScroll: true, onStart: () => setProcessing(true), onFinish: () => setProcessing(false) },
        );
    }

    return (
        <AppLayout
            breadcrumbs={[{ label: quotation.lead.client_name }, { label: `${typeLabel} · Versi ${quotation.version}` }]}
        >
            <Head title={`${typeLabel} — ${quotation.lead.client_name}`} />

            <PageHeader
                title={`${typeLabel}: ${quotation.lead.client_name}`}
                icon={FileText}
                description={
                    quotation.valid_until
                        ? `Versi ${quotation.version} · berlaku sampai ${formatDate(quotation.valid_until)}.`
                        : quotation.requester
                          ? `Versi ${quotation.version} · diminta oleh ${quotation.requester.name}.`
                          : `Versi ${quotation.version} · dibuat dari desain yang sudah di-ACC klien.`
                }
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <StatusChip status={status} />
                        <Button variant="outline" size="sm" asChild>
                            <a href={route('quotations.pdf', { quotation: quotation.id })} target="_blank" rel="noopener noreferrer">
                                <FileDown className="size-4" />
                                Export PDF
                            </a>
                        </Button>
                        <Button variant="outline" size="sm" asChild>
                            <a href={route('quotations.excel', { quotation: quotation.id })}>
                                <FileSpreadsheet className="size-4" />
                                Export Excel
                            </a>
                        </Button>
                        {canCancel && (
                            <Button variant="outline" size="sm" onClick={() => setCancelOpen(true)}>
                                <Ban className="size-4" />
                                Batalkan RAB
                            </Button>
                        )}
                    </div>
                }
            />

            <QuotationExpiryNotice quotation={quotation} className="mb-6" />

            {status === 'CANCELLED' && (
                <Notice tone="error" className="mb-6">
                    RAB ini dibatalkan — lihat alasannya di Log Audit. Minta RAB baru dari halaman lead bila perlu.
                </Notice>
            )}

            {quotation.request_note && (
                <Notice tone={isRequested ? 'warning' : 'info'} className="mb-6">
                    <span className="flex flex-col gap-3 sm:flex-row sm:items-center">
                        <span className="flex-1">
                            {quotation.requester?.name ?? 'Marketing'} meminta {typeLabel}: “{quotation.request_note}”
                            {isRequested && !canManage && ' — menunggu Estimator mulai menyusun.'}
                        </span>
                        {isRequested && canManage && (
                            <Button size="sm" className="shrink-0" onClick={() => post('quotations.start')} disabled={processing}>
                                Mulai Susun
                            </Button>
                        )}
                    </span>
                </Notice>
            )}

            {lastRevision && (
                <Notice tone={findings.length > 0 ? 'warning' : 'info'} className="mb-6">
                    <p>
                        Versi {quotation.version} adalah revisi: versi {lastRevision.version} {REVISION_REASON_TEXT[lastRevision.reason]}
                        {lastRevision.note ? ` — “${lastRevision.note}”` : ''}. Perbarui RAB lalu kirim ulang ke PM untuk review.
                    </p>
                    {findings.length > 0 && (
                        <ul className="mt-2 list-disc space-y-0.5 pl-5">
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
                        description="Bagian pekerjaan, item, dimensi, volume, dan harga — lalu diskon dan pembulatan."
                    >
                        <RabBuilder
                            quotation={quotation}
                            units={units}
                            editable={editable}
                            onSubmitForReview={() => post('quotations.submit')}
                            findings={findings}
                        />
                        {!isDraft && (
                            <p className="mt-4 text-sm text-daiku-muted">RAB sudah dikirim untuk review — isinya tidak bisa diubah lagi.</p>
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
                </>
            )}

            {(status === 'SUBMITTED' || status === 'WAITING_CEO') && (
                <SectionCard
                    title={status === 'SUBMITTED' ? 'Review PM / Asisten PM' : 'Approval CEO'}
                    icon={BadgeCheck}
                    description={
                        reviewStage
                            ? 'Tandai tiap item ✔ cocok / ✘ kurang cocok. Item ✘ wajib diberi catatan dan membuat RAB kembali ke Estimator.'
                            : undefined
                    }
                    className="mt-6"
                >
                    {reviewStage ? (
                        <QuotationReviewPanel quotation={quotation} stage={reviewStage} reviews={currentReviews} />
                    ) : (
                        <p className="text-sm text-daiku-muted">
                            {status === 'SUBMITTED' ? 'Menunggu review item oleh PM / Asisten PM.' : 'Sudah di-ACC PM — menunggu keputusan CEO.'}
                        </p>
                    )}
                </SectionCard>
            )}

            {status === 'APPROVED_INTERNAL' && (
                <NextStep
                    text="Disetujui internal. Estimator mengirim RAB final ke Marketing untuk diteruskan ke klien."
                    action={
                        canManage && (
                            <Button size="sm" onClick={() => post('quotations.sendToMarketing')} disabled={processing}>
                                <Send className="size-4" />
                                Kirim RAB Final ke Marketing
                            </Button>
                        )
                    }
                />
            )}

            {status === 'READY_TO_SEND' && (
                <NextStep
                    text={`RAB final sudah di Marketing. "Kirim ke Client" membuat link penawaran untuk klien dan memulai masa berlaku ${validityDays} hari.`}
                    action={
                        canClientDecide && (
                            <Button size="sm" onClick={() => post('quotations.sendToClient')} disabled={processing}>
                                <Send className="size-4" />
                                Kirim ke Client
                            </Button>
                        )
                    }
                />
            )}

            {status === 'SENT_TO_CLIENT' && (
                <SectionCard
                    title="Keputusan Klien"
                    icon={Handshake}
                    description={`Penawaran berlaku ${validityDays} hari sejak dikirim ke klien.`}
                    className="mt-6"
                >
                    <div className="space-y-4">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                            <p className="flex-1 text-sm text-daiku-muted">
                                Dikirim ke klien — berlaku sampai{' '}
                                <span className="font-medium text-foreground">{formatDate(quotation.valid_until)}</span>. Klien
                                menyetujui sendiri lewat link di bawah; permintaan revisi lewat WhatsApp dicatat dengan "Klien
                                Menolak".
                            </p>
                            {canClientDecide && (
                                <Button variant="outline" size="sm" className="shrink-0" onClick={() => setClientRejectOpen(true)}>
                                    Klien Menolak
                                </Button>
                            )}
                        </div>
                        {shareUrl && (
                            <ShareLinkPanel
                                url={shareUrl}
                                clientName={quotation.lead.client_name}
                                contact={quotation.lead.contact ?? null}
                                typeLabel={typeLabel}
                                validUntil={quotation.valid_until ? formatDate(quotation.valid_until) : null}
                            />
                        )}
                    </div>
                </SectionCard>
            )}

            {status === 'CLIENT_APPROVED' && (
                <SectionCard title="Disetujui Klien" icon={Handshake} className="mt-6">
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
                            (invoices.length > 0 ? 'Pembayarannya ditagih lewat invoice di bawah.' : 'Terbitkan invoice-nya untuk ditagihkan ke klien.')}
                        {quotation.type === 'PROYEK' && (
                            <Link
                                href={route('crm.leads.show', { lead: quotation.lead.id })}
                                className="inline-flex items-center gap-1 font-medium text-foreground underline decoration-daiku-yellow underline-offset-4"
                            >
                                Buka proyek dari halaman lead
                                <ArrowRight className="size-3" aria-hidden />
                            </Link>
                        )}
                    </p>
                    {(invoices.length > 0 || canIssueInvoice) && (
                        <div className="mt-4 flex flex-col gap-2 border-t border-border pt-4">
                            {invoices.map((invoice) => (
                                <div key={invoice.id} className="flex flex-wrap items-center gap-2 text-sm">
                                    <span className="font-medium text-daiku-dark">{invoice.number}</span>
                                    <span className="text-daiku-muted">
                                        {INVOICE_TYPE_LABEL[invoice.type]} · {formatRupiah(invoice.amount)} · jatuh tempo {formatDate(invoice.due_date)}
                                    </span>
                                    <StatusChip status={invoice.status} />
                                    <a
                                        href={route('finance.invoices.pdf', { invoice: invoice.id })}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex items-center gap-1 text-xs font-medium underline decoration-daiku-yellow underline-offset-4"
                                    >
                                        PDF
                                    </a>
                                </div>
                            ))}
                            {canIssueInvoice && (
                                <Button size="sm" className="w-fit" onClick={() => setIssueOpen(true)}>
                                    <ReceiptText className="size-4" />
                                    Terbitkan Invoice
                                </Button>
                            )}
                        </div>
                    )}
                </SectionCard>
            )}

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
                                label={approval.status === 'REJECTED' && approval.approver_role !== 'CLIENT' ? 'Dikembalikan' : undefined}
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
                    <QuotationRevisionHistory
                        revisions={revisions}
                        currentTotal={quotation.total_amount}
                        currentVersion={quotation.version}
                    />
                </SectionCard>
            )}

            <QuotationDecisionDialog
                open={clientRejectOpen}
                onOpenChange={setClientRejectOpen}
                quotation={quotation}
                role="CLIENT"
                decision="reject"
            />
            {canIssueInvoice && (
                <IssueInvoiceDialog
                    open={issueOpen}
                    onOpenChange={setIssueOpen}
                    action={route('quotations.invoices.store', { quotation: quotation.id })}
                    label={quotation.type === 'SURVEY' ? 'Jasa Survey' : 'Jasa Desain'}
                    amount={quotation.total_amount}
                />
            )}
            <CancelQuotationDialog open={cancelOpen} onOpenChange={setCancelOpen} quotation={quotation} label={typeLabel} />
        </AppLayout>
    );
}

function NextStep({ text, action }: { text: string; action?: ReactNode }) {
    return (
        <SectionCard title="Langkah Berikutnya" icon={Send} className="mt-6">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                <p className="flex-1 text-sm text-daiku-muted">{text}</p>
                {action && <div className="shrink-0">{action}</div>}
            </div>
        </SectionCard>
    );
}
