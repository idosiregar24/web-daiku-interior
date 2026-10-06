import { EmptyState } from '@/Components/shared/EmptyState';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { formatDate, formatRupiah } from '@/lib/format';
import type { Quotation } from '@/types';
import { Button } from '@/Components/ui/button';
import { Link } from '@inertiajs/react';
import { Copy, ExternalLink, FileDown, FileStack } from 'lucide-react';
import { toast } from 'sonner';
import type { ReactNode } from 'react';
import { QUOTATION_TYPE_LABEL, quotationTitle } from './labels';

type HistoryQuotation = Pick<Quotation, 'id' | 'type' | 'custom_name' | 'parent_quotation_id' | 'status' | 'total_amount' | 'version' | 'created_at'> & {
    /** Sprint 15 — "377/OFF/Daiku/IX/2026" once sent. */
    letter_number?: string | null;
    /** Sprint 15 — this RAB's own client link (CEO/Marketing only; null otherwise or not sent yet). */
    client_url?: string | null;
};

function copyLink(url: string) {
    navigator.clipboard
        .writeText(url)
        .then(() => toast.success('Link klien disalin.'))
        .catch(() => toast.error('Gagal menyalin — buka link lalu salin dari browser.'));
}

/** Fixed sections first, in the order a client usually goes through them; custom names after, A–Z. */
const FIXED_ORDER = [QUOTATION_TYPE_LABEL.SURVEY, QUOTATION_TYPE_LABEL.DESAIN, QUOTATION_TYPE_LABEL.PROYEK, 'RAB Tambahan'];

/**
 * Sprint 14 Sub 02 — "Riwayat RAB": every RAB of one client in one place,
 * one section per kind (Jasa Survey · Jasa Desain · Proyek · RAB Tambahan ·
 * each custom name), newest first inside a section. Sprint 15: each RAB
 * shows its letter number, its PDF, and — for CEO/Marketing — its own client link.
 */
export function RabHistoryCard({ quotations, action }: { quotations: HistoryQuotation[]; action?: ReactNode }) {
    const groups = new Map<string, HistoryQuotation[]>();
    for (const quotation of quotations) {
        const title = quotationTitle(quotation);
        groups.set(title, [...(groups.get(title) ?? []), quotation]);
    }

    const ordered = [...groups.entries()].sort(([a], [b]) => {
        const rank = (title: string) => (FIXED_ORDER.includes(title) ? FIXED_ORDER.indexOf(title) : FIXED_ORDER.length);

        return rank(a) - rank(b) || a.localeCompare(b, 'id');
    });

    return (
        <SectionCard title="Riwayat RAB" description="Semua RAB klien ini, dikelompokkan per jenis." icon={FileStack} action={action} flush>
            {ordered.length === 0 ? (
                <EmptyState title="Belum ada RAB untuk klien ini." />
            ) : (
                <div className="divide-y divide-border">
                    {ordered.map(([title, rows]) => (
                        <section key={title} className="px-4 py-3 sm:px-5">
                            <h3 className="mb-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                {title} <span className="font-normal normal-case">({rows.length})</span>
                            </h3>
                            <ul className="space-y-1.5">
                                {[...rows]
                                    .sort((a, b) => b.id - a.id)
                                    .map((quotation) => (
                                        <li key={quotation.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                                            <Link
                                                href={route('quotations.show', { quotation: quotation.id })}
                                                className="font-medium text-foreground underline decoration-daiku-yellow underline-offset-4 hover:decoration-2"
                                            >
                                                QUO-{String(quotation.id).padStart(5, '0')} · Versi {quotation.version}
                                            </Link>
                                            <StatusChip status={quotation.status} />
                                            <span className="tabular-nums">{formatRupiah(quotation.total_amount)}</span>
                                            <span className="text-xs text-muted-foreground">{formatDate(quotation.created_at)}</span>
                                            {quotation.letter_number && (
                                                <span className="text-xs text-muted-foreground">No. {quotation.letter_number}</span>
                                            )}
                                            <span className="ml-auto flex items-center gap-1">
                                                <Button variant="ghost" size="sm" asChild>
                                                    <a href={route('quotations.pdf', { quotation: quotation.id })} target="_blank" rel="noopener noreferrer">
                                                        <FileDown className="size-3.5" />
                                                        PDF
                                                    </a>
                                                </Button>
                                                {quotation.client_url && (
                                                    <>
                                                        <Button variant="ghost" size="sm" onClick={() => copyLink(quotation.client_url!)}>
                                                            <Copy className="size-3.5" />
                                                            Salin link klien
                                                        </Button>
                                                        <Button variant="ghost" size="icon-sm" asChild>
                                                            <a href={quotation.client_url} target="_blank" rel="noopener noreferrer" aria-label="Buka link klien">
                                                                <ExternalLink className="size-3.5" />
                                                            </a>
                                                        </Button>
                                                    </>
                                                )}
                                            </span>
                                        </li>
                                    ))}
                            </ul>
                        </section>
                    ))}
                </div>
            )}
        </SectionCard>
    );
}
