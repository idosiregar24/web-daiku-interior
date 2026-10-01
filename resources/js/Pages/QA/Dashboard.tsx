import { formatDuration, waitingLabel } from '@/Components/modules/dashboards/dashboardFormat';
import { SectionLink } from '@/Components/modules/dashboards/SectionLink';
import { EmptyState } from '@/Components/shared/EmptyState';
import { PageHeader } from '@/Components/shared/PageHeader';
import { ProgressBar } from '@/Components/shared/ProgressBar';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatCard } from '@/Components/shared/StatCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { QaDashboardStats, QaPendingRow, QaRepeatRejection } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, CircleCheck, CircleX, Hourglass, Repeat, ShieldCheck, Timer } from 'lucide-react';

interface QaDashboardProps {
    stats: QaDashboardStats;
    pending: QaPendingRow[];
    repeatRejections: { total: number; rows: QaRepeatRejection[] };
}

/**
 * Dashboard QA — the QA division's "Analytics – Per Divisi" view (PRD
 * §7.1 `P`, §4.6). Project/milestone level only: QA never sees task
 * detail ("hanya ringkasan progres milestone").
 */
export default function QaDashboard({ stats, pending, repeatRejections }: QaDashboardProps) {
    const oldest = pending[0];

    return (
        <AppLayout breadcrumbs={[{ label: 'Dashboard QA' }]}>
            <Head title="Dashboard QA" />

            <PageHeader
                title="Dashboard QA"
                icon={ShieldCheck}
                description="Antrean review milestone, keputusan bulan ini, dan milestone yang berulang kali ditolak."
                actions={
                    <Button variant="outline" asChild>
                        <Link href={route('qa-forms.index')}>
                            <ArrowLeft className="size-4" />
                            Semua QA Form
                        </Link>
                    </Button>
                }
            />

            <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Menunggu Review"
                    value={pending.length}
                    icon={Hourglass}
                    tone={oldest && oldest.daysWaiting >= 3 ? 'warning' : 'default'}
                    hint={oldest ? `Terlama ${oldest.daysWaiting} hari` : 'Antrean kosong'}
                />
                <StatCard label="Disetujui" value={stats.approvedThisMonth} icon={CircleCheck} hint={stats.monthLabel} />
                <StatCard
                    label="Ditolak"
                    value={stats.rejectedThisMonth}
                    icon={CircleX}
                    hint={
                        stats.rejectionRate === null
                            ? `Belum ada keputusan ${stats.monthLabel}`
                            : `Tingkat penolakan ${stats.rejectionRate}% · ${stats.monthLabel}`
                    }
                >
                    {stats.rejectionRate !== null && (
                        <ProgressBar value={stats.rejectionRate} tone="error" label="Tingkat penolakan bulan ini" />
                    )}
                </StatCard>
                <StatCard
                    label="Rata-rata Waktu Review"
                    value={formatDuration(stats.avgReviewHours)}
                    icon={Timer}
                    hint={`Review pertama · ${stats.reviewSample} form, ${stats.reviewWindowDays} hari terakhir`}
                />
            </div>

            <div className="grid gap-6 xl:grid-cols-3">
                <SectionCard
                    title="Antrean Review"
                    icon={Hourglass}
                    description="Terlama menunggu di atas — sejak milestone (kembali) diserahkan ke QA."
                    flush
                    className="xl:col-span-2"
                    action={<SectionLink href={route('qa-forms.index', { status: 'PENDING' })} />}
                >
                    {pending.length === 0 ? (
                        <EmptyState icon={ShieldCheck} title="Tidak ada QA Form yang menunggu review." />
                    ) : (
                        <ul className="divide-y divide-border">
                            {pending.map((form) => (
                                <li key={form.id} className="flex items-start justify-between gap-3 px-4 py-3 sm:px-5">
                                    <div className="min-w-0">
                                        <Link
                                            href={route('qa-forms.show', { qa_form: form.id })}
                                            className="block truncate text-sm font-medium text-foreground hover:underline"
                                        >
                                            {form.milestoneName}
                                        </Link>
                                        <p className="text-xs text-muted-foreground">
                                            {form.projectName}
                                            {form.milestoneTargetDate && ` · target ${formatDate(form.milestoneTargetDate)}`}
                                            {form.projectProgress !== null && ` · progres proyek ${form.projectProgress}%`}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 flex-col items-end gap-1">
                                        {form.round > 1 ? (
                                            <StatusChip status="REVIEW_ULANG" tone="warning" label={`Review ke-${form.round}`} />
                                        ) : (
                                            <StatusChip status="PENDING" label="Review pertama" />
                                        )}
                                        <span
                                            className={cn(
                                                'text-xs',
                                                form.daysWaiting >= 3 ? 'font-medium text-warning-ink' : 'text-muted-foreground',
                                            )}
                                        >
                                            {waitingLabel(form.daysWaiting)}
                                        </span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>

                <SectionCard
                    title="Ditolak ≥ 2×"
                    icon={Repeat}
                    description="Milestone yang berulang kali gagal QA (CEO ikut dinotifikasi)."
                    flush
                    footer={
                        repeatRejections.total > repeatRejections.rows.length
                            ? `Menampilkan ${repeatRejections.rows.length} dari ${repeatRejections.total}.`
                            : undefined
                    }
                >
                    {repeatRejections.rows.length === 0 ? (
                        <EmptyState icon={Repeat} title="Belum ada milestone yang ditolak dua kali." />
                    ) : (
                        <ul className="divide-y divide-border">
                            {repeatRejections.rows.map((form) => (
                                <li key={form.id} className="px-4 py-3 sm:px-5">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <Link
                                                href={route('qa-forms.show', { qa_form: form.id })}
                                                className="block truncate text-sm font-medium text-foreground hover:underline"
                                            >
                                                {form.milestoneName}
                                            </Link>
                                            <p className="text-xs text-muted-foreground">
                                                {form.projectName} · ditolak {form.rejectionCount}×
                                            </p>
                                        </div>
                                        <StatusChip status={form.status} className="shrink-0" />
                                    </div>
                                    {form.notes && <p className="mt-1 line-clamp-2 text-xs text-muted-foreground">“{form.notes}”</p>}
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>
        </AppLayout>
    );
}
