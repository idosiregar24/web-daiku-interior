import { dueLabel, FLUSH_TABLE_CLASS, lateLabel, waitingLabel } from '@/Components/modules/dashboards/dashboardFormat';
import { SectionLink } from '@/Components/modules/dashboards/SectionLink';
import { DataTable } from '@/Components/shared/DataTable';
import { EmptyState } from '@/Components/shared/EmptyState';
import { PageHeader } from '@/Components/shared/PageHeader';
import { ProgressBar } from '@/Components/shared/ProgressBar';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatCard } from '@/Components/shared/StatCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate, formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import type {
    AttentionMilestone,
    DueTaskRow,
    MonitorProject,
    MonitorStats,
    OverdueProjectGroup,
    PendingOvertimeRow,
    User,
} from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { AlertTriangle, ArrowLeft, CalendarClock, CalendarX2, Clock, Flag, FolderKanban, TrendingUp } from 'lucide-react';

interface ProjectDashboardProps {
    stats: MonitorStats;
    projects: MonitorProject[];
    overdue: OverdueProjectGroup[];
    dueSoon: DueTaskRow[];
    milestones: AttentionMilestone[];
    pendingOvertime: PendingOvertimeRow[];
    filters: { pm_id: number | null };
    /** CEO only — empty for a PM (who only ever sees their own projects). */
    pmOptions: Pick<User, 'id' | 'name'>[];
}

const MILESTONE_REASON: Record<AttentionMilestone['reason'], { label: string; tone: 'error' | 'warning' | 'info' }> = {
    OVERDUE: { label: 'Lewat target', tone: 'error' },
    QA_WAITING: { label: 'Menunggu QA', tone: 'warning' },
    DUE_SOON: { label: 'Target ≤ 7 hari', tone: 'info' },
};

const projectColumns: ColumnDef<MonitorProject>[] = [
    {
        accessorKey: 'name',
        header: 'Proyek',
        cell: ({ row }) => (
            <div className="min-w-0">
                <Link
                    href={route('projects.show', { project: row.original.id })}
                    className="font-medium text-foreground hover:underline"
                >
                    {row.original.name}
                </Link>
                {row.original.client && <span className="block text-xs text-muted-foreground">{row.original.client}</span>}
            </div>
        ),
    },
    { accessorKey: 'status', header: 'Status', cell: ({ row }) => <StatusChip status={row.original.status} /> },
    { accessorKey: 'pm', header: 'PM', cell: ({ row }) => row.original.pm ?? '—' },
    {
        accessorKey: 'progress',
        header: 'Progres',
        cell: ({ row }) =>
            row.original.progress === null ? (
                <span className="text-xs text-muted-foreground">Belum ada log</span>
            ) : (
                <div className="min-w-36">
                    <ProgressBar value={row.original.progress} label={`Progres ${row.original.name}`} showValue />
                    <span className="text-xs text-muted-foreground">Log {formatDate(row.original.progressDate)}</span>
                </div>
            ),
    },
    {
        accessorKey: 'milestonesCompleted',
        header: 'Milestone',
        cell: ({ row }) => (
            <span className="tabular-nums">
                {row.original.milestonesCompleted}/{row.original.milestonesTotal}
            </span>
        ),
    },
    { accessorKey: 'openTasks', header: 'Task Terbuka', cell: ({ row }) => <span className="tabular-nums">{row.original.openTasks}</span> },
    {
        accessorKey: 'overdueTasks',
        header: 'Terlambat',
        cell: ({ row }) => (
            <span className={cn('tabular-nums', row.original.overdueTasks > 0 && 'font-medium text-error-ink')}>
                {row.original.overdueTasks}
            </span>
        ),
    },
];

/**
 * Monitor Proyek — PRD §4.4 "Overdue Monitor: Dashboard khusus PM untuk
 * memantau task yang melewati deadline". A PM sees their own ACTIVE /
 * ON_HOLD projects, the CEO every PM's (filterable). Lateness is only
 * flagged on ACTIVE projects — ON_HOLD is paused (Sprint 9 decision #1).
 */
export default function ProjectDashboard({
    stats,
    projects,
    overdue,
    dueSoon,
    milestones,
    pendingOvertime,
    filters,
    pmOptions,
}: ProjectDashboardProps) {
    function filterPm(value: string) {
        router.get(route('projects.dashboard'), value === 'all' ? {} : { pm_id: value }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout breadcrumbs={[{ label: 'Monitor Proyek' }]}>
            <Head title="Monitor Proyek" />

            <PageHeader
                title="Monitor Proyek"
                icon={FolderKanban}
                description="Task terlambat, jatuh tempo minggu ini, milestone, dan lembur yang menunggu keputusan."
                actions={
                    <>
                        {pmOptions.length > 0 && (
                            <Select value={filters.pm_id ? String(filters.pm_id) : 'all'} onValueChange={filterPm}>
                                <SelectTrigger className="w-48" aria-label="Filter PM">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Semua PM</SelectItem>
                                    {pmOptions.map((pm) => (
                                        <SelectItem key={pm.id} value={String(pm.id)}>
                                            {pm.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                        <Button variant="outline" asChild>
                            <Link href={route('projects.index')}>
                                <ArrowLeft className="size-4" />
                                Daftar Proyek
                            </Link>
                        </Button>
                    </>
                }
            />

            <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Proyek Dipantau"
                    value={stats.projects}
                    icon={FolderKanban}
                    hint={stats.onHold > 0 ? `${stats.onHold} sedang ditahan (On Hold)` : 'Semua aktif'}
                />
                <StatCard
                    label="Task Terlambat"
                    value={stats.overdueTasks}
                    icon={AlertTriangle}
                    tone={stats.overdueTasks > 0 ? 'error' : 'default'}
                    hint={stats.overdueTasks > 0 ? `di ${overdue.length} proyek` : 'Semua sesuai jadwal'}
                />
                <StatCard
                    label="Jatuh Tempo Minggu Ini"
                    value={stats.dueThisWeek}
                    icon={CalendarClock}
                    hint={`${stats.dueToday} hari ini`}
                />
                <StatCard
                    label="Lembur Menunggu"
                    value={stats.pendingOvertime}
                    icon={Clock}
                    tone={stats.pendingOvertime > 0 ? 'warning' : 'default'}
                    hint={`${stats.qaWaiting} milestone menunggu QA`}
                />
            </div>

            <SectionCard
                title="Task Terlambat"
                icon={CalendarX2}
                description="Per proyek, lalu per tukang — keterlambatan terbesar di atas."
                flush
                className="mb-6"
                action={<SectionLink href={route('tasks.index')}>Semua task</SectionLink>}
            >
                {overdue.length === 0 ? (
                    <EmptyState icon={CalendarX2} title="Tidak ada task yang melewati deadline." />
                ) : (
                    <div className="divide-y divide-border">
                        {overdue.map((group) => (
                            <div key={group.projectId} className="px-4 py-4 sm:px-5">
                                <div className="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                                    <Link
                                        href={route('projects.show', { project: group.projectId })}
                                        className="font-medium text-foreground hover:underline"
                                    >
                                        {group.projectName}
                                        {group.client && <span className="ml-1.5 text-xs font-normal text-muted-foreground">{group.client}</span>}
                                    </Link>
                                    <span className="text-xs font-medium text-error-ink">
                                        {group.total} task · terlama {group.maxDaysLate} hari
                                    </span>
                                </div>
                                <div className="space-y-3">
                                    {group.assignees.map((assignee) => (
                                        <div key={assignee.id} className="rounded-lg bg-daiku-gray/60 px-3 py-2.5">
                                            <p className="mb-1.5 text-xs font-semibold text-foreground">{assignee.name}</p>
                                            <ul className="space-y-1.5">
                                                {assignee.tasks.map((task) => (
                                                    <li key={task.id} className="flex items-start justify-between gap-3 text-sm">
                                                        <div className="min-w-0">
                                                            <p className="truncate">{task.title}</p>
                                                            <p className="text-xs text-muted-foreground">
                                                                {task.milestone ? `${task.milestone} · ` : ''}deadline {formatDate(task.dueDate)}
                                                            </p>
                                                        </div>
                                                        <StatusChip status="OVER" label={lateLabel(task.daysLate)} className="shrink-0" />
                                                    </li>
                                                ))}
                                            </ul>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </SectionCard>

            <div className="mb-6 grid gap-6 xl:grid-cols-2">
                <SectionCard title="Jatuh Tempo Minggu Ini" icon={CalendarClock} description="Task belum selesai, hari ini s/d Minggu." flush>
                    {dueSoon.length === 0 ? (
                        <EmptyState icon={CalendarClock} title="Tidak ada task jatuh tempo minggu ini." />
                    ) : (
                        <ul className="divide-y divide-border">
                            {dueSoon.map((task) => (
                                <li key={task.id} className="flex items-start justify-between gap-3 px-4 py-3 sm:px-5">
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium">{task.title}</p>
                                        <p className="text-xs text-muted-foreground">
                                            <Link href={route('projects.show', { project: task.projectId })} className="hover:underline">
                                                {task.projectName}
                                            </Link>
                                            {' · '}
                                            {task.assignee ?? '—'}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 flex-col items-end gap-1">
                                        <StatusChip status={task.status} />
                                        <span className={cn('text-xs', task.isToday ? 'font-medium text-warning-ink' : 'text-muted-foreground')}>
                                            {task.isToday ? 'Hari ini' : formatDate(task.dueDate)}
                                        </span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>

                <SectionCard
                    title="Milestone Perlu Perhatian"
                    icon={Flag}
                    description="Lewat target, menunggu QA, atau target dalam 7 hari."
                    flush
                >
                    {milestones.length === 0 ? (
                        <EmptyState icon={Flag} title="Tidak ada milestone yang perlu perhatian." />
                    ) : (
                        <ul className="divide-y divide-border">
                            {milestones.map((milestone) => (
                                <li key={milestone.id} className="flex items-start justify-between gap-3 px-4 py-3 sm:px-5">
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium">{milestone.name}</p>
                                        <p className="text-xs text-muted-foreground">
                                            <Link href={route('projects.show', { project: milestone.projectId })} className="hover:underline">
                                                {milestone.projectName}
                                            </Link>
                                            {' · target '}
                                            {formatDate(milestone.targetDate)} ({dueLabel(milestone.daysLeft)})
                                        </p>
                                    </div>
                                    <StatusChip
                                        status={milestone.reason}
                                        tone={MILESTONE_REASON[milestone.reason].tone}
                                        label={MILESTONE_REASON[milestone.reason].label}
                                        className="shrink-0"
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            </div>

            <SectionCard
                title="Lembur Menunggu Persetujuan PM"
                icon={Clock}
                flush
                className="mb-6"
                action={<SectionLink href={route('overtime.index', { status: 'PENDING' })}>Proses lembur</SectionLink>}
            >
                {pendingOvertime.length === 0 ? (
                    <EmptyState icon={Clock} title="Tidak ada pengajuan lembur yang menunggu." />
                ) : (
                    <ul className="divide-y divide-border">
                        {pendingOvertime.map((request) => (
                            <li key={request.id} className="flex items-start justify-between gap-3 px-4 py-3 sm:px-5">
                                <div className="min-w-0">
                                    <p className="text-sm font-medium">
                                        {request.staff ?? '—'} · {request.hours.toLocaleString('id-ID')} jam
                                    </p>
                                    <p className="truncate text-xs text-muted-foreground">
                                        {request.projectName} · {formatDate(request.workDate)} · {request.reason}
                                    </p>
                                </div>
                                <div className="shrink-0 text-right">
                                    <p className="text-sm font-medium tabular-nums">{formatRupiah(request.totalAmount)}</p>
                                    <p className="text-xs text-muted-foreground">{waitingLabel(request.daysWaiting)}</p>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </SectionCard>

            <SectionCard
                title="Progres Proyek"
                icon={TrendingUp}
                description="Progres terakhir dari Progress Log, milestone lolos QA, dan task terlambat."
                flush
            >
                <DataTable
                    columns={projectColumns}
                    data={projects}
                    emptyMessage="Belum ada proyek aktif atau ditahan."
                    className={FLUSH_TABLE_CLASS}
                />
            </SectionCard>
        </AppLayout>
    );
}
