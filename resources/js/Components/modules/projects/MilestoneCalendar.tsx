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
    IN_PROGRESS: 'bg-info/15 text-info hover:bg-info/25',
    QA_WAITING: 'bg-warning/15 text-warning hover:bg-warning/25',
    COMPLETED: 'bg-success/15 text-success hover:bg-success/25',
    OVERDUE: 'bg-error/15 text-error hover:bg-error/25',
};

const LEGEND: { status: MilestoneStatus; label: string }[] = [
    { status: 'PENDING', label: 'Belum Mulai' },
    { status: 'IN_PROGRESS', label: 'Berjalan' },
    { status: 'QA_WAITING', label: 'Menunggu QA' },
    { status: 'COMPLETED', label: 'Selesai' },
    { status: 'OVERDUE', label: 'Terlambat' },
];

const WEEKDAY_LABELS = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

function toDay(value: string | Date): Date {
    const date = new Date(value);
    date.setHours(0, 0, 0, 0);

    return date;
}

/** Defaults to the earliest not-yet-COMPLETED milestone's month (the one a PM actually cares about right now), falling back to the first milestone or today if there's nothing sensible. */
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
}

/**
 * "Buat semisal dari tanggal 5-7 ada garis dari 5 sampai ke 7 agar
 * kelihatan dari kapan sampai kapan" — each milestone (after the first)
 * spans from the day *after* the previous milestone's target_date through
 * its own target_date, same phase concept MilestoneGanttCalendar already
 * uses, just re-expressed as day-by-day segments a month grid can render
 * as a continuous bar. Starts a day later than the Gantt version
 * (exclusive, not inclusive) on purpose — that keeps segments from ever
 * sharing a boundary day, so no two bars ever need to stack in the same
 * cell. The first milestone has no predecessor to span from, so it stays
 * a single-day segment on its own target_date.
 */
function buildPhaseSegments(milestones: Milestone[]): PhaseSegment[] {
    const sorted = [...milestones].sort((a, b) => a.order - b.order);

    return sorted.map((milestone, index) => {
        const end = toDay(milestone.target_date);

        if (index === 0) {
            return { milestone, start: end, end };
        }

        const prevEnd = toDay(sorted[index - 1].target_date);
        const start = addDays(prevEnd, 1);

        // Guard against out-of-order target_dates (e.g. a milestone
        // manually re-dated earlier than its predecessor) producing a
        // negative-length segment — collapse to a single day instead.
        return { milestone, start: start > end ? end : start, end };
    });
}

interface MilestoneCalendarProps {
    milestones: Milestone[];
    canManage: boolean;
    onEdit: (milestone: Milestone) => void;
    onDelete: (milestone: Milestone) => void;
    onMarkDone: (milestone: Milestone) => void;
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
export function MilestoneCalendar({ milestones, canManage, onEdit, onDelete, onMarkDone }: MilestoneCalendarProps) {
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
     * One *array* per calendar day (not a single entry) — segments don't
     * overlap by construction (see buildPhaseSegments()'s docblock), but
     * two milestones can still land on the exact same target_date (no DB
     * constraint stops that), which collapses both into single-day
     * segments that do land on the same day. Pushing into an array and
     * stacking every entry, instead of a plain `Map.set()` that would
     * silently overwrite one with the other, is what actually handles
     * that case.
     */
    const barsByDay = useMemo(() => {
        const map = new Map<string, { milestone: Milestone; isStart: boolean; isEnd: boolean }[]>();

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
                map.get(key)!.push(entry);
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
                        <p className="text-xs text-daiku-muted">Milestone selesai</p>
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
                                            {bars.map((bar) => (
                                                <MilestonePhaseBar
                                                    key={bar.milestone.id}
                                                    milestone={bar.milestone}
                                                    isStart={bar.isStart}
                                                    isEnd={bar.isEnd}
                                                    canManage={canManage}
                                                    onEdit={onEdit}
                                                    onDelete={onDelete}
                                                    onMarkDone={onMarkDone}
                                                />
                                            ))}
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
}: {
    milestone: Milestone;
    isStart: boolean;
    isEnd: boolean;
    canManage: boolean;
    onEdit: (milestone: Milestone) => void;
    onDelete: (milestone: Milestone) => void;
    onMarkDone: (milestone: Milestone) => void;
}) {
    const [open, setOpen] = useState(false);
    const canMarkDone = canManage && (milestone.status === 'PENDING' || milestone.status === 'IN_PROGRESS');

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
                    <StatusChip status={milestone.status} />
                </div>
                <p className="text-xs text-daiku-muted">
                    Target: {new Date(milestone.target_date).toLocaleDateString('id-ID', { day: 'numeric', month: 'long', year: 'numeric' })}
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
                            Edit
                        </Button>
                        <Button variant="outline" size="sm" onClick={() => onDelete(milestone)}>
                            <Trash2 className="size-4 text-error" />
                            Hapus
                        </Button>
                    </div>
                )}
            </PopoverContent>
        </Popover>
    );
}
