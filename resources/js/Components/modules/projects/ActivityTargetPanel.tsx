import { EmptyState } from '@/Components/shared/EmptyState';
import { ProgressBar } from '@/Components/shared/ProgressBar';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatCard } from '@/Components/shared/StatCard';
import { TABLE_HEAD_CLASS, TableCard } from '@/Components/shared/TableCard';
import { cn } from '@/lib/utils';
import type { ActivityTarget, ActivityTargetDay, ActivityTargetWeek } from '@/types';
import { Activity, CalendarCheck, ChevronDown, ChevronRight, RefreshCw, Scale, Table2 } from 'lucide-react';
import { Fragment, useState } from 'react';

const DAY_LABEL = new Intl.DateTimeFormat('id-ID', { weekday: 'short', day: 'numeric', month: 'short' });
const SHORT_DATE = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short' });

const asDate = (day: string) => new Date(`${day}T00:00:00`);

function weekLabel(week: ActivityTargetWeek): string {
    return `${SHORT_DATE.format(asDate(week.start))} – ${SHORT_DATE.format(asDate(week.end))} ${asDate(week.end).getFullYear()}`;
}

/** Actual − target for a day/week; null while it is still ahead. */
function gapOf(row: Pick<ActivityTargetDay, 'target' | 'actual'>): number | null {
    return row.actual === null ? null : row.actual - row.target;
}

function GapValue({ gap }: { gap: number | null }) {
    if (gap === null) {
        return <span className="text-muted-foreground/50">—</span>;
    }

    return <span className={cn(gap > 0 && 'text-success-ink', gap < 0 && 'text-error-ink')}>{gap > 0 ? `+${gap}` : gap}</span>;
}

/** Cumulative actual vs cumulative target — behind schedule turns red. */
function CumulativeActual({ row }: { row: Pick<ActivityTargetDay, 'cum_target' | 'cum_actual'> }) {
    if (row.cum_actual === null) {
        return <span className="text-muted-foreground/50">—</span>;
    }

    return <span className={cn('font-medium', row.cum_actual >= row.cum_target ? 'text-success-ink' : 'text-error-ink')}>{row.cum_actual}%</span>;
}

/**
 * Sprint 25 — tab "Activity Target": the project's daily targets against
 * what was actually finished, per working day (grouped per week), with
 * both cumulated over every target — the last actual figure is the
 * project's progress. Numbers only (ProductionService::activityTarget()).
 */
export function ActivityTargetPanel({ data }: { data: ActivityTarget }) {
    const { summary } = data;
    const currentWeek = data.weeks.find((week) => week.start <= data.today && data.today <= week.end) ?? data.weeks[data.weeks.length - 1];
    const [open, setOpen] = useState<Set<string>>(() => new Set(currentWeek ? [currentWeek.start] : []));

    function toggle(start: string) {
        setOpen((previous) => {
            const next = new Set(previous);
            if (next.has(start)) {
                next.delete(start);
            } else {
                next.add(start);
            }

            return next;
        });
    }

    return (
        <div className="space-y-6">
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Progress Proyek" value={`${summary.progress}%`} icon={Activity} hint={`${summary.done}/${summary.total} daily target selesai`}>
                    <ProgressBar value={summary.progress} label="Progress proyek dari daily target yang selesai" />
                </StatCard>
                <StatCard
                    label="Target s.d. Hari Ini"
                    value={`${summary.target_to_date}%`}
                    icon={CalendarCheck}
                    hint={`${summary.planned_to_date} target dijadwalkan sampai hari ini`}
                />
                <StatCard
                    label="Selisih"
                    value={`${summary.gap > 0 ? '+' : ''}${summary.gap} poin`}
                    icon={Scale}
                    tone={summary.total === 0 ? 'default' : summary.gap >= 0 ? 'success' : 'error'}
                    hint={summary.total === 0 ? 'Belum ada daily target' : summary.gap >= 0 ? 'Sesuai atau di depan jadwal' : 'Tertinggal dari jadwal'}
                />
                <StatCard
                    label="Bergulir"
                    value={summary.rolled}
                    icon={RefreshCw}
                    tone={summary.rolled > 0 ? 'warning' : 'default'}
                    hint="Kali target belum selesai pindah ke hari berikutnya"
                />
            </div>

            <SectionCard
                title="Daily Target vs Actual"
                description="Per hari kerja. Daily Target = target yang direncanakan hari itu, Actual = target yang selesai hari itu. Kumulatif dihitung terhadap semua daily target proyek."
                icon={Table2}
                flush
            >
                {data.weeks.length === 0 ? (
                    <EmptyState title="Belum ada daily target — dibuat Pengawas setelah Technical Meeting." />
                ) : (
                    <>
                        <ul className="divide-y divide-border md:hidden">
                            {data.weeks.map((week) => (
                                <li key={week.start}>
                                    <WeekToggle week={week} open={open.has(week.start)} onToggle={() => toggle(week.start)} />
                                    {open.has(week.start) && (
                                        <ul className="divide-y divide-border bg-daiku-gray/40">
                                            {week.days.map((day) => (
                                                <li key={day.date} className={cn('px-4 py-3 text-sm', day.date === data.today && 'bg-daiku-yellow-light/60')}>
                                                    <div className="flex items-center justify-between gap-2">
                                                        <span className="font-medium text-foreground">
                                                            {DAY_LABEL.format(asDate(day.date))}
                                                            {day.date === data.today && <span className="ml-2 text-xs text-daiku-muted">Hari ini</span>}
                                                        </span>
                                                        <span className="tabular-nums">
                                                            {day.actual ?? '—'}/{day.target} <GapValue gap={gapOf(day)} />
                                                        </span>
                                                    </div>
                                                    <p className="mt-1 text-xs text-muted-foreground tabular-nums">
                                                        Kumulatif target {day.cum_target}% · actual {day.cum_actual === null ? '—' : `${day.cum_actual}%`}
                                                        {day.rolled > 0 && <span className="ml-1 text-warning-ink">· ↻{day.rolled}</span>}
                                                    </p>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </li>
                            ))}
                        </ul>

                        <TableCard className="hidden rounded-none border-0 shadow-none ring-0 md:block">
                            <table className="w-full text-sm">
                                <thead className={TABLE_HEAD_CLASS}>
                                    <tr>
                                        <th className="px-4 py-2 text-left">Tanggal</th>
                                        <th className="px-3 py-2 text-right">Daily Target</th>
                                        <th className="px-3 py-2 text-right">Actual</th>
                                        <th className="px-3 py-2 text-right">Selisih</th>
                                        <th className="px-3 py-2 text-right">↻ Bergulir</th>
                                        <th className="px-3 py-2 text-right">Target Kumulatif</th>
                                        <th className="px-4 py-2 text-right">Actual Kumulatif</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-border">
                                    {data.weeks.map((week) => (
                                        <Fragment key={week.start}>
                                            <tr className="bg-daiku-gray/60">
                                                <td className="px-4 py-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => toggle(week.start)}
                                                        aria-expanded={open.has(week.start)}
                                                        className="flex items-center gap-1.5 font-medium text-foreground hover:underline"
                                                    >
                                                        {open.has(week.start) ? <ChevronDown className="size-4" /> : <ChevronRight className="size-4" />}
                                                        Minggu {weekLabel(week)}
                                                    </button>
                                                </td>
                                                <NumberCells row={week} strong />
                                            </tr>
                                            {open.has(week.start) &&
                                                week.days.map((day) => (
                                                    <tr
                                                        key={day.date}
                                                        className={cn(
                                                            day.date === data.today && 'bg-daiku-yellow-light/60',
                                                            day.actual === null && 'text-muted-foreground',
                                                        )}
                                                    >
                                                        <td className="py-2 pr-4 pl-10">
                                                            {DAY_LABEL.format(asDate(day.date))}
                                                            {day.date === data.today && <span className="ml-2 text-xs text-daiku-muted">Hari ini</span>}
                                                        </td>
                                                        <NumberCells row={day} />
                                                    </tr>
                                                ))}
                                        </Fragment>
                                    ))}
                                </tbody>
                            </table>
                        </TableCard>
                    </>
                )}
            </SectionCard>
        </div>
    );
}

function NumberCells({ row, strong = false }: { row: Omit<ActivityTargetDay, 'date'>; strong?: boolean }) {
    return (
        <>
            <td className={cn('px-3 py-2 text-right tabular-nums', strong && 'font-medium')}>{row.target}</td>
            <td className={cn('px-3 py-2 text-right tabular-nums', strong && 'font-medium')}>{row.actual ?? '—'}</td>
            <td className="px-3 py-2 text-right tabular-nums">
                <GapValue gap={gapOf(row)} />
            </td>
            <td className="px-3 py-2 text-right tabular-nums">{row.rolled > 0 ? <span className="text-warning-ink">{row.rolled}</span> : '—'}</td>
            <td className="px-3 py-2 text-right tabular-nums">{row.cum_target}%</td>
            <td className="px-4 py-2 text-right tabular-nums">
                <CumulativeActual row={row} />
            </td>
        </>
    );
}

function WeekToggle({ week, open, onToggle }: { week: ActivityTargetWeek; open: boolean; onToggle: () => void }) {
    return (
        <button
            type="button"
            onClick={onToggle}
            aria-expanded={open}
            className="flex min-h-12 w-full items-center gap-2 px-4 py-3 text-left text-sm hover:bg-daiku-yellow-light/40"
        >
            {open ? <ChevronDown className="size-4 shrink-0" /> : <ChevronRight className="size-4 shrink-0" />}
            <span className="flex-1 font-medium text-foreground">Minggu {weekLabel(week)}</span>
            <span className="text-xs text-muted-foreground tabular-nums">
                {week.actual ?? '—'}/{week.target} · {week.cum_actual === null ? '—' : `${week.cum_actual}%`}
            </span>
        </button>
    );
}
