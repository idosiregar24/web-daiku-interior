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
import type { Termin, TerminStatus } from '@/types';
import { router } from '@inertiajs/react';
import {
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
import { ChevronLeft, ChevronRight, FileDown } from 'lucide-react';
import { useMemo, useState } from 'react';

const DOT_CLASS: Record<TerminStatus, string> = {
    SCHEDULED: 'bg-daiku-muted',
    INVOICED: 'bg-info',
    PAID: 'bg-success',
    OVERDUE: 'bg-error',
};

const CHIP_CLASS: Record<TerminStatus, string> = {
    SCHEDULED: 'bg-daiku-gray text-daiku-muted hover:bg-daiku-border',
    INVOICED: 'bg-info/15 text-info hover:bg-info/25',
    PAID: 'bg-success/15 text-success hover:bg-success/25',
    OVERDUE: 'bg-error/15 text-error hover:bg-error/25',
};

const LEGEND: { status: TerminStatus; label: string }[] = [
    { status: 'SCHEDULED', label: 'Terjadwal' },
    { status: 'INVOICED', label: 'Invoice Terbit' },
    { status: 'PAID', label: 'Sudah Dibayar' },
    { status: 'OVERDUE', label: 'Terlambat' },
];

const WEEKDAY_LABELS = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

function formatRupiah(value: string | number) {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(Number(value));
}

interface TerminCalendarProps {
    termins: Termin[];
    /** 'yyyy-MM' of the month currently being viewed — server-computed (TerminController::index()). */
    month: string;
    canMarkPaid: boolean;
}

/**
 * PRD §8.4 "Layout per Role — Finance: Dashboard cash flow + termin
 * calendar view". Each termin plots on its `scheduled_date` (always a
 * Sabtu, TerminService::getNextSaturday()) as a colored event chip;
 * clicking one opens its detail + actions in a popover rather than
 * navigating away, since the calendar's whole point is staying in one
 * view across a month.
 */
export function TerminCalendar({ termins, month, canMarkPaid }: TerminCalendarProps) {
    const monthDate = useMemo(() => new Date(`${month}-01T00:00:00`), [month]);
    const [selectedDate, setSelectedDate] = useState<Date>(() => new Date());

    function goToMonth(date: Date) {
        router.get(
            route('finance.termins.index'),
            { month: format(date, 'yyyy-MM') },
            { preserveState: true, preserveScroll: true, only: ['calendarTermins', 'calendarMonth'] },
        );
    }

    /** Mini calendar day click — jumps the main grid over if it lands outside the currently visible month. */
    function onSelectDate(date: Date | undefined) {
        if (!date) return;

        setSelectedDate(date);
        if (!isSameMonth(date, monthDate)) {
            goToMonth(date);
        }
    }

    const days = useMemo(() => {
        const gridStart = startOfWeek(startOfMonth(monthDate), { weekStartsOn: 1 });
        const gridEnd = endOfWeek(endOfMonth(monthDate), { weekStartsOn: 1 });

        return eachDayOfInterval({ start: gridStart, end: gridEnd });
    }, [monthDate]);

    const terminsByDay = useMemo(() => {
        const map = new Map<string, Termin[]>();

        for (const termin of termins) {
            const key = termin.scheduled_date.slice(0, 10);
            if (!map.has(key)) map.set(key, []);
            map.get(key)!.push(termin);
        }

        return map;
    }, [termins]);

    const monthTotal = termins
        .filter((termin) => isSameMonth(new Date(termin.scheduled_date), monthDate))
        .reduce((sum, termin) => sum + Number(termin.amount), 0);

    return (
        <div className="grid gap-4 lg:grid-cols-[260px_1fr]">
            <div className="space-y-4">
                <Card className="py-2">
                    <CardContent className="px-2">
                        <Calendar
                            mode="single"
                            month={monthDate}
                            onMonthChange={goToMonth}
                            selected={selectedDate}
                            onSelect={onSelectDate}
                            locale={id}
                            className="w-full p-0"
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="space-y-1 pt-4">
                        <p className="text-xs text-daiku-muted">Total termin bulan ini</p>
                        <p className="text-lg font-semibold text-daiku-dark">{formatRupiah(monthTotal)}</p>
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
                            <Button variant="outline" size="icon-sm" onClick={() => goToMonth(subMonths(monthDate, 1))}>
                                <ChevronLeft className="size-4" />
                            </Button>
                            <Button variant="outline" size="sm" onClick={() => goToMonth(new Date())}>
                                Hari Ini
                            </Button>
                            <Button variant="outline" size="icon-sm" onClick={() => goToMonth(addMonths(monthDate, 1))}>
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
                            const dayTermins = terminsByDay.get(key) ?? [];
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
                                    <div className="mt-1 flex flex-col gap-1">
                                        {dayTermins.map((termin) => (
                                            <TerminEventChip key={termin.id} termin={termin} canMarkPaid={canMarkPaid} />
                                        ))}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}

function TerminEventChip({ termin, canMarkPaid }: { termin: Termin; canMarkPaid: boolean }) {
    const [open, setOpen] = useState(false);

    function onMarkPaid() {
        if (!confirm(`Tandai termin #${termin.termin_number} (${termin.project?.name}) sudah dibayar?`)) return;

        router.post(
            route('finance.termins.markPaid', { termin: termin.id }),
            {},
            { preserveScroll: true, only: ['calendarTermins', 'calendarMonth'], onSuccess: () => setOpen(false) },
        );
    }

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    className={cn(
                        'w-full truncate rounded px-1.5 py-0.5 text-left text-[11px] font-medium transition-colors',
                        CHIP_CLASS[termin.status],
                    )}
                >
                    {termin.project?.name} · #{termin.termin_number}
                </button>
            </PopoverTrigger>
            <PopoverContent className="w-64 space-y-2" align="start">
                <div className="flex items-start justify-between gap-2">
                    <p className="text-sm font-semibold text-daiku-dark">{termin.project?.name}</p>
                    <StatusChip status={termin.status} />
                </div>
                <p className="text-xs text-daiku-muted">
                    Termin #{termin.termin_number} ({termin.percentage}%)
                    {termin.milestone && ` · ${termin.milestone.name}`}
                </p>
                <p className="text-base font-semibold text-daiku-dark">{formatRupiah(termin.amount)}</p>
                <div className="flex items-center gap-2 pt-1">
                    <Button variant="outline" size="sm" asChild>
                        <a href={route('finance.termins.pdf', { termin: termin.id })} target="_blank" rel="noopener noreferrer">
                            <FileDown className="size-4" />
                            PDF
                        </a>
                    </Button>
                    {canMarkPaid && termin.status !== 'PAID' && (
                        <Button size="sm" onClick={onMarkPaid}>
                            Tandai Dibayar
                        </Button>
                    )}
                </div>
            </PopoverContent>
        </Popover>
    );
}
