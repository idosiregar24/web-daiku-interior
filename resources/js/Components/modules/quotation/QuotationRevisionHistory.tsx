import { StatusChip } from '@/Components/shared/StatusChip';
import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { Button } from '@/Components/ui/button';
import { formatDateTime, formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { QuotationRevision, QuotationRevisionReason } from '@/types';
import { ChevronDown } from 'lucide-react';
import { useState } from 'react';

const REASON_LABEL: Record<QuotationRevisionReason, string> = {
    CEO_REJECTED: 'Ditolak CEO',
    PM_REJECTED: 'Ditolak PM',
    CLIENT_REJECTED: 'Ditolak Klien',
};

interface QuotationRevisionHistoryProps {
    /** Closed versions, newest first (Quotation::revisions()). */
    revisions: QuotationRevision[];
    /** The live version's total and number — each closed version shows its difference to it. */
    currentTotal: string;
    currentVersion: number;
    className?: string;
}

/**
 * PRD §4.3 "Versi Revisi" — every version that a CEO, PM or client
 * rejection sent back to DRAFT, with the RAB it had at the time
 * (expandable) and how its total compares with the current version.
 * Meant to sit inside a `flush` SectionCard.
 */
export function QuotationRevisionHistory({ revisions, currentTotal, currentVersion, className }: QuotationRevisionHistoryProps) {
    return (
        <ol className={cn('divide-y divide-border', className)}>
            {revisions.map((revision) => (
                <RevisionRow
                    key={revision.id}
                    revision={revision}
                    currentTotal={currentTotal}
                    currentVersion={currentVersion}
                />
            ))}
        </ol>
    );
}

function RevisionRow({
    revision,
    currentTotal,
    currentVersion,
}: {
    revision: QuotationRevision;
    currentTotal: string;
    currentVersion: number;
}) {
    const [open, setOpen] = useState(false);
    const panelId = `quotation-revision-${revision.id}`;
    const difference = Number(currentTotal) - Number(revision.total_amount);

    return (
        <li className="px-4 py-3.5 sm:px-5">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-4">
                <div className="min-w-0 space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-sm font-semibold text-foreground">Versi {revision.version}</span>
                        <StatusChip status={revision.reason} label={REASON_LABEL[revision.reason]} />
                    </div>
                    {revision.note && <p className="text-sm whitespace-pre-line text-foreground">{revision.note}</p>}
                    <p className="text-xs text-muted-foreground">
                        {revision.reason === 'CLIENT_REJECTED' ? 'Dicatat oleh' : 'Oleh'} {revision.closer?.name ?? '—'} ·{' '}
                        {formatDateTime(revision.created_at)}
                    </p>
                </div>
                <div className="shrink-0 sm:text-right">
                    <p className="text-sm font-semibold text-foreground">{formatRupiah(revision.total_amount)}</p>
                    <p className="text-xs text-muted-foreground">
                        {difference === 0
                            ? `Sama dengan versi ${currentVersion}`
                            : `Versi ${currentVersion} ${difference > 0 ? 'lebih mahal' : 'lebih murah'} ${formatRupiah(Math.abs(difference))}`}
                    </p>
                </div>
            </div>

            <Button
                type="button"
                variant="ghost"
                size="sm"
                className="mt-1.5 -ml-2.5"
                aria-expanded={open}
                aria-controls={panelId}
                onClick={() => setOpen((value) => !value)}
            >
                <ChevronDown className={cn('transition-transform', open && 'rotate-180')} aria-hidden />
                {open ? 'Sembunyikan' : 'Lihat'} {revision.items.length} item RAB
            </Button>

            {open && (
                <TableCard className="mt-2">
                    <table id={panelId} className="w-full text-sm">
                        <thead className={TABLE_HEAD_CLASS}>
                            <tr>
                                <th className="px-3 py-2 text-left font-semibold">Deskripsi</th>
                                <th className="w-16 px-3 py-2 text-right font-semibold">Qty</th>
                                <th className="w-20 px-3 py-2 text-left font-semibold">Satuan</th>
                                <th className="w-36 px-3 py-2 text-right font-semibold">Harga Satuan</th>
                                <th className="w-36 px-3 py-2 text-right font-semibold">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            {revision.items.map((item, index) => (
                                <tr key={index} className="border-t border-daiku-border">
                                    <td className="px-3 py-2">{item.description}</td>
                                    <td className="px-3 py-2 text-right">{item.qty}</td>
                                    <td className="px-3 py-2">{item.unit}</td>
                                    <td className="px-3 py-2 text-right">{formatRupiah(item.unit_price)}</td>
                                    <td className="px-3 py-2 text-right font-medium text-foreground">{formatRupiah(item.total_price)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </TableCard>
            )}
        </li>
    );
}
