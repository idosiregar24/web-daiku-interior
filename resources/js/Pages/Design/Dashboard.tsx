import { MoneyTrendChart } from '@/Components/modules/analytics/MoneyTrendChart';
import { dueLabel, FLUSH_TABLE_CLASS, lateLabel } from '@/Components/modules/dashboards/dashboardFormat';
import { MonthRangeFilter } from '@/Components/modules/dashboards/MonthRangeFilter';
import { SectionLink } from '@/Components/modules/dashboards/SectionLink';
import { DataTable } from '@/Components/shared/DataTable';
import { EmptyState } from '@/Components/shared/EmptyState';
import { PageHeader } from '@/Components/shared/PageHeader';
import { ProgressBar } from '@/Components/shared/ProgressBar';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatCard } from '@/Components/shared/StatCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate, formatRupiah, formatRupiahCompact } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { DesignKpis, DesignPicKpi, DesignRevenue, DesignRevenueProject, MonthOption, MyDesignRow, MyDesigns } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import {
    AlarmClock,
    ArrowLeft,
    CalendarClock,
    CircleCheck,
    FileSpreadsheet,
    Layers,
    Palette,
    TrendingUp,
    UserRound,
    UsersRound,
} from 'lucide-react';

interface DesignDashboardProps {
    kpis: DesignKpis;
    revenue: DesignRevenue;
    range: { from: string; to: string };
    monthOptions: MonthOption[];
    /** Only for a DESIGNER viewer — their own designs. */
    myDesigns: MyDesigns | null;
}

const picColumns: ColumnDef<DesignPicKpi>[] = [
    {
        accessorKey: 'name',
        header: 'PIC',
        cell: ({ row }) => (
            <span className={cn('font-medium', row.original.picId === null && 'text-muted-foreground italic')}>
                {row.original.name}
            </span>
        ),
    },
    { accessorKey: 'total', header: 'Total', cell: ({ row }) => <span className="tabular-nums">{row.original.total}</span> },
    { accessorKey: 'active', header: 'Aktif', cell: ({ row }) => <span className="tabular-nums">{row.original.active}</span> },
    { accessorKey: 'done', header: 'Selesai', cell: ({ row }) => <span className="tabular-nums">{row.original.done}</span> },
    {
        accessorKey: 'onSchedule',
        header: 'Tepat Waktu',
        cell: ({ row }) => <span className="tabular-nums">{row.original.onSchedule}</span>,
    },
    {
        accessorKey: 'delayed',
        header: 'Terlambat',
        cell: ({ row }) => (
            <span className={cn('tabular-nums', row.original.delayed > 0 && 'font-medium text-error-ink')}>
                {row.original.delayed}
            </span>
        ),
    },
    {
        accessorKey: 'onTimeRate',
        header: '% Tepat Waktu',
        cell: ({ row }) =>
            row.original.onTimeRate === null ? (
                <span className="text-xs text-muted-foreground">Belum ada desain</span>
            ) : (
                <ProgressBar
                    value={row.original.onTimeRate}
                    label={`Tepat waktu ${row.original.name}`}
                    showValue
                    className="min-w-32"
                />
            ),
    },
    {
        accessorKey: 'avgDelayDays',
        header: 'Rata-rata Delay',
        cell: ({ row }) =>
            row.original.avgDelayDays === null ? (
                <span className="text-muted-foreground">—</span>
            ) : (
                <span className="tabular-nums">{row.original.avgDelayDays.toLocaleString('id-ID')} hari</span>
            ),
    },
];

const revenueColumns: ColumnDef<DesignRevenueProject>[] = [
    {
        accessorKey: 'client',
        header: 'Klien',
        cell: ({ row }) => (
            <div className="min-w-0">
                {row.original.designId ? (
                    <Link
                        href={route('design.show', { design: row.original.designId })}
                        className="font-medium text-foreground hover:underline"
                    >
                        {row.original.client}
                    </Link>
                ) : (
                    <span className="font-medium">{row.original.client}</span>
                )}
                <Link
                    href={route('projects.show', { project: row.original.projectId })}
                    className="block truncate text-xs text-muted-foreground hover:underline"
                >
                    {row.original.projectName}
                </Link>
            </div>
        ),
    },
    { accessorKey: 'pic', header: 'PIC', cell: ({ row }) => row.original.pic ?? '—' },
    { accessorKey: 'closedAt', header: 'Deal', cell: ({ row }) => formatDate(row.original.closedAt) },
    {
        accessorKey: 'contractValue',
        header: 'Nilai Kontrak',
        cell: ({ row }) => <span className="tabular-nums">{formatRupiah(row.original.contractValue)}</span>,
    },
    {
        accessorKey: 'paid',
        header: 'Terbayar',
        cell: ({ row }) => <span className="tabular-nums">{formatRupiah(row.original.paid)}</span>,
    },
    {
        accessorKey: 'piutang',
        header: 'Piutang',
        cell: ({ row }) => (
            <div className="tabular-nums">
                <span className={cn('font-medium', row.original.piutang > 0 ? 'text-foreground' : 'text-success-ink')}>
                    {row.original.piutang > 0 ? formatRupiah(row.original.piutang) : 'Lunas'}
                </span>
                {row.original.unscheduled > 0 && (
                    <span className="block text-xs text-warning-ink">
                        {formatRupiah(row.original.unscheduled)} belum ada termin
                    </span>
                )}
            </div>
        ),
    },
    { accessorKey: 'status', header: 'Status Proyek', cell: ({ row }) => <StatusChip status={row.original.status} /> },
];

/** One row of the "Desain Saya" lists. */
function MyDesignItem({ design, late }: { design: MyDesignRow; late: boolean }) {
    return (
        <li className="flex items-start justify-between gap-3 px-4 py-3 sm:px-5">
            <div className="min-w-0">
                <Link
                    href={route('design.show', { design: design.id })}
                    className="block truncate text-sm font-medium text-foreground hover:underline"
                >
                    {design.client}
                </Link>
                <p className="mt-0.5 text-xs text-muted-foreground">Deadline {formatDate(design.deadline)}</p>
            </div>
            <div className="flex shrink-0 flex-col items-end gap-1">
                <StatusChip status={design.status} />
                <span className={cn('text-xs font-medium', late ? 'text-error-ink' : 'text-warning-ink')}>
                    {late ? lateLabel(design.delayDays) : dueLabel(design.daysLeft ?? 0)}
                </span>
            </div>
        </li>
    );
}

function MyDesignList({ title, rows, late, empty }: { title: string; rows: MyDesignRow[]; late: boolean; empty: string }) {
    return (
        <div className="min-w-0">
            <p className="border-b border-border bg-daiku-gray/60 px-4 py-2 text-[11px] font-semibold tracking-wider text-daiku-muted uppercase sm:px-5">
                {title} ({rows.length})
            </p>
            {rows.length === 0 ? (
                <p className="px-4 py-6 text-center text-sm text-muted-foreground sm:px-5">{empty}</p>
            ) : (
                <ul className="divide-y divide-border">
                    {rows.map((design) => (
                        <MyDesignItem key={design.id} design={design} late={late} />
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * KPI Desain — PRD §4.2 "KPI per PIC" (on schedule vs delay per
 * designer) and "Tracking Omset Desain" (omset & piutang per closing
 * month and per project). The Design division's "Analytics – Per Divisi"
 * view (PRD §7.1 `P`), CEO + Designer; a designer also gets "Desain Saya".
 */
export default function DesignDashboard({ kpis, revenue, range, monthOptions, myDesigns }: DesignDashboardProps) {
    const { summary } = kpis;
    const firstMonth = revenue.months[0];
    const lastMonth = revenue.months[revenue.months.length - 1];
    const periodLabel =
        firstMonth && lastMonth && firstMonth.month !== lastMonth.month
            ? `${firstMonth.label} – ${lastMonth.label}`
            : (lastMonth?.label ?? '');

    return (
        <AppLayout breadcrumbs={[{ label: 'KPI Desain' }]}>
            <Head title="KPI Desain" />

            <PageHeader
                title="KPI Desain"
                icon={Palette}
                description="Kinerja PIC desain (tepat waktu vs terlambat) serta omset & piutang proyek desain."
                actions={
                    <Button variant="outline" asChild>
                        <Link href={route('design.index')}>
                            <ArrowLeft className="size-4" />
                            Daftar Desain
                        </Link>
                    </Button>
                }
            />

            {myDesigns && (
                <SectionCard
                    title="Desain Saya"
                    icon={UserRound}
                    description={`${myDesigns.openCount} desain aktif dengan Anda sebagai PIC.`}
                    flush
                    className="mb-6"
                    contentClassName="grid divide-y divide-border md:grid-cols-2 md:divide-x md:divide-y-0"
                >
                    <MyDesignList
                        title="Deadline minggu ini"
                        rows={myDesigns.dueThisWeek}
                        late={false}
                        empty="Tidak ada deadline minggu ini."
                    />
                    <MyDesignList
                        title="Terlambat"
                        rows={myDesigns.overdue}
                        late
                        empty="Tidak ada desain yang terlambat."
                    />
                </SectionCard>
            )}

            <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Total Desain"
                    value={summary.total}
                    icon={Layers}
                    hint={`${summary.active} aktif · ${summary.done} selesai produksi`}
                />
                <StatCard
                    label="Terlambat Saat Ini"
                    value={summary.delayedActive}
                    icon={AlarmClock}
                    tone={summary.delayedActive > 0 ? 'error' : 'default'}
                    hint={summary.delayedActive > 0 ? `dari ${summary.active} desain aktif` : 'Semua desain aktif sesuai jadwal'}
                />
                <StatCard
                    label="Tepat Waktu"
                    value={summary.onTimeRate === null ? '—' : `${summary.onTimeRate}%`}
                    icon={CircleCheck}
                    hint="Semua desain, termasuk yang sudah selesai"
                >
                    {summary.onTimeRate !== null && (
                        <ProgressBar value={summary.onTimeRate} label="Persentase desain tepat waktu" />
                    )}
                </StatCard>
                <StatCard
                    label="Omset Desain"
                    value={formatRupiahCompact(revenue.totals.omset)}
                    icon={TrendingUp}
                    trend={revenue.months.map((row) => row.omset)}
                    hint={`${periodLabel} · piutang ${formatRupiahCompact(revenue.totals.piutang)}`}
                />
            </div>

            <SectionCard
                title="KPI per PIC"
                icon={UsersRound}
                description="Total proyek desain per desainer, tepat waktu vs terlambat."
                flush
                className="mb-6"
                footer="Terlambat = delay_hari > 0, atau lewat deadline dan belum DONE_PRODUKSI. Hari HOLD_CLIENT/REVISI_CLIENT tidak dihitung sebagai delay."
            >
                <DataTable
                    columns={picColumns}
                    data={kpis.byPic}
                    emptyMessage="Belum ada desainer aktif."
                    className={FLUSH_TABLE_CLASS}
                />
            </SectionCard>

            <SectionCard
                title="Omset & Piutang Desain per Bulan"
                icon={TrendingUp}
                description="Nilai kontrak proyek desain menurut bulan deal, dan sisa yang belum dibayar klien."
                className="mb-6"
                footer={
                    <span>
                        Omset {formatRupiah(revenue.totals.omset)} · terbayar {formatRupiah(revenue.totals.paid)} · piutang{' '}
                        <span className="font-medium text-foreground">{formatRupiah(revenue.totals.piutang)}</span>
                        {revenue.totals.unscheduled > 0 &&
                            ` (termasuk ${formatRupiah(revenue.totals.unscheduled)} yang belum dijadwalkan terminnya)`}
                        . Proyek CANCELLED tidak dihitung.
                    </span>
                }
            >
                <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <p className="text-sm text-muted-foreground">
                        {revenue.totals.projects} proyek · {periodLabel}
                    </p>
                    <MonthRangeFilter routeName="design.dashboard" range={range} options={monthOptions} only={['revenue', 'range']} />
                </div>
                {revenue.totals.projects === 0 ? (
                    <EmptyState icon={TrendingUp} title="Belum ada proyek desain yang deal pada periode ini." />
                ) : (
                    <MoneyTrendChart
                        data={revenue.months}
                        series={[
                            { key: 'omset', name: 'Omset (nilai kontrak)', kind: 'bar' },
                            { key: 'piutang', name: 'Piutang', kind: 'bar' },
                        ]}
                        ariaLabel="Grafik omset dan piutang proyek desain per bulan deal"
                    />
                )}
            </SectionCard>

            <SectionCard
                title="Rincian per Proyek"
                icon={FileSpreadsheet}
                description={`Proyek desain yang deal pada ${periodLabel || 'periode ini'}, terbaru di atas.`}
                flush
                action={<SectionLink href={route('projects.index')}>Semua proyek</SectionLink>}
            >
                <DataTable
                    columns={revenueColumns}
                    data={revenue.projects}
                    emptyMessage="Belum ada proyek desain yang deal pada periode ini."
                    className={FLUSH_TABLE_CLASS}
                />
            </SectionCard>

            <p className="mt-4 flex items-center gap-1.5 text-xs text-muted-foreground">
                <CalendarClock className="size-3.5" aria-hidden />
                Piutang = nilai kontrak − DP & pelunasan yang sudah diterima di termin.
            </p>
        </AppLayout>
    );
}
