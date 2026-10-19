import { EmptyState } from '@/Components/shared/EmptyState';
import { SectionCard } from '@/Components/shared/SectionCard';
import { TABLE_HEAD_CLASS, TableCard } from '@/Components/shared/TableCard';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { cn } from '@/lib/utils';
import type { Task } from '@/types';
import { CheckCircle2, ChevronDown, ChevronRight, Circle, CircleAlert, Clock, ListChecks, RefreshCw } from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';
import { asDay, groupTaskTargets, type TaskTargetRow, type TaskTargetState, taskTargetRows, todayWib } from './taskTargetRows';

const DAY_LABEL = new Intl.DateTimeFormat('id-ID', { weekday: 'short', day: 'numeric', month: 'short' });
const SHORT_DATE = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short' });

type StateFilter = 'all' | 'pending' | 'done' | 'rolled' | 'over';

const STATE_FILTERS: { value: StateFilter; label: string }[] = [
    { value: 'all', label: 'Semua status' },
    { value: 'pending', label: 'Belum selesai' },
    { value: 'done', label: 'Selesai' },
    { value: 'rolled', label: 'Bergulir' },
    { value: 'over', label: 'Terlambat' },
];

function matches(row: TaskTargetRow, filter: StateFilter): boolean {
    switch (filter) {
        case 'all':
            return true;
        case 'pending':
            return row.state !== 'done';
        case 'rolled':
            return row.rolled > 0;
        default:
            return row.state === filter;
    }
}

function weekLabel(start: string | null): string {
    if (!start) return 'Tanpa tanggal';
    const end = asDay(start);
    end.setDate(end.getDate() + 6);

    return `Minggu ${SHORT_DATE.format(asDay(start))} – ${SHORT_DATE.format(end)} ${end.getFullYear()}`;
}

const STATE_ICON: Record<TaskTargetState, typeof Circle> = {
    done: CheckCircle2,
    over: CircleAlert,
    rolled: RefreshCw,
    pending: Circle,
    upcoming: Clock,
};

const STATE_INK: Record<TaskTargetState, string> = {
    done: 'text-success-ink',
    over: 'text-error-ink',
    rolled: 'text-warning-ink',
    pending: 'text-warning-ink',
    upcoming: 'text-muted-foreground',
};

/** Left column — what was planned. */
function TargetCell({ row, showDay = false }: { row: TaskTargetRow; showDay?: boolean }) {
    return (
        <div className="min-w-0">
            <p className="font-medium break-words text-foreground">{row.task.title}</p>
            <p className="mt-0.5 text-xs text-muted-foreground">
                {row.task.assignee?.name ?? 'Belum ditugaskan'}
                {row.task.milestone?.name && ` · ${row.task.milestone.name}`}
                {showDay && row.targetOn && ` · target ${DAY_LABEL.format(asDay(row.targetOn))}`}
            </p>
        </div>
    );
}

/** Right column — what actually happened. */
function ActualCell({ row, today }: { row: TaskTargetRow; today: string }) {
    const Icon = STATE_ICON[row.state];
    let text: string;
    let note: string | null = null;

    switch (row.state) {
        case 'done':
            text = `Selesai ${row.doneOn ? DAY_LABEL.format(asDay(row.doneOn)) : ''}${row.doneAt ? `, ${row.doneAt}` : ''}`;
            note =
                row.daysLate === null || row.daysLate === 0
                    ? 'tepat hari target'
                    : row.daysLate < 0
                      ? `lebih cepat ${-row.daysLate} hari`
                      : `terlambat ${row.daysLate} hari`;
            break;
        case 'over':
            text = 'Terlambat — lewat timeline';
            break;
        case 'rolled':
            text = `Bergulir ×${row.rolled}${row.task.due_date ? ` → sekarang ${DAY_LABEL.format(asDay(row.task.due_date))}` : ''}`;
            break;
        case 'upcoming':
            text = 'Belum waktunya';
            break;
        default:
            text = row.targetOn === today ? 'Hari ini — belum selesai' : 'Belum selesai';
    }

    return (
        <div className="min-w-0">
            <p className={cn('flex items-start gap-1.5 font-medium', STATE_INK[row.state])}>
                <Icon className="mt-0.5 size-4 shrink-0" aria-hidden />
                <span>{text}</span>
            </p>
            {(note || (row.state !== 'done' && row.rolled > 0 && row.state !== 'rolled')) && (
                <p className="mt-0.5 pl-5.5 text-xs text-muted-foreground">
                    {note ?? `↻ bergulir ×${row.rolled}`}
                    {note && row.rolled > 0 && ` · ↻ bergulir ×${row.rolled}`}
                </p>
            )}
            {row.task.kendala && <p className="mt-0.5 pl-5.5 text-xs break-words text-warning-ink">Kendala: {row.task.kendala}</p>}
        </div>
    );
}

/**
 * Sprint 27 P5 — the whiteboard note under "target – Aktivity / Actual":
 * one row per daily target, the target on the left (task, tukang,
 * timeline) and the actual on the right (finished when / rolled / late).
 * Built from the page's `tasks` prop, so only viewers who already see the
 * project's tasks get it (CEO, PM, Asisten PM, the project's Pengawas).
 */
export function TaskTargetTable({ tasks }: { tasks: Task[] }) {
    const today = todayWib();
    const [assignee, setAssignee] = useState('all');
    const [stateFilter, setStateFilter] = useState<StateFilter>('all');

    const rows = useMemo(() => taskTargetRows(tasks, today), [tasks, today]);
    const people = useMemo(() => {
        const names = new Map<string, string>();
        for (const row of rows) names.set(row.task.assignee_id ? String(row.task.assignee_id) : 'none', row.task.assignee?.name ?? 'Belum ditugaskan');

        return [...names.entries()].sort((a, b) => a[1].localeCompare(b[1]));
    }, [rows]);
    const weeks = useMemo(
        () =>
            groupTaskTargets(
                rows.filter(
                    (row) => (assignee === 'all' || String(row.task.assignee_id ?? 'none') === assignee) && matches(row, stateFilter),
                ),
            ),
        [rows, assignee, stateFilter],
    );

    const currentWeek = weeks.find((week) => week.start && week.start <= today && today < nextWeek(week.start)) ?? weeks.find((week) => week.start);
    const [open, setOpen] = useState<Set<string>>(() => new Set(currentWeek?.start ? [currentWeek.start] : []));
    const isOpen = (start: string | null) => open.has(start ?? '');

    function toggle(start: string | null) {
        const key = start ?? '';
        setOpen((previous) => {
            const next = new Set(previous);
            if (next.has(key)) next.delete(key);
            else next.add(key);

            return next;
        });
    }

    return (
        <SectionCard
            title="Target vs Actual per Task"
            description="Kiri: daily target yang direncanakan (hari target tidak ikut bergulir). Kanan: actual — kapan selesai, bergulir, atau terlambat."
            icon={ListChecks}
            flush
            action={
                rows.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        <Select value={assignee} onValueChange={setAssignee}>
                            <SelectTrigger size="sm" className="w-40" aria-label="Saring tukang">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua tukang</SelectItem>
                                {people.map(([id, name]) => (
                                    <SelectItem key={id} value={id}>
                                        {name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select value={stateFilter} onValueChange={(value) => setStateFilter(value as StateFilter)}>
                            <SelectTrigger size="sm" className="w-36" aria-label="Saring status">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {STATE_FILTERS.map((option) => (
                                    <SelectItem key={option.value} value={option.value}>
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                )
            }
        >
            {rows.length === 0 ? (
                <EmptyState title="Belum ada daily target — dibuat Pengawas setelah Technical Meeting." />
            ) : weeks.length === 0 ? (
                <EmptyState title="Tidak ada daily target yang cocok dengan saringan." />
            ) : (
                <>
                    {/* Phone — one card per target, Target | Actual side by side as on the board. */}
                    <ul className="divide-y divide-border md:hidden">
                        {weeks.map((week) => (
                            <li key={week.start ?? 'none'}>
                                <WeekToggle label={weekLabel(week.start)} done={week.done} total={week.total} open={isOpen(week.start)} onToggle={() => toggle(week.start)} />
                                {isOpen(week.start) && (
                                    <ul className="divide-y divide-border bg-daiku-gray/40">
                                        {week.days.flatMap((day) =>
                                            day.rows.map((row) => (
                                                <li key={row.task.id} className={cn('grid grid-cols-2 gap-3 px-4 py-3 text-sm', day.date === today && 'bg-daiku-yellow-light/60')}>
                                                    <TargetCell row={row} showDay />
                                                    <ActualCell row={row} today={today} />
                                                </li>
                                            )),
                                        )}
                                    </ul>
                                )}
                            </li>
                        ))}
                    </ul>

                    <TableCard className="hidden rounded-none border-0 shadow-none ring-0 md:block">
                        <table className="w-full table-fixed text-sm">
                            <thead className={TABLE_HEAD_CLASS}>
                                <tr>
                                    <th className="w-32 px-4 py-2 text-left">Hari Target</th>
                                    <th className="px-3 py-2 text-left">Daily Target</th>
                                    <th className="px-4 py-2 text-left">Actual</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {weeks.map((week) => (
                                    <Fragment key={week.start ?? 'none'}>
                                        <tr className="bg-daiku-gray/60">
                                            <td colSpan={3} className="px-4 py-2">
                                                <button
                                                    type="button"
                                                    onClick={() => toggle(week.start)}
                                                    aria-expanded={isOpen(week.start)}
                                                    className="flex w-full items-center gap-1.5 text-left font-medium text-foreground hover:underline"
                                                >
                                                    {isOpen(week.start) ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                                                    <span className="flex-1">{weekLabel(week.start)}</span>
                                                    <span className="text-xs font-normal text-muted-foreground tabular-nums">
                                                        {week.done}/{week.total} selesai
                                                    </span>
                                                </button>
                                            </td>
                                        </tr>
                                        {isOpen(week.start) &&
                                            week.days.map((day) =>
                                                day.rows.map((row, index) => (
                                                    <tr key={row.task.id} className={cn('align-top', day.date === today && 'bg-daiku-yellow-light/60')}>
                                                        <td className="py-2.5 pr-3 pl-10 text-muted-foreground">
                                                            {index === 0 && day.date && (
                                                                <>
                                                                    <span className="font-medium text-foreground">{DAY_LABEL.format(asDay(day.date))}</span>
                                                                    {day.date === today && <span className="block text-xs text-daiku-muted">Hari ini</span>}
                                                                </>
                                                            )}
                                                        </td>
                                                        <td className="px-3 py-2.5">
                                                            <TargetCell row={row} />
                                                        </td>
                                                        <td className="px-4 py-2.5">
                                                            <ActualCell row={row} today={today} />
                                                        </td>
                                                    </tr>
                                                )),
                                            )}
                                    </Fragment>
                                ))}
                            </tbody>
                        </table>
                    </TableCard>
                </>
            )}
        </SectionCard>
    );
}

function nextWeek(start: string): string {
    const date = asDay(start);
    date.setDate(date.getDate() + 7);

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function WeekToggle({ label, done, total, open, onToggle }: { label: string; done: number; total: number; open: boolean; onToggle: () => void }) {
    return (
        <button
            type="button"
            onClick={onToggle}
            aria-expanded={open}
            className="flex min-h-12 w-full items-center gap-2 px-4 py-3 text-left text-sm hover:bg-daiku-yellow-light/40"
        >
            {open ? <ChevronDown className="size-4 shrink-0" /> : <ChevronRight className="size-4 shrink-0" />}
            <span className="flex-1 font-medium text-foreground">{label}</span>
            <span className="text-xs text-muted-foreground tabular-nums">
                {done}/{total} selesai
            </span>
        </button>
    );
}
