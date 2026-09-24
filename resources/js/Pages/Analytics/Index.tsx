import { MoneyTrendChart } from '@/Components/modules/analytics/MoneyTrendChart';
import { type HeatmapData, OverdueHeatmap } from '@/Components/modules/analytics/OverdueHeatmap';
import { type FunnelData, PipelineFunnel } from '@/Components/modules/analytics/PipelineFunnel';
import { RevenueTargetDialog } from '@/Components/modules/analytics/RevenueTargetDialog';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StatCard } from '@/Components/shared/StatCard';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import AppLayout from '@/Layouts/AppLayout';
import { formatDateTime, formatRupiah, formatRupiahCompact } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { TaskStatus } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, FolderKanban, PiggyBank, Target, TrendingUp } from 'lucide-react';
import { type ReactNode, useMemo, useState } from 'react';

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

function Widget({
    title,
    description,
    action,
    children,
    className,
}: {
    title: string;
    description?: string;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <Card className={className}>
            <CardHeader className="flex flex-row items-start justify-between gap-4">
                <div>
                    <CardTitle className="text-base">{title}</CardTitle>
                    {description && <CardDescription>{description}</CardDescription>}
                </div>
                {action}
            </CardHeader>
            <CardContent>{children}</CardContent>
        </Card>
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
    const targets = useMemo(() => Object.fromEntries(revenue.map((row) => [row.month, row.target])), [revenue]);
    const achievement = thisMonth?.target ? Math.round((thisMonth.revenue / thisMonth.target) * 100) : null;
    const overdueTotal = overdueHeatmap.rows.reduce((sum, row) => sum + row.total, 0);
    const activeTaskTotal = Object.values(taskStatus).reduce((sum, count) => sum + count, 0);

    return (
        <AppLayout breadcrumbs={[{ label: 'Eksekutif' }, { label: 'Analytics' }]}>
            <Head title="Executive Dashboard" />

            <PageHeader
                title="Executive Dashboard"
                description={`Ringkasan seluruh divisi · diperbarui ${formatDateTime(generatedAt)}`}
            />

            <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label={`Kontrak Closing ${thisMonth?.label ?? ''}`}
                    value={formatRupiahCompact(thisMonth?.revenue ?? 0)}
                    icon={Target}
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
                <Widget title="Pipeline Funnel" description="Lead yang mencapai setiap tahap (kumulatif).">
                    <PipelineFunnel data={funnel} />
                </Widget>

                <Widget
                    title="Revenue vs Target"
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
                </Widget>

                <Widget title="Cash Flow" description="Pemasukan vs pengeluaran, 6 bulan terakhir.">
                    <MoneyTrendChart
                        data={cashFlow}
                        series={[
                            { key: 'income', name: 'Pemasukan', kind: 'bar' },
                            { key: 'expense', name: 'Pengeluaran', kind: 'bar' },
                        ]}
                        ariaLabel="Grafik pemasukan dibanding pengeluaran per bulan"
                    />
                </Widget>

                <Widget
                    title="Proyek Aktif"
                    description="Progres terakhir yang dilaporkan PM dan milestone yang lolos QA."
                    action={
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={route('projects.index', { status: 'ACTIVE' })}>Semua proyek</Link>
                        </Button>
                    }
                >
                    {activeProjects.length === 0 ? (
                        <p className="py-8 text-center text-sm text-daiku-muted">Belum ada proyek aktif.</p>
                    ) : (
                        <ul className="divide-y divide-daiku-border">
                            {activeProjects.map((project) => (
                                <li key={project.id} className="py-3 first:pt-0 last:pb-0">
                                    <div className="flex items-baseline justify-between gap-3">
                                        <Link
                                            href={route('projects.show', { project: project.id })}
                                            className="truncate text-sm font-medium text-daiku-dark hover:underline"
                                        >
                                            {project.name}
                                        </Link>
                                        <span className="shrink-0 text-sm font-semibold tabular-nums">{project.progress}%</span>
                                    </div>
                                    <div
                                        className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-daiku-gray"
                                        role="progressbar"
                                        aria-valuenow={project.progress}
                                        aria-valuemin={0}
                                        aria-valuemax={100}
                                        aria-label={`Progres ${project.name}`}
                                    >
                                        <div className="h-full rounded-full bg-viz-1" style={{ width: `${project.progress}%` }} />
                                    </div>
                                    <p className="mt-1 flex flex-wrap gap-x-3 text-xs text-daiku-muted">
                                        <span>PM {project.pm ?? '—'}</span>
                                        <span>
                                            Milestone {project.milestonesCompleted}/{project.milestonesTotal}
                                        </span>
                                        <span>{formatRupiahCompact(project.contractValue)}</span>
                                        {project.overdueTasks > 0 && (
                                            <span className="font-medium text-error">{project.overdueTasks} task terlambat</span>
                                        )}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </Widget>

                <Widget
                    title="Performa PM"
                    description="On-time = milestone lolos QA pada/sebelum target tanggalnya."
                    className="xl:col-span-2"
                >
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-daiku-yellow-light">
                                <tr>
                                    <th className="p-2 text-left font-medium">PM</th>
                                    <th className="p-2 text-right font-medium">Proyek Aktif</th>
                                    <th className="p-2 text-right font-medium">Proyek Selesai</th>
                                    <th className="p-2 text-right font-medium">Milestone Selesai</th>
                                    <th className="w-48 p-2 text-left font-medium">On-time Rate</th>
                                    <th className="p-2 text-right font-medium">Task Terlambat</th>
                                </tr>
                            </thead>
                            <tbody>
                                {teamPerformance.length === 0 ? (
                                    <tr>
                                        <td colSpan={6} className="p-6 text-center text-daiku-muted">
                                            Belum ada PM aktif.
                                        </td>
                                    </tr>
                                ) : (
                                    teamPerformance.map((pm) => (
                                        <tr key={pm.id} className="border-t border-daiku-border">
                                            <td className="p-2 font-medium">{pm.name}</td>
                                            <td className="p-2 text-right tabular-nums">{pm.activeProjects}</td>
                                            <td className="p-2 text-right tabular-nums">{pm.completedProjects}</td>
                                            <td className="p-2 text-right tabular-nums">{pm.milestonesCompleted}</td>
                                            <td className="p-2">
                                                {pm.onTimeRate === null ? (
                                                    <span className="text-xs text-daiku-muted">Belum ada data</span>
                                                ) : (
                                                    <div className="flex items-center gap-2">
                                                        <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-daiku-gray">
                                                            <div className="h-full rounded-full bg-viz-1" style={{ width: `${pm.onTimeRate}%` }} />
                                                        </div>
                                                        <span className="w-10 text-right text-xs font-medium tabular-nums">{pm.onTimeRate}%</span>
                                                    </div>
                                                )}
                                            </td>
                                            <td className={cn('p-2 text-right tabular-nums', pm.overdueTasks > 0 && 'font-medium text-error')}>
                                                {pm.overdueTasks}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </Widget>

                <Widget
                    title="Task Terlambat per Proyek"
                    description="Jumlah task lewat deadline, dikelompokkan per minggu jatuh tempo."
                    className="xl:col-span-2"
                >
                    <OverdueHeatmap data={overdueHeatmap} />
                </Widget>

                <Widget
                    title="Penalti & Dana Family Gathering"
                    action={
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={route('penalties.index')}>Detail</Link>
                        </Button>
                    }
                >
                    <dl className="mb-4 grid grid-cols-3 gap-4">
                        <div>
                            <dt className="text-xs text-daiku-muted">Bulan ini</dt>
                            <dd className="font-semibold tabular-nums">{formatRupiah(penalties.monthTotal)}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-daiku-muted">Sepanjang waktu</dt>
                            <dd className="font-semibold tabular-nums">{formatRupiah(penalties.allTimeTotal)}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-daiku-muted">Saldo dana</dt>
                            <dd className="font-semibold tabular-nums">{formatRupiah(penalties.fundBalance)}</dd>
                        </div>
                    </dl>
                    <p className="mb-2 text-xs font-medium text-daiku-muted">Penalti terbanyak bulan ini</p>
                    {penalties.topStaff.length === 0 ? (
                        <p className="text-sm text-daiku-muted">Tidak ada penalti bulan ini.</p>
                    ) : (
                        <ul className="space-y-1.5 text-sm">
                            {penalties.topStaff.map((staff) => (
                                <li key={staff.name} className="flex justify-between gap-3">
                                    <span>{staff.name}</span>
                                    <span className="tabular-nums text-daiku-muted">
                                        {staff.count}× · <span className="font-medium text-daiku-dark">{formatRupiah(staff.total)}</span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Widget>

                <Widget
                    title="Margin Material"
                    action={
                        <Button variant="ghost" size="sm" asChild>
                            <Link href={route('logistics.materials.index')}>Detail</Link>
                        </Button>
                    }
                >
                    <dl className="mb-4 grid grid-cols-3 gap-4">
                        <div>
                            <dt className="text-xs text-daiku-muted">Margin terealisasi</dt>
                            <dd className="flex items-center gap-1 font-semibold tabular-nums">
                                <TrendingUp className="size-3.5 text-daiku-muted" aria-hidden />
                                {formatRupiahCompact(materialMargin.realized)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-daiku-muted">Potensi (stok)</dt>
                            <dd className="font-semibold tabular-nums">{formatRupiahCompact(materialMargin.potential)}</dd>
                        </div>
                        <div>
                            <dt className="text-xs text-daiku-muted">Stok menipis</dt>
                            <dd className={cn('font-semibold tabular-nums', materialMargin.lowStockCount > 0 && 'text-error')}>
                                {materialMargin.lowStockCount} item
                            </dd>
                        </div>
                    </dl>
                    <p className="mb-2 text-xs font-medium text-daiku-muted">Margin terbesar dari pemakaian proyek</p>
                    {materialMargin.topMaterials.length === 0 ? (
                        <p className="text-sm text-daiku-muted">Belum ada pemakaian material tercatat.</p>
                    ) : (
                        <ul className="space-y-1.5 text-sm">
                            {materialMargin.topMaterials.map((material) => (
                                <li key={material.name} className="flex justify-between gap-3">
                                    <span className="truncate">{material.name}</span>
                                    <span className="shrink-0 tabular-nums text-daiku-muted">
                                        {material.qtyUsed} terpakai ·{' '}
                                        <span className="font-medium text-daiku-dark">{formatRupiah(material.margin)}</span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Widget>
            </div>

            <RevenueTargetDialog open={targetOpen} onOpenChange={setTargetOpen} existing={targets} />
        </AppLayout>
    );
}
