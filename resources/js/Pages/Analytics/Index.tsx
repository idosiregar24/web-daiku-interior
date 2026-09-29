import { MoneyTrendChart } from '@/Components/modules/analytics/MoneyTrendChart';
import { type HeatmapData, OverdueHeatmap } from '@/Components/modules/analytics/OverdueHeatmap';
import { type FunnelData, PipelineFunnel } from '@/Components/modules/analytics/PipelineFunnel';
import { RevenueTargetDialog } from '@/Components/modules/analytics/RevenueTargetDialog';
import { TaskStatusBreakdown } from '@/Components/modules/analytics/TaskStatusBreakdown';
import { EmptyState } from '@/Components/shared/EmptyState';
import { PageHeader } from '@/Components/shared/PageHeader';
import { ProgressBar } from '@/Components/shared/ProgressBar';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatCard } from '@/Components/shared/StatCard';
import { Button } from '@/Components/ui/button';
import AppLayout from '@/Layouts/AppLayout';
import { formatDateTime, formatRupiah, formatRupiahCompact } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { TaskStatus } from '@/types';
import { Head, Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    BarChart3,
    CalendarX2,
    Filter,
    FolderKanban,
    ListChecks,
    Package,
    PiggyBank,
    Target,
    TrendingUp,
    UsersRound,
    Wallet,
} from 'lucide-react';
import { useMemo, useState } from 'react';

interface ActiveProject {
    id: number;
    name: string;
    pm: string | null;
    contractValue: number;
    progress: number;
    milestonesCompleted: number;
    milestonesTotal: number;
    overdueTasks: number;
}

interface MonthlyRevenue {
    month: string;
    label: string;
    revenue: number;
    target: number | null;
}

interface MonthlyCashFlow {
    month: string;
    label: string;
    income: number;
    expense: number;
}

interface PmPerformance {
    id: number;
    name: string;
    activeProjects: number;
    completedProjects: number;
    milestonesCompleted: number;
    onTimeRate: number | null;
    overdueTasks: number;
}

interface AnalyticsProps {
    funnel: FunnelData;
    activeProjects: ActiveProject[];
    revenue: MonthlyRevenue[];
    cashFlow: MonthlyCashFlow[];
    teamPerformance: PmPerformance[];
    penalties: {
        monthTotal: number;
        monthCount: number;
        allTimeTotal: number;
        fundBalance: number;
        topStaff: { name: string; count: number; total: number }[];
    };
    materialMargin: {
        realized: number;
        potential: number;
        lowStockCount: number;
        topMaterials: { name: string; qtyUsed: number; margin: number }[];
    };
    overdueHeatmap: HeatmapData;
    taskStatus: Record<TaskStatus, number>;
    generatedAt: string;
}

/** Small inline "Detail →" link used in widget headers. */
function DetailLink({ href, children = 'Detail' }: { href: string; children?: string }) {
    return (
        <Button variant="ghost" size="sm" asChild>
            <Link href={href}>
                {children}
                <ArrowRight className="size-3.5" />
            </Link>
        </Button>
    );
}

/** Label/value pair for the compact summary rows inside widgets. */
function Metric({ label, value, tone }: { label: string; value: string; tone?: 'error' }) {
    return (
        <div className="rounded-lg bg-daiku-gray/70 px-3 py-2.5 ring-1 ring-border ring-inset">
            <dt className="text-[11px] font-medium text-muted-foreground">{label}</dt>
            <dd className={cn('mt-0.5 text-sm font-semibold text-foreground', tone === 'error' && 'text-error-ink')}>{value}</dd>
        </div>
    );
}

/**
 * PRD §4.10 CEO Executive Dashboard — all eight widgets (Pipeline Funnel,
 * Active Projects, Revenue vs Target, Cash Flow, Team Performance,
 * Penalty Summary, Material Margin, Overdue Heatmap). CEO only (PRD §7.1
 * "Analytics – Executive").
 */
export default function AnalyticsIndex({
    funnel,
    activeProjects,
    revenue,
    cashFlow,
    teamPerformance,
    penalties,
    materialMargin,
    overdueHeatmap,
    taskStatus,
    generatedAt,
}: AnalyticsProps) {
    const [targetOpen, setTargetOpen] = useState(false);

    const thisMonth = revenue[revenue.length - 1];
    const lastMonth = revenue[revenue.length - 2];
    const targets = useMemo(() => Object.fromEntries(revenue.map((row) => [row.month, row.target])), [revenue]);
    const achievement = thisMonth?.target ? Math.round((thisMonth.revenue / thisMonth.target) * 100) : null;
    const revenueChange =
        thisMonth && lastMonth && lastMonth.revenue > 0
            ? ((thisMonth.revenue - lastMonth.revenue) / lastMonth.revenue) * 100
            : null;
    const overdueTotal = overdueHeatmap.rows.reduce((sum, row) => sum + row.total, 0);
    const activeTaskTotal = Object.values(taskStatus).reduce((sum, count) => sum + count, 0);

    return (
        <AppLayout>
            <Head title="Executive Dashboard" />

            <PageHeader
                title="Executive Dashboard"
                icon={BarChart3}
                description={`Ringkasan seluruh divisi · diperbarui ${formatDateTime(generatedAt)}`}
            />

            <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label={`Kontrak Closing ${thisMonth?.label ?? ''}`}
                    value={formatRupiahCompact(thisMonth?.revenue ?? 0)}
                    icon={Target}
                    trend={revenue.map((row) => row.revenue)}
                    delta={revenueChange === null ? undefined : { value: revenueChange, label: 'vs bulan lalu' }}
                    hint={
                        achievement === null
                            ? 'Target bulan ini belum diatur'
                            : `${achievement}% dari target ${formatRupiahCompact(thisMonth?.target ?? 0)}`
                    }
                />
                <StatCard
                    label="Proyek Aktif"
                    value={activeProjects.length}
                    icon={FolderKanban}
                    hint={`${activeTaskTotal} task berjalan`}
                />
                <StatCard
                    label="Task Terlambat"
                    value={overdueTotal}
                    icon={AlertTriangle}
                    tone={overdueTotal > 0 ? 'error' : 'default'}
                    hint={overdueTotal > 0 ? `di ${overdueHeatmap.rows.length} proyek` : 'Semua sesuai jadwal'}
                />
                <StatCard
                    label="Dana Family Gathering"
                    value={formatRupiahCompact(penalties.fundBalance)}
                    icon={PiggyBank}
                    hint={`${penalties.monthCount} penalti bulan ini`}
                />
            </div>

            <div className="grid gap-6 xl:grid-cols-2">
                <SectionCard
                    title="Revenue vs Target"
                    icon={TrendingUp}
                    description="Nilai kontrak closing per bulan dibanding target, 6 bulan terakhir."
                    action={
                        <Button variant="outline" size="sm" onClick={() => setTargetOpen(true)}>
                            Atur Target
                        </Button>
                    }
                >
                    <MoneyTrendChart
                        data={revenue}
                        series={[
                            { key: 'revenue', name: 'Nilai kontrak', kind: 'bar' },
                            { key: 'target', name: 'Target', kind: 'line' },
                        ]}
                        ariaLabel="Grafik nilai kontrak closing dibanding target per bulan"
                    />
                </SectionCard>

                <SectionCard title="Cash Flow" icon={Wallet} description="Pemasukan vs pengeluaran, 6 bulan terakhir.">
                    <MoneyTrendChart
                        data={cashFlow}
                        series={[
                            { key: 'income', name: 'Pemasukan', kind: 'bar' },
                            { key: 'expense', name: 'Pengeluaran', kind: 'bar' },
                        ]}
                        ariaLabel="Grafik pemasukan dibanding pengeluaran per bulan"
                    />
                </SectionCard>

                <SectionCard title="Pipeline Funnel" icon={Filter} description="Lead yang mencapai setiap tahap (kumulatif).">
                    <PipelineFunnel data={funnel} />
                </SectionCard>

                <SectionCard
                    title="Status Task"
                    icon={ListChecks}
                    description="Sebaran status seluruh task di proyek aktif."
                    action={<DetailLink href={route('tasks.index')}>Semua task</DetailLink>}
                >
                    <TaskStatusBreakdown data={taskStatus} />
                </SectionCard>

                <SectionCard
                    title="Proyek Aktif"
                    icon={FolderKanban}
                    description="Progres terakhir yang dilaporkan PM dan milestone yang lolos QA."
                    flush
                    className="xl:col-span-2"
                    action={<DetailLink href={route('projects.index', { status: 'ACTIVE' })}>Semua proyek</DetailLink>}
                >
                    {activeProjects.length === 0 ? (
                        <EmptyState icon={FolderKanban} title="Belum ada proyek aktif." />
                    ) : (
                        <ul className="grid divide-y divide-border md:grid-cols-2 md:divide-y-0">
                            {activeProjects.map((project) => (
                                <li
                                    key={project.id}
                                    className="border-border px-4 py-3.5 sm:px-5 md:border-b md:odd:border-r"
                                >
                                    <div className="flex items-baseline justify-between gap-3">
                                        <Link
                                            href={route('projects.show', { project: project.id })}
                                            className="truncate text-sm font-medium text-foreground hover:underline"
                                        >
                                            {project.name}
                                        </Link>
                                        <span className="shrink-0 text-sm font-semibold tabular-nums">{project.progress}%</span>
                                    </div>
                                    <ProgressBar value={project.progress} label={`Progres ${project.name}`} className="mt-2" />
                                    <p className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                        <span>PM {project.pm ?? '—'}</span>
                                        <span>
                                            Milestone {project.milestonesCompleted}/{project.milestonesTotal}
                                        </span>
                                        <span>{formatRupiahCompact(project.contractValue)}</span>
                                        {project.overdueTasks > 0 && (
                                            <span className="font-medium text-error-ink">{project.overdueTasks} task terlambat</span>
                                        )}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>

                <SectionCard
                    title="Performa PM"
                    icon={UsersRound}
                    description="On-time = milestone lolos QA pada/sebelum target tanggalnya."
                    className="xl:col-span-2"
                    flush
                >
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-daiku-yellow-light/70">
                                <tr className="text-[11px] tracking-wider text-daiku-muted uppercase">
                                    <th className="px-4 py-2.5 text-left font-semibold sm:px-5">PM</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Proyek Aktif</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Proyek Selesai</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Milestone Selesai</th>
                                    <th className="w-56 px-4 py-2.5 text-left font-semibold">On-time Rate</th>
                                    <th className="px-4 py-2.5 text-right font-semibold sm:px-5">Task Terlambat</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {teamPerformance.length === 0 ? (
                                    <tr>
                                        <td colSpan={6}>
                                            <EmptyState icon={UsersRound} title="Belum ada PM aktif." />
                                        </td>
                                    </tr>
                                ) : (
                                    teamPerformance.map((pm) => (
                                        <tr key={pm.id} className="transition-colors hover:bg-daiku-gray/60">
                                            <td className="px-4 py-3 font-medium sm:px-5">{pm.name}</td>
                                            <td className="px-4 py-3 text-right tabular-nums">{pm.activeProjects}</td>
                                            <td className="px-4 py-3 text-right tabular-nums">{pm.completedProjects}</td>
                                            <td className="px-4 py-3 text-right tabular-nums">{pm.milestonesCompleted}</td>
                                            <td className="px-4 py-3">
                                                {pm.onTimeRate === null ? (
                                                    <span className="text-xs text-muted-foreground">Belum ada data</span>
                                                ) : (
                                                    <ProgressBar value={pm.onTimeRate} label={`On-time rate ${pm.name}`} showValue />
                                                )}
                                            </td>
                                            <td
                                                className={cn(
                                                    'px-4 py-3 text-right tabular-nums sm:px-5',
                                                    pm.overdueTasks > 0 && 'font-medium text-error-ink',
                                                )}
                                            >
                                                {pm.overdueTasks}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </SectionCard>

                <SectionCard
                    title="Task Terlambat per Proyek"
                    icon={CalendarX2}
                    description="Jumlah task lewat deadline, dikelompokkan per minggu jatuh tempo."
                    className="xl:col-span-2"
                >
                    <OverdueHeatmap data={overdueHeatmap} />
                </SectionCard>

                <SectionCard
                    title="Penalti & Dana Family Gathering"
                    icon={PiggyBank}
                    action={<DetailLink href={route('penalties.index')} />}
                >
                    <dl className="mb-5 grid grid-cols-1 gap-2 sm:grid-cols-3">
                        <Metric label="Bulan ini" value={formatRupiah(penalties.monthTotal)} />
                        <Metric label="Sepanjang waktu" value={formatRupiah(penalties.allTimeTotal)} />
                        <Metric label="Saldo dana" value={formatRupiah(penalties.fundBalance)} />
                    </dl>
                    <p className="mb-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                        Penalti terbanyak bulan ini
                    </p>
                    {penalties.topStaff.length === 0 ? (
                        <p className="text-sm text-muted-foreground">Tidak ada penalti bulan ini.</p>
                    ) : (
                        <ul className="divide-y divide-border text-sm">
                            {penalties.topStaff.map((staff) => (
                                <li key={staff.name} className="flex justify-between gap-3 py-2 first:pt-0 last:pb-0">
                                    <span>{staff.name}</span>
                                    <span className="text-muted-foreground tabular-nums">
                                        {staff.count}× ·{' '}
                                        <span className="font-medium text-foreground">{formatRupiah(staff.total)}</span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>

                <SectionCard
                    title="Margin Material"
                    icon={Package}
                    action={<DetailLink href={route('logistics.materials.index')} />}
                >
                    <dl className="mb-5 grid grid-cols-1 gap-2 sm:grid-cols-3">
                        <Metric label="Margin terealisasi" value={formatRupiahCompact(materialMargin.realized)} />
                        <Metric label="Potensi (stok)" value={formatRupiahCompact(materialMargin.potential)} />
                        <Metric
                            label="Stok menipis"
                            value={`${materialMargin.lowStockCount} item`}
                            tone={materialMargin.lowStockCount > 0 ? 'error' : undefined}
                        />
                    </dl>
                    <p className="mb-2 text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                        Margin terbesar dari pemakaian proyek
                    </p>
                    {materialMargin.topMaterials.length === 0 ? (
                        <p className="text-sm text-muted-foreground">Belum ada pemakaian material tercatat.</p>
                    ) : (
                        <ul className="divide-y divide-border text-sm">
                            {materialMargin.topMaterials.map((material) => (
                                <li key={material.name} className="flex justify-between gap-3 py-2 first:pt-0 last:pb-0">
                                    <span className="truncate">{material.name}</span>
                                    <span className="shrink-0 text-muted-foreground tabular-nums">
                                        {material.qtyUsed} terpakai ·{' '}
                                        <span className="font-medium text-foreground">{formatRupiah(material.margin)}</span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>

            <RevenueTargetDialog open={targetOpen} onOpenChange={setTargetOpen} existing={targets} />
        </AppLayout>
    );
}
