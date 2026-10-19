import { Calendar } from '@/Components/ui/calendar';
import { Card, CardContent } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/Components/ui/popover';
import { StatusChip } from '@/Components/shared/StatusChip';
import { cn } from '@/lib/utils';
import type { Milestone, MilestoneStatus } from '@/types';
import {
    addDays,
    addMonths,
    eachDayOfInterval,
    endOfMonth,
    endOfWeek,
    format,
    isSameDay,
    isSameMonth,
    isToday,
    startOfMonth,
    startOfWeek,
    subMonths,
} from 'date-fns';
import { id } from 'date-fns/locale';
import { CheckCircle2, ChevronLeft, ChevronRight, Pencil, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

const DOT_CLASS: Record<MilestoneStatus, string> = {
    PENDING: 'bg-daiku-muted',
    IN_PROGRESS: 'bg-info',
    QA_WAITING: 'bg-warning',
    COMPLETED: 'bg-success',
    OVERDUE: 'bg-error',
};

const BAR_CLASS: Record<MilestoneStatus, string> = {
    PENDING: 'bg-daiku-gray text-daiku-muted hover:bg-daiku-border',
    IN_PROGRESS: 'bg-info/15 text-info-ink hover:bg-info/25',
    QA_WAITING: 'bg-warning/15 text-warning-ink hover:bg-warning/25',
    COMPLETED: 'bg-success/15 text-success-ink hover:bg-success/25',
    OVERDUE: 'bg-error/15 text-error-ink hover:bg-error/25',
};

const LEGEND: { status: MilestoneStatus; label: string }[] = [
    { status: 'PENDING', label: 'Belum Mulai' },
    { status: 'IN_PROGRESS', label: 'Dikerjakan' },
    { status: 'QA_WAITING', label: 'Belum Diperiksa Pengawas' },
    { status: 'COMPLETED', label: 'Selesai' },
    { status: 'OVERDUE', label: 'Terlambat' },
];

const WEEKDAY_LABELS = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

function toDay(value: string | Date): Date {
    const date = new Date(value);
    date.setHours(0, 0, 0, 0);

    return date;
}

/** Defaults to the earliest not-yet-COMPLETED timeline's month (the one a PM actually cares about right now), falling back to the first timeline or today if there's nothing sensible. */
function defaultMonth(milestones: Milestone[]): Date {
    const sorted = [...milestones].sort(
        (a, b) => new Date(a.target_date).getTime() - new Date(b.target_date).getTime(),
    );
    const active = sorted.find((m) => m.status !== 'COMPLETED');

    return new Date((active ?? sorted[0])?.target_date ?? new Date());
}

interface PhaseSegment {
    milestone: Milestone;
    start: Date;
    end: Date;
    /** Row inside a day cell — the same for every day of the segment, so its bar reads as one line. */
    lane: number;
}

/**
 * "Buat semisal dari tanggal 5-7 ada garis dari 5 sampai ke 7 agar
 * kelihatan dari kapan sampai kapan" — Sprint 23 K17: each Timeline spans
 * its own `start_date` → `target_date` (Tanggal Selesai). Timelines may
 * overlap, so each segment gets a lane (the lowest one free on its first
 * day) and every day cell renders lanes in order — a bar stays on the
 * same row across the days it covers. A row without a start date (none
 * should be left after the backfill) starts the day after the previous
 * one ends, as before.
 */
function buildPhaseSegments(milestones: Milestone[]): PhaseSegment[] {
    const sorted = [...milestones].sort((a, b) => a.order - b.order);
    const segments = sorted.map((milestone, index) => {
        const end = toDay(milestone.target_date);
        const start = milestone.start_date
            ? toDay(milestone.start_date)
            : index > 0
              ? addDays(toDay(sorted[index - 1].target_date), 1)
              : end;

        // Out-of-order dates collapse to a single day instead of a negative span.
        return { milestone, start: start > end ? end : start, end, lane: 0 };
    });

    const laneEnds: Date[] = [];

    [...segments]
        .sort((a, b) => a.start.getTime() - b.start.getTime() || a.end.getTime() - b.end.getTime())
        .forEach((segment) => {
            const free = laneEnds.findIndex((laneEnd) => laneEnd < segment.start);
            segment.lane = free === -1 ? laneEnds.length : free;
            laneEnds[segment.lane] = segment.end;
        });

    return segments;
}

interface MilestoneCalendarProps {
    milestones: Milestone[];
    canManage: boolean;
    onEdit: (milestone: Milestone) => void;
    onDelete: (milestone: Milestone) => void;
    onMarkDone: (milestone: Milestone) => void;
    /** Sprint 26 H3e — timelines whose latest QC check was rejected ("Perlu Perbaikan"). */
    qcRejected?: ReadonlySet<number>;
}

/**
 * Second view for the Milestone tab ("toggle Gantt/Kalender", requested
 * alongside MilestoneGanttCalendar) — a month-grid plotting each
 * milestone's phase as a bar spanning its date range (see
 * buildPhaseSegments()), not just a single-day dot. Entirely client-side
 * (no server round-trip on month navigation) — unlike TerminCalendar,
 * `milestones` here is already the complete, un-paginated list for one
 * project (loaded once by ProjectController::show()), so there's nothing
 * to re-fetch.
 */
export function MilestoneCalendar({ milestones, canManage, onEdit, onDelete, onMarkDone, qcRejected }: MilestoneCalendarProps) {
    const [monthDate, setMonthDate] = useState(() => defaultMonth(milestones));
    const [selectedDate, setSelectedDate] = useState<Date>(() => defaultMonth(milestones));

    /** Mini calendar day click — jumps the main grid over if it lands outside the currently visible month. */
    function onSelectDate(date: Date | undefined) {
        if (!date) return;

        setSelectedDate(date);
        if (!isSameMonth(date, monthDate)) {
            setMonthDate(date);
        }
    }

    const days = useMemo(() => {
        const gridStart = startOfWeek(startOfMonth(monthDate), { weekStartsOn: 1 });
        const gridEnd = endOfWeek(endOfMonth(monthDate), { weekStartsOn: 1 });

        return eachDayOfInterval({ start: gridStart, end: gridEnd });
    }, [monthDate]);

    const segments = useMemo(() => buildPhaseSegments(milestones), [milestones]);

    /**
     * One array per calendar day, indexed by lane (see buildPhaseSegments())
     * — overlapping timelines stack, and an empty lane keeps a spacer so
     * the bars below it don't jump rows from one day to the next.
     */
    const barsByDay = useMemo(() => {
        const map = new Map<string, ({ milestone: Milestone; isStart: boolean; isEnd: boolean } | undefined)[]>();

        for (const segment of segments) {
            const segmentDays = eachDayOfInterval({ start: segment.start, end: segment.end });

            segmentDays.forEach((day, index) => {
                const key = format(day, 'yyyy-MM-dd');
                const entry = {
                    milestone: segment.milestone,
                    isStart: index === 0,
                    isEnd: index === segmentDays.length - 1,
                };

                if (!map.has(key)) map.set(key, []);
                map.get(key)![segment.lane] = entry;
            });
        }

        return map;
    }, [segments]);

    const completedCount = milestones.filter((m) => m.status === 'COMPLETED').length;

    return (
        <div className="grid gap-4 lg:grid-cols-[260px_1fr]">
            <div className="space-y-4">
                <Card className="py-2">
                    <CardContent className="px-2">
                        <Calendar
                            mode="single"
                            month={monthDate}
                            onMonthChange={setMonthDate}
                            selected={selectedDate}
                            onSelect={onSelectDate}
                            locale={id}
                            className="w-full p-0"
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="space-y-1 pt-4">
                        <p className="text-xs text-daiku-muted">Timeline selesai</p>
                        <p className="text-lg font-semibold text-daiku-dark">
                            {completedCount} / {milestones.length}
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="space-y-2 pt-4">
                        <p className="text-xs font-medium text-daiku-muted">Keterangan</p>
                        {LEGEND.map((entry) => (
                            <span key={entry.status} className="flex items-center gap-2 text-sm text-daiku-dark">
                                <span className={cn('size-2.5 shrink-0 rounded-full', DOT_CLASS[entry.status])} />
                                {entry.label}
                            </span>
                        ))}
                    </CardContent>
                </Card>
            </div>

            <Card className="py-0">
                <CardContent className="p-0">
                    <div className="flex items-center justify-between border-b border-daiku-border p-3">
                        <p className="text-sm font-semibold text-daiku-dark">
                            {format(monthDate, 'MMMM yyyy', { locale: id })}
                        </p>
                        <div className="flex items-center gap-1">
                            <Button variant="outline" size="icon-sm" onClick={() => setMonthDate(subMonths(monthDate, 1))}>
                                <ChevronLeft className="size-4" />
                            </Button>
                            <Button variant="outline" size="sm" onClick={() => setMonthDate(new Date())}>
                                Hari Ini
                            </Button>
                            <Button variant="outline" size="icon-sm" onClick={() => setMonthDate(addMonths(monthDate, 1))}>
                                <ChevronRight className="size-4" />
                            </Button>
                        </div>
                    </div>

                    <div className="grid grid-cols-7 border-b border-daiku-border bg-daiku-yellow-light">
                        {WEEKDAY_LABELS.map((label) => (
                            <div key={label} className="p-2 text-center text-xs font-medium text-daiku-muted">
                                {label}
                            </div>
                        ))}
                    </div>

                    <div className="grid grid-cols-7">
                        {days.map((day) => {
                            const key = format(day, 'yyyy-MM-dd');
                            const bars = barsByDay.get(key) ?? [];
                            const inMonth = isSameMonth(day, monthDate);
                            const today = isToday(day);
                            const selected = isSameDay(day, selectedDate);

                            return (
                                <div
                                    key={key}
                                    onClick={() => setSelectedDate(day)}
                                    className={cn(
                                        'min-h-28 cursor-pointer border-r border-b border-daiku-border p-1.5 last:border-r-0',
                                        !inMonth && 'bg-daiku-gray/50',
                                        selected && 'ring-2 ring-inset ring-daiku-yellow-dark',
                                    )}
                                >
                                    <span
                                        className={cn(
                                            'flex size-6 items-center justify-center rounded-full text-xs',
                                            today ? 'bg-daiku-yellow font-semibold text-daiku-dark' : 'text-daiku-muted',
                                            !inMonth && 'text-daiku-muted/50',
                                        )}
                                    >
                                        {format(day, 'd')}
                                    </span>
                                    {bars.length > 0 && (
                                        <div className="mt-1 flex flex-col gap-1">
                                            {Array.from(bars, (bar, lane) =>
                                                bar ? (
                                                <MilestonePhaseBar
                                                    key={bar.milestone.id}
                                                    milestone={bar.milestone}
                                                    isStart={bar.isStart}
                                                    isEnd={bar.isEnd}
                                                    canManage={canManage}
                                                    onEdit={onEdit}
                                                    onDelete={onDelete}
                                                    onMarkDone={onMarkDone}
                                                    qcRejected={qcRejected}
                                                />
                                                ) : (
                                                    <span key={`lane-${lane}`} className="block h-4" aria-hidden />
                                                ),
                                            )}
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}

/**
 * One day's slice of a milestone's phase bar. Bleeds past the day cell's
 * own horizontal padding (`-mx-1.5` cancelling the cell's `p-1.5`) so
 * adjacent days' slices touch edge-to-edge and read as one continuous bar
 * — rounded only on the actual start/end day, square everywhere it
 * continues into a neighboring cell.
 */
function MilestonePhaseBar({
    milestone,
    isStart,
    isEnd,
    canManage,
    onEdit,
    onDelete,
    onMarkDone,
    qcRejected,
}: {
    milestone: Milestone;
    isStart: boolean;
    isEnd: boolean;
    canManage: boolean;
    onEdit: (milestone: Milestone) => void;
    onDelete: (milestone: Milestone) => void;
    onMarkDone: (milestone: Milestone) => void;
    qcRejected?: ReadonlySet<number>;
}) {
    const [open, setOpen] = useState(false);
    const canMarkDone = canManage && ['PENDING', 'IN_PROGRESS', 'OVERDUE'].includes(milestone.status);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    onClick={(e) => e.stopPropagation()}
                    title={milestone.name}
                    className={cn(
                        '-mx-1.5 block h-4 w-[calc(100%+0.75rem)] truncate px-1.5 text-left text-[10px] leading-4 font-medium transition-colors',
                        BAR_CLASS[milestone.status],
                        isStart && 'rounded-l',
                        isEnd && 'rounded-r',
                    )}
                >
                    {isStart ? milestone.name : ' '}
                </button>
            </PopoverTrigger>
            <PopoverContent className="w-64 space-y-2" align="start">
                <div className="flex items-start justify-between gap-2">
                    <p className="text-sm font-semibold text-daiku-dark">{milestone.name}</p>
                    <span className="flex shrink-0 flex-col items-end gap-1">
                        <StatusChip domain="milestone" status={milestone.status} />
                        {qcRejected?.has(milestone.id) && <StatusChip domain="qa" status="REJECTED" />}
                    </span>
                </div>
                <p className="text-xs text-daiku-muted">
                    {milestone.start_date &&
                        `${new Date(milestone.start_date).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })} – `}
                    {new Date(milestone.target_date).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })}
                </p>
                {canManage && (
                    <div className="flex flex-wrap items-center gap-2 pt-1">
                        {canMarkDone && (
                            <Button size="sm" onClick={() => onMarkDone(milestone)}>
                                <CheckCircle2 className="size-4" />
                                Tandai Selesai
                            </Button>
                        )}
                        <Button variant="outline" size="sm" onClick={() => onEdit(milestone)}>
                            <Pencil className="size-4" />
                            Ubah Timeline
                        </Button>
                        <Button variant="outline" size="sm" onClick={() => onDelete(milestone)}>
                            <Trash2 className="size-4 text-error-ink" />
                            Hapus
                        </Button>
                    </div>
                )}
            </PopoverContent>
        </Popover>
    );
}
