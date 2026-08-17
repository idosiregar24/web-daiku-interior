import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';
import type { Milestone, MilestoneStatus } from '@/types';
import { CheckCircle2, Pencil, Trash2 } from 'lucide-react';

const BAR_CLASS: Record<MilestoneStatus, string> = {
    COMPLETED: 'bg-success',
    IN_PROGRESS: 'bg-info',
    QA_WAITING: 'bg-warning',
    OVERDUE: 'bg-error',
    PENDING: 'bg-daiku-muted/40',
};

const LEGEND: { status: MilestoneStatus; label: string }[] = [
    { status: 'PENDING', label: 'Belum Mulai' },
    { status: 'IN_PROGRESS', label: 'Berjalan' },
    { status: 'QA_WAITING', label: 'Menunggu QA' },
    { status: 'COMPLETED', label: 'Selesai' },
    { status: 'OVERDUE', label: 'Terlambat' },
];

const DAY_MS = 86_400_000;

function toDay(value: string) {
    const date = new Date(value);
    date.setHours(0, 0, 0, 0);

    return date;
}

function diffDays(a: Date, b: Date) {
    return Math.round((a.getTime() - b.getTime()) / DAY_MS);
}

function formatShort(date: Date) {
    return date.toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });
}

function formatMonth(date: Date) {
    return date.toLocaleDateString('id-ID', { month: 'long', year: 'numeric' });
}

interface MilestoneGanttCalendarProps {
    milestones: Milestone[];
    canManage: boolean;
    onEdit: (milestone: Milestone) => void;
    onDelete: (milestone: Milestone) => void;
    onMarkDone: (milestone: Milestone) => void;
}

/**
 * "update untuk bagian milestone, design nya harus modern ala ala gantt
 * chart canggih tampil seperti kalender" — a horizontal Gantt-style
 * timeline plotted against real calendar months, replacing the vertical
 * dot-timeline this project shipped with earlier (git history has it if
 * ever needed again). Each milestone's "phase" spans from the
 * previous milestone's target_date (or the project's start_date for the
 * first) to its own target_date — milestones only carry a single
 * target_date, not their own start_date, so this is the only sequential
 * reading of a duration that doesn't invent a new column.
 *
 * Built as plain HTML/CSS (percentage-based absolute positioning), not a
 * Recharts chart — Recharts has no Gantt/timeline primitive, and every
 * row already carries its own explicit date-range label (not just a
 * color bar), so no separate table view is needed to read the same data.
 */
export function MilestoneGanttCalendar({
    milestones,
    canManage,
    onEdit,
    onDelete,
    onMarkDone,
}: MilestoneGanttCalendarProps) {
    const sorted = [...milestones].sort((a, b) => a.order - b.order);
    const targetDates = sorted.map((m) => toDay(m.target_date));

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const earliest = new Date(Math.min(...targetDates.map((d) => d.getTime()), today.getTime()));
    const latest = new Date(Math.max(...targetDates.map((d) => d.getTime()), today.getTime()));

    // Pad both ends by a few days so bars/markers never sit flush on the edge.
    const rangeStart = new Date(earliest.getTime() - 5 * DAY_MS);
    const rangeEnd = new Date(latest.getTime() + 5 * DAY_MS);
    const totalDays = Math.max(diffDays(rangeEnd, rangeStart), 1);

    const pct = (date: Date) => (diffDays(date, rangeStart) / totalDays) * 100;

    const months: Date[] = [];
    const cursor = new Date(rangeStart.getFullYear(), rangeStart.getMonth(), 1);
    while (cursor <= rangeEnd) {
        months.push(new Date(cursor));
        cursor.setMonth(cursor.getMonth() + 1);
    }

    const todayPct = today >= rangeStart && today <= rangeEnd ? pct(today) : null;

    return (
        <div className="rounded-lg border border-daiku-border bg-white p-4">
            <div className="mb-4 flex flex-wrap items-center gap-4 text-xs text-daiku-muted">
                {LEGEND.map((entry) => (
                    <span key={entry.status} className="flex items-center gap-1.5">
                        <span className={cn('size-2.5 rounded-full', BAR_CLASS[entry.status])} />
                        {entry.label}
                    </span>
                ))}
                {todayPct !== null && (
                    <span className="ml-auto flex items-center gap-1.5">
                        <span className="h-2.5 w-0.5 bg-daiku-dark" />
                        Hari ini
                    </span>
                )}
            </div>

            <div className="overflow-x-auto">
                <div style={{ minWidth: `${Math.max(months.length * 140, 480)}px` }}>
                    {/* Month header */}
                    <div className="relative h-6 border-b border-daiku-border">
                        {months.map((month) => (
                            <span
                                key={month.toISOString()}
                                className="absolute top-0 text-[11px] font-medium whitespace-nowrap text-daiku-muted"
                                style={{ left: `${pct(month)}%` }}
                            >
                                {formatMonth(month)}
                            </span>
                        ))}
                    </div>

                    {/* Rows */}
                    <div className="relative py-2">
                        {todayPct !== null && (
                            <div
                                className="absolute top-0 bottom-0 w-px bg-daiku-dark/60"
                                style={{ left: `${todayPct}%` }}
                                aria-hidden
                            />
                        )}

                        <div className="flex flex-col gap-2">
                            {sorted.map((milestone, index) => {
                                const phaseStart =
                                    index === 0 ? rangeStart : toDay(sorted[index - 1].target_date);
                                const phaseEnd = toDay(milestone.target_date);
                                const left = pct(phaseStart);
                                const width = Math.max(pct(phaseEnd) - left, 2);
                                const canMarkDone =
                                    canManage &&
                                    (milestone.status === 'PENDING' || milestone.status === 'IN_PROGRESS');

                                return (
                                    <div key={milestone.id} className="group flex items-center gap-3">
                                        <div className="w-40 shrink-0 truncate text-sm font-medium text-daiku-dark" title={milestone.name}>
                                            {index + 1}. {milestone.name}
                                        </div>
                                        <div className="relative h-8 min-w-0 flex-1">
                                            <div
                                                className={cn(
                                                    'absolute top-1 flex h-6 items-center gap-1.5 rounded-full px-3 text-[11px] font-medium whitespace-nowrap text-white shadow-sm transition-transform group-hover:scale-[1.02]',
                                                    BAR_CLASS[milestone.status],
                                                    milestone.status === 'PENDING' && 'text-daiku-dark',
                                                )}
                                                style={{ left: `${left}%`, width: `${width}%` }}
                                                title={`${formatShort(phaseStart)} — ${formatShort(phaseEnd)}`}
                                            >
                                                {milestone.status === 'COMPLETED' && <CheckCircle2 className="size-3.5 shrink-0" />}
                                                <span className="truncate">{formatShort(phaseEnd)}</span>
                                            </div>
                                        </div>
                                        <div className="flex shrink-0 items-center gap-1">
                                            <StatusChip status={milestone.status} />
                                            {canMarkDone && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    className="h-7 px-2 text-xs"
                                                    onClick={() => onMarkDone(milestone)}
                                                >
                                                    Tandai Selesai
                                                </Button>
                                            )}
                                            {canManage && (
                                                <>
                                                    <Button variant="ghost" size="icon-sm" onClick={() => onEdit(milestone)}>
                                                        <Pencil className="size-4" />
                                                    </Button>
                                                    <Button variant="ghost" size="icon-sm" onClick={() => onDelete(milestone)}>
                                                        <Trash2 className="size-4 text-error" />
                                                    </Button>
                                                </>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}
