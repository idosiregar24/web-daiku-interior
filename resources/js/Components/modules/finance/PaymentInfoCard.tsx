import { EmptyState } from '@/Components/shared/EmptyState';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { formatDate, formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { LeadPaymentItem, LeadPayments } from '@/types';
import { FileDown, Wallet } from 'lucide-react';
import type { ReactNode } from 'react';

/**
 * Sprint 23 K12c — "Informasi Pembayaran": every payment of a client
 * (Jasa Survey, Jasa Desain, DP, Termin n dari N, Pelunasan, Pekerjaan
 * Tambahan) with whose turn it is — Belum Ditagih · Marketing → Belum
 * Dibayar · Klien → Menunggu Konfirmasi · Finance → Lunas. Data built once
 * in InvoiceService::leadPayments(); the server sends it only to CEO,
 * Marketing and the project's PM. Reusable on Detail Proyek.
 */
export function PaymentInfoCard({ payments, className }: { payments: LeadPayments; className?: string }) {
    const { summary, items } = payments;

    return (
        <SectionCard
            title="Informasi Pembayaran"
            icon={Wallet}
            description="Semua tagihan klien ini — yang sudah lunas, menunggu konfirmasi Finance, dan yang belum dibayar."
            className={className}
            flush
        >
            {items.length === 0 ? (
                <EmptyState title="Belum ada tagihan untuk klien ini." />
            ) : (
                <>
                    <dl className="grid grid-cols-2 gap-x-4 gap-y-3 border-b border-border px-4 py-4 sm:grid-cols-5 sm:px-5">
                        <Summary label="Total tagihan" value={formatRupiah(summary.total)} hint={`${summary.count} pembayaran`} />
                        <Summary label="Sudah lunas" value={formatRupiah(summary.paid)} tone="text-success-ink" />
                        <Summary label="Menunggu konfirmasi Finance" value={formatRupiah(summary.awaiting_confirmation)} tone="text-info-ink" />
                        <Summary
                            label="Belum dibayar"
                            value={formatRupiah(summary.unpaid)}
                            hint={summary.not_billed > 0 ? `termasuk ${formatRupiah(summary.not_billed)} belum ditagih` : undefined}
                        />
                        <Summary
                            label="Terlambat"
                            value={formatRupiah(summary.overdue)}
                            tone={summary.overdue_count > 0 ? 'text-error-ink' : undefined}
                            hint={summary.overdue_count > 0 ? `${summary.overdue_count} tagihan lewat jadwal` : undefined}
                        />
                    </dl>

                    {/* Phone: one card per payment. */}
                    <ul className="divide-y divide-border md:hidden">
                        {items.map((item) => (
                            <li key={item.key} className="space-y-2 px-4 py-3.5">
                                <div className="flex items-start justify-between gap-3">
                                    <PaymentName item={item} />
                                    <p className="shrink-0 text-right font-semibold text-daiku-dark">{formatRupiah(item.amount)}</p>
                                </div>
                                <PaymentState item={item} />
                                <p className="text-xs text-daiku-muted">
                                    {item.invoice_number ?? 'Belum ada invoice'} · <Schedule item={item} />
                                </p>
                                <PaidInfo item={item} />
                                <PdfLink item={item} />
                            </li>
                        ))}
                    </ul>

                    <div className="hidden overflow-x-auto md:block">
                        <table className="w-full text-sm">
                            <thead className={TABLE_HEAD_CLASS}>
                                <tr>
                                    <th className="px-4 py-2.5 text-left font-semibold">Pembayaran</th>
                                    <th className="px-4 py-2.5 text-left font-semibold">No. Invoice</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Nominal</th>
                                    <th className="px-4 py-2.5 text-left font-semibold">Jatuh Tempo / Jadwal</th>
                                    <th className="px-4 py-2.5 text-left font-semibold">Status</th>
                                    <th className="px-4 py-2.5 text-left font-semibold">Lunas</th>
                                    <th className="px-4 py-2.5" />
                                </tr>
                            </thead>
                            <tbody>
                                {items.map((item) => (
                                    <tr key={item.key} className="border-t border-border align-top">
                                        <td className="px-4 py-3">
                                            <PaymentName item={item} />
                                        </td>
                                        <td className="px-4 py-3 whitespace-nowrap text-daiku-muted">{item.invoice_number ?? '—'}</td>
                                        <td className="px-4 py-3 text-right font-medium whitespace-nowrap text-daiku-dark">{formatRupiah(item.amount)}</td>
                                        <td className="px-4 py-3 text-daiku-muted">
                                            <Schedule item={item} />
                                        </td>
                                        <td className="px-4 py-3">
                                            <PaymentState item={item} />
                                        </td>
                                        <td className="px-4 py-3">
                                            <PaidInfo item={item} empty="—" />
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <PdfLink item={item} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </>
            )}
        </SectionCard>
    );
}

function Summary({ label, value, hint, tone }: { label: string; value: string; hint?: string; tone?: string }) {
    return (
        <div className="min-w-0">
            <dt className="text-xs text-daiku-muted">{label}</dt>
            <dd className={cn('text-base font-semibold tabular-nums text-daiku-dark', tone)}>{value}</dd>
            {hint && <dd className="text-xs text-daiku-muted">{hint}</dd>}
        </div>
    );
}

function PaymentName({ item }: { item: LeadPaymentItem }) {
    return (
        <div className="min-w-0">
            <p className="font-medium text-daiku-dark">{item.label}</p>
            {item.context && <p className="truncate text-xs text-daiku-muted">{item.context}</p>}
        </div>
    );
}

/** Status + whose turn (lib/waitingOn.ts), "Terlambat" when the date passed, Finance's last rejection. */
function PaymentState({ item }: { item: LeadPaymentItem }) {
    return (
        <div className="space-y-1">
            <span className="flex flex-wrap items-center gap-1">
                <StatusChip domain={item.domain} status={item.status} />
                {item.overdue && <StatusChip status="OVERDUE" label="Terlambat" />}
            </span>
            {item.reject_reason && <p className="text-xs text-error-ink">Bukti ditolak Finance: {item.reject_reason}</p>}
        </div>
    );
}

function Schedule({ item }: { item: LeadPaymentItem }) {
    if (item.due_date) {
        return <span className={item.overdue ? 'font-medium text-error-ink' : undefined}>{formatDate(item.due_date)}</span>;
    }

    return <>{item.schedule_note ?? '—'}</>;
}

function PaidInfo({ item, empty = null }: { item: LeadPaymentItem; empty?: ReactNode }) {
    if (item.state !== 'LUNAS') {
        return item.paid_amount > 0 ? <p className="text-xs text-daiku-muted">Dibayar sebagian {formatRupiah(item.paid_amount)}</p> : <>{empty}</>;
    }

    return (
        <p className="text-xs text-daiku-muted">
            {item.paid_date ? formatDate(item.paid_date) : 'Lunas'}
            {item.bank_account && ` · ${item.bank_account}`}
        </p>
    );
}

function PdfLink({ item }: { item: LeadPaymentItem }) {
    if (!item.pdf_url) {
        return null;
    }

    return (
        <a
            href={item.pdf_url}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1 text-xs font-medium whitespace-nowrap text-foreground underline decoration-daiku-yellow underline-offset-4"
        >
            <FileDown className="size-3.5" aria-hidden />
            {item.invoice_id ? 'PDF invoice' : 'PDF tagihan'}
        </a>
    );
}
