import { MoneyTrendChart } from '@/Components/modules/analytics/MoneyTrendChart';
import { waitingLabel } from '@/Components/modules/dashboards/dashboardFormat';
import { SectionLink } from '@/Components/modules/dashboards/SectionLink';
import { EmptyState } from '@/Components/shared/EmptyState';
import { Notice } from '@/Components/shared/Notice';
import { PageHeader } from '@/Components/shared/PageHeader';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatCard } from '@/Components/shared/StatCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate, formatRupiah } from '@/lib/format';
import type { QuotationMonthlyValue, QuotationQueue, QuotationQueueRow, QuotationStatus, QuotationTurnaround } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, BarChart3, ClipboardCheck, FilePen, FileText, ListOrdered, Timer, UserCheck } from 'lucide-react';

interface QuotationDashboardProps {
    statusCounts: Record<QuotationStatus, number>;
    todo: QuotationQueue;
    waitingCeo: QuotationQueue;
    waitingPm: QuotationQueue;
    monthly: QuotationMonthlyValue[];
    turnaround: QuotationTurnaround;
}

const STATUS_LABEL: Record<QuotationStatus, string> = {
    DRAFT: 'Draft (Estimator)',
    SUBMITTED: 'Menunggu CEO',
    CEO_REVIEW: 'Menunggu PM',
    PM_REVIEW: 'Review PM',
    SENT_TO_CLIENT: 'Terkirim ke klien',
    APPROVED: 'Deal (disetujui klien)',
    REJECTED: 'Ditolak',
};

const DECIDER_LABEL: Record<string, string> = { CEO: 'CEO', PM: 'PM', CLIENT: 'klien' };

function QueueList({ queue, sinceLabel, empty }: { queue: QuotationQueue; sinceLabel: string; empty: string }) {
    if (queue.rows.length === 0) {
        return <EmptyState icon={ClipboardCheck} title={empty} />;
    }

    return (
        <ul className="divide-y divide-border">
            {queue.rows.map((row: QuotationQueueRow) => (
                <li key={row.id} className="px-4 py-3 sm:px-5">
                    <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                            <Link
                                href={route('quotations.show', { quotation: row.id })}
                                className="block truncate text-sm font-medium text-foreground hover:underline"
                            >
                                {row.client}
                            </Link>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                                Versi {row.version} · {sinceLabel} {formatDate(row.since)}
                            </p>
                        </div>
                        <div className="shrink-0 text-right">
                            <p className="text-sm font-medium tabular-nums">{formatRupiah(row.totalAmount)}</p>
                            <p className={row.daysWaiting >= 3 ? 'text-xs font-medium text-warning-ink' : 'text-xs text-muted-foreground'}>
                                {waitingLabel(row.daysWaiting)}
                            </p>
                        </div>
                    </div>
                    {row.lastRejection && (
                        <Notice tone="warning" className="mt-2 py-2 text-xs">
                            Ditolak {DECIDER_LABEL[row.lastRejection.role] ?? row.lastRejection.role}
                            {row.lastRejection.by ? ` (${row.lastRejection.by})` : ''} · {formatDate(row.lastRejection.at)}
                            {row.lastRejection.note ? `: ${row.lastRejection.note}` : ''}
                        </Notice>
                    )}
                </li>
            ))}
        </ul>
    );
}

function queueFooter(queue: QuotationQueue) {
    return queue.total > queue.rows.length ? `Menampilkan ${queue.rows.length} dari ${queue.total} quotation.` : undefined;
}

/**
 * Dashboard Quotation — the Estimator's "Analytics – Per Divisi" view
 * (PRD §7.1 `P`, §4.3): work queues of the CEO→PM approval pipeline,
 * RAB value per month and turnaround. CEO + Estimator.
 */
export default function QuotationDashboard({ statusCounts, todo, waitingCeo, waitingPm, monthly, turnaround }: QuotationDashboardProps) {
    const statuses = (Object.keys(STATUS_LABEL) as QuotationStatus[]).filter(
        (status) => !['PM_REVIEW', 'REJECTED'].includes(status) || statusCounts[status] > 0,
    );
    const maxCount = Math.max(1, ...statuses.map((status) => statusCounts[status]));

    return (
        <AppLayout breadcrumbs={[{ label: 'Dashboard Quotation' }]}>
            <Head title="Dashboard Quotation" />

            <PageHeader
                title="Dashboard Quotation"
                icon={FileText}
                description="Antrean RAB, approval CEO → PM, dan nilai penawaran per bulan."
                actions={
                    <Button variant="outline" asChild>
                        <Link href={route('quotations.index')}>
                            <ArrowLeft className="size-4" />
                            Daftar Quotation
                        </Link>
                    </Button>
                }
            />

            <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Perlu Dikerjakan" value={todo.total} icon={FilePen} hint="Draft RAB, termasuk yang dikembalikan" />
                <StatCard label="Menunggu CEO" value={waitingCeo.total} icon={UserCheck} hint="Sudah disubmit Estimator" />
                <StatCard label="Menunggu PM" value={waitingPm.total} icon={ClipboardCheck} hint="Sudah disetujui CEO" />
                <StatCard
                    label="Waktu ke Klien"
                    value={turnaround.avgDays === null ? '—' : `${turnaround.avgDays.toLocaleString('id-ID')} hari`}
                    icon={Timer}
                    hint={
                        turnaround.count === 0
                            ? 'Belum ada quotation terkirim 6 bulan terakhir'
                            : `Rata-rata draft dibuat → terkirim, ${turnaround.count} quotation · ${(turnaround.avgRejections ?? 0).toLocaleString('id-ID')}× ditolak`
                    }
                />
            </div>

            <div className="grid gap-6 xl:grid-cols-2">
                <SectionCard
                    title="Perlu Dikerjakan"
                    icon={FilePen}
                    description="Draft RAB terlama di atas, dengan alasan penolakan terakhir bila dikembalikan."
                    flush
                    className="xl:col-span-2"
                    footer={queueFooter(todo)}
                    action={<SectionLink href={route('quotations.index', { status: 'DRAFT' })} />}
                >
                    <QueueList queue={todo} sinceLabel="dibuat" empty="Tidak ada draft yang perlu dikerjakan." />
                </SectionCard>

                <SectionCard
                    title="Menunggu Approval CEO"
                    icon={UserCheck}
                    flush
                    footer={queueFooter(waitingCeo)}
                    action={<SectionLink href={route('quotations.index', { status: 'SUBMITTED' })} />}
                >
                    <QueueList queue={waitingCeo} sinceLabel="disubmit" empty="Tidak ada quotation menunggu CEO." />
                </SectionCard>

                <SectionCard
                    title="Menunggu Approval PM"
                    icon={ClipboardCheck}
                    flush
                    footer={queueFooter(waitingPm)}
                    action={<SectionLink href={route('quotations.index', { status: 'CEO_REVIEW' })} />}
                >
                    <QueueList queue={waitingPm} sinceLabel="disetujui CEO" empty="Tidak ada quotation menunggu PM." />
                </SectionCard>

                <SectionCard
                    title="Nilai RAB per Bulan"
                    icon={BarChart3}
                    description="Terkirim = bulan terakhir dikirim ke klien (approval PM); Deal = bulan deal dikonfirmasi."
                >
                    <MoneyTrendChart
                        data={monthly}
                        series={[
                            { key: 'sent', name: 'Terkirim ke klien', kind: 'bar' },
                            { key: 'deal', name: 'Deal', kind: 'bar' },
                        ]}
                        ariaLabel="Grafik nilai RAB terkirim dan deal per bulan"
                    />
                </SectionCard>

                <SectionCard title="Status Quotation" icon={ListOrdered} description="Jumlah quotation di setiap tahap.">
                    <ul className="space-y-3">
                        {statuses.map((status) => (
                            <li key={status} className="grid grid-cols-[minmax(0,10rem)_1fr_2.5rem] items-center gap-3">
                                <StatusChip status={status} label={STATUS_LABEL[status]} className="justify-self-start" />
                                <div className="h-2 overflow-hidden rounded-full bg-viz-seq-1/70" aria-hidden>
                                    <div
                                        className="h-full rounded-full bg-viz-1"
                                        style={{ width: `${(statusCounts[status] / maxCount) * 100}%` }}
                                    />
                                </div>
                                <span className="text-right text-sm font-semibold tabular-nums">{statusCounts[status]}</span>
                            </li>
                        ))}
                    </ul>
                </SectionCard>
            </div>
        </AppLayout>
    );
}
