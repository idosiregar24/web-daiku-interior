import { EmptyState } from '@/Components/shared/EmptyState';
import { Notice } from '@/Components/shared/Notice';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';
import { formatDateTime, formatRupiah, formatRupiahCompact } from '@/lib/format';
import { formatProgress } from '@/lib/progress';
import { cn } from '@/lib/utils';
import { TARGET_STATUS, targetGap, targetGapText, targetStatus, type TargetStatus } from '@/lib/workCheck';
import type { ProjectExecution, WorkItemScheduleStatus, WorkItemTarget } from '@/types';
import { Link } from '@inertiajs/react';
import { format, parseISO } from 'date-fns';
import { id as localeId } from 'date-fns/locale';
import { CalendarRange, CircleCheck, FileDown, Hourglass, Info, PencilLine, Sheet, TriangleAlert, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { ExecutionDecisionDialog, type ExecutionDecisionTarget } from './ExecutionDecisionDialog';
import { decisionTargetOf, workCheckState } from './WeeklyReportsCard';
import { WorkCheckForm, type SheetInput } from './WorkCheckForm';
import { dayRange, inside, rangeLabel, SCHEDULE_STATUS } from './workSchedule';

/** A continuous day grid is drawn up to this many days; longer → only the pengecekan dates (+ today). */
const MAX_DAYS = 120;

type Timeline = { id: number; name: string; start: string | null; end: string | null };

interface Row {
    id: number;
    description: string;
    volume_label: string;
    team_lead: string;
    /** RAB value (money viewers). */
    value: number | null;
    weight: number | null;
    cells: Record<string, number>;
    total: number;
    contribution: number | null;
    status: WorkItemScheduleStatus | null;
    timelines: Timeline[];
    /** Sprint 38 — Progres Target (daily targets of the item done ÷ all); null without targets. */
    target: WorkItemTarget | null;
}

type Report = ProjectExecution['weeklyReports'][number];

/** "+30%", "−10%" (a correction), "" for nothing. */
function added(value: number | undefined): string {
    if (value === undefined || value === 0) return '';

    return `${value > 0 ? '+' : '−'}${formatProgress(Math.abs(value))}`;
}

/** The items as the sheet shows them — schedule (every viewer), recap & values (pengecekan viewers), whichever reached this viewer. */
function rowsOf(execution: ProjectExecution): Row[] {
    const { workSchedule: schedule, progressRecap: recap, workItems } = execution;
    const recapById = new Map((recap?.items ?? []).map((item) => [item.id, item]));
    const valueById = new Map((workItems?.sections ?? []).flatMap((section) => section.items).map((item) => [item.id, item.rab_subtotal]));
    const base: { id: number; description: string; volume_label: string; team_lead: string }[] = schedule?.items ?? recap?.items ?? [];

    return base.map((item) => {
        const timed = schedule?.items.find((row) => row.id === item.id);
        const summed = recapById.get(item.id);

        return {
            id: item.id,
            description: item.description,
            volume_label: item.volume_label,
            team_lead: item.team_lead,
            value: valueById.get(item.id) ?? null,
            weight: summed?.weight ?? null,
            cells: summed?.cells ?? {},
            total: summed?.total ?? timed?.progress ?? 0,
            contribution: summed?.contribution ?? null,
            status: timed?.status ?? null,
            timelines: timed?.timelines ?? [],
            target: summed?.target ?? null,
        };
    });
}

/**
 * Sprint 31 Sub 07 / Sprint 35 — "Lembar Progres", laid out like the
 * SIMULASI PERSENTASI sheet: NO · Uraian · Volume · Nilai · Bobot, then one
 * column per day (month over the date) with the item's timelines as blue
 * blocks and the % added on each pengecekan date, then TOTAL % and
 * Kontribusi (kept in view on the right), and the "Jumlah / Progres proyek"
 * row. The schedule is the Timeline (Sprint 35): the PM puts items into
 * timelines; nothing is scheduled here. For the Pengawas / Admin Proyek
 * today's column is where the pengecekan is typed (WorkCheckForm).
 */
export function WorkProgressSheet({ projectId, execution }: { projectId: number; execution: ProjectExecution }) {
    const { workSchedule: schedule, progressRecap: recap, workCheck, can } = execution;
    const state = workCheckState(execution);
    const rows = useMemo(() => rowsOf(execution), [execution]);
    const today = schedule?.today ?? format(new Date(), 'yyyy-MM-dd');
    // The pengecekan sent and waiting for the PM: its % stay visible (marked) and the PM decides right here.
    const pending = execution.weeklyReports.find((report) => report.status === 'DIAJUKAN') ?? null;
    const pendingDate = pending?.submitted_at ? format(parseISO(pending.submitted_at), 'yyyy-MM-dd') : null;
    const [deciding, setDeciding] = useState<ExecutionDecisionTarget | null>(null);
    // Nothing new is typed while this week's last pengecekan waits for the PM.
    const typing = state.showsCheck && workCheck !== null && execution.currentWeek !== null && (state.weekOpen || pending === null);
    const timelineHref = route('projects.show', { project: projectId, tab: 'milestone' });

    if (rows.length === 0) {
        return (
            <SectionCard title="Lembar Progres" icon={Sheet}>
                <EmptyState title="Belum ada item berupah." description="Lembar Progres terisi setelah Satuan Upah tiap item diisi di Upah Tukang." />
            </SectionCard>
        );
    }

    const table = (input?: SheetInput) => <SheetTable rows={rows} execution={execution} today={today} pendingDate={pendingDate} input={input} />;

    return (
        <SectionCard
            title="Lembar Progres"
            description="Jadwal (dari Timeline) & progres tiap item RAB — seperti lembar Simulasi Persentasi."
            icon={Sheet}
            action={
                recap || can.linkTimelines ? (
                    <div className="flex flex-wrap gap-2">
                        {/* The recap reaches exactly who may download it (ProjectPolicy::viewProgressRecap()). */}
                        {recap && (
                            <Button size="sm" variant="outline" asChild>
                                <a href={route('projects.progress-sheet.excel', { project: projectId })}>
                                    <FileDown className="size-4" />
                                    Unduh Excel
                                </a>
                            </Button>
                        )}
                        {can.linkTimelines && (
                            <Button size="sm" variant="outline" asChild>
                                <Link href={timelineHref}>
                                    <CalendarRange className="size-4" />
                                    Atur di Timeline
                                </Link>
                            </Button>
                        )}
                    </div>
                ) : undefined
            }
        >
            {schedule && (schedule.summary.late > 0 || schedule.summary.unscheduled > 0) && (
                <div className="mb-4 space-y-2">
                    {schedule.summary.late > 0 && (
                        <Notice tone="error">{schedule.summary.late} item terlambat dari jadwalnya (timeline sudah lewat, progres belum 100 %).</Notice>
                    )}
                    {schedule.summary.unscheduled > 0 && (
                        <Notice tone="info">
                            {schedule.summary.unscheduled} dari {schedule.summary.total} item belum masuk timeline, jadi belum punya jadwal —{' '}
                            {can.linkTimelines ? (
                                <Link href={timelineHref} className="font-medium underline decoration-daiku-yellow underline-offset-2">
                                    pilih item RAB-nya saat menambah / mengubah timeline
                                </Link>
                            ) : (
                                'dipilih PM saat menyusun timeline'
                            )}
                            .
                        </Notice>
                    )}
                </div>
            )}

            <TargetSummary rows={rows} gap={recap?.target_gap ?? null} />

            {pending && (
                <PendingCheckBanner
                    report={pending}
                    rows={rows}
                    gap={recap?.target_gap ?? null}
                    canDecide={can.decide}
                    sentBySelf={state.showsCheck}
                    onDecide={() => setDeciding(decisionTargetOf(pending, execution))}
                />
            )}

            {typing && !state.sentThisWeek && <TodayPrompt label={state.nextLabel} canSend={state.canSend} sendDays={state.sendDays} />}

            {typing && workCheck && execution.currentWeek ? (
                <WorkCheckForm
                    key={state.formKey}
                    projectId={projectId}
                    check={workCheck}
                    weekLabel={state.nextLabel}
                    canSubmit={state.canSend}
                    canDraft={can.fillWorkCheck}
                    blockedReason={state.blockedReason}
                    rejectedNote={state.rejectedNote}
                    renderSheet={(input) => table(input)}
                />
            ) : (
                <>
                    <PhoneCards rows={rows} pendingDate={pendingDate} gap={recap?.target_gap ?? null} />
                    <div className="hidden md:block">{table()}</div>
                    {recap && recap.total !== null && <p className="mt-3 text-sm text-daiku-muted md:hidden">Progres proyek {formatProgress(recap.total)}</p>}
                </>
            )}

            <ExecutionDecisionDialog target={deciding} onOpenChange={(open) => !open && setDeciding(null)} />
        </SectionCard>
    );
}

const HEAD = 'bg-daiku-yellow-light px-2 py-1.5 text-[11px] font-semibold tracking-wide text-daiku-muted uppercase';
// Kept in view while the days scroll: NO + Uraian on the left, TOTAL % (+ Daily Target Selesai) + Kontribusi on the right (like the sheet's frozen panes).
// Frozen left: NO · Uraian (+ volume) · Nilai · Bobot — offsets in px, the money columns only for who sees them.
const W = { no: 40, item: 224, value: 104, weight: 88 };
const LEFT = 'sticky z-10';
const RIGHT_SHARE = 'sticky right-0 z-10 w-24 min-w-24';
// Sprint 38 — Total % | Daily Target Selesai | Kontribusi: read the pengecekan first, its comparison beside it.
// Daily Target Selesai sits on Kontribusi (right-24 = w-24); Total % on both (right-52 = w-28 + w-24) — else on Kontribusi.
const RIGHT_TARGET = 'sticky right-24 z-10 w-28 min-w-28 border-l border-border';
const RIGHT_TOTAL_BESIDE_SHARE = 'sticky right-24 z-10 w-24 min-w-24 border-l border-border';
const RIGHT_TOTAL_BESIDE_TARGET = 'sticky right-52 z-10 w-24 min-w-24 border-l border-border';

function SheetTable({
    rows,
    execution,
    today,
    pendingDate,
    input,
}: {
    rows: Row[];
    execution: ProjectExecution;
    today: string;
    pendingDate: string | null;
    input?: SheetInput;
}) {
    const { workSchedule: schedule, progressRecap: recap, workCheck } = execution;
    const showValue = rows.some((row) => row.value !== null);
    const showWeight = recap !== null;
    // Sprint 38 — the Target column once any item has a daily target.
    const showTarget = recap !== null && rows.some((row) => row.target !== null);
    const RIGHT_TOTAL = showTarget ? RIGHT_TOTAL_BESIDE_TARGET : RIGHT_TOTAL_BESIDE_SHARE;
    const gap = recap?.target_gap ?? null;
    const at = {
        no: { left: 0, width: W.no, minWidth: W.no },
        item: { left: W.no, width: W.item, minWidth: W.item, maxWidth: W.item },
        value: { left: W.no + W.item, width: W.value, minWidth: W.value },
        weight: { left: W.no + W.item + (showValue ? W.value : 0), width: W.weight, minWidth: W.weight },
    };
    // The last frozen column draws the divider.
    const lastFrozen = showWeight ? 'weight' : showValue ? 'value' : 'item';
    const edge = (key: keyof typeof at) => (key === lastFrozen ? 'border-r border-border' : '');

    // Continuous days over the timelines, the pengecekan dates and (when typing) today; else just those dates.
    const days = useMemo(() => {
        const marks = [...(recap?.dates ?? []), ...(input ? [today] : [])];
        const bounds = [schedule?.from, schedule?.to, ...marks].filter((day): day is string => Boolean(day)).sort();
        if (bounds.length === 0) return [];
        const range = dayRange(bounds[0], bounds[bounds.length - 1]);

        return schedule?.from && range.length <= MAX_DAYS ? range : Array.from(new Set(marks)).sort();
    }, [schedule?.from, schedule?.to, recap?.dates, input, today]);

    // The month row over the dates (the sheet's "SEPTEMBER | OKTOBER").
    const months = useMemo(() => {
        const spans: { key: string; label: string; span: number }[] = [];
        days.forEach((day) => {
            const key = day.slice(0, 7);
            const last = spans[spans.length - 1];
            if (last && last.key === key) last.span += 1;
            else spans.push({ key, label: format(parseISO(day), 'MMMM yyyy', { locale: localeId }), span: 1 });
        });

        // A month with only a day or two under it gets its short name ("Nov") — the full one wouldn't fit.
        return spans.map((span) => (span.span < 3 ? { ...span, label: format(parseISO(`${span.key}-01`), 'MMM', { locale: localeId }) } : span));
    }, [days]);

    const cumulative = new Map((recap?.per_date ?? []).map((row) => [row.date, row.cumulative]));
    const checkItems = new Map((workCheck?.items ?? []).map((item) => [item.id, item]));
    const scroller = useRef<HTMLDivElement>(null);
    const totalValue = rows.reduce((sum, row) => sum + (row.value ?? 0), 0);
    const projectTotal = input ? input.preview.weightedProgress : (recap?.total ?? null);

    // Today's column in view (a long project's grid is wider than the screen) — once, on open.
    useEffect(() => {
        const box = scroller.current;
        const cell = box?.querySelector<HTMLElement>('th[data-today]');
        if (!box || !cell) return;
        const overflow = cell.offsetLeft + cell.offsetWidth - (box.clientWidth - 176);
        if (overflow > 0) box.scrollLeft = overflow + 16;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <div className="overflow-hidden rounded-lg ring-1 ring-border">
            {/* Only the grid scrolls sideways; the colour legend below stays put. */}
            <div ref={scroller} className="overflow-x-auto">
                <table className="border-separate border-spacing-0 text-sm">
                    <thead>
                        <tr>
                            <th rowSpan={2} style={at.no} className={cn(HEAD, LEFT, 'text-center')}>
                                No
                            </th>
                            <th rowSpan={2} style={at.item} className={cn(HEAD, LEFT, edge('item'), 'text-left')}>
                                Uraian Pekerjaan · Volume
                            </th>
                            {showValue && (
                                <th rowSpan={2} style={at.value} className={cn(HEAD, LEFT, edge('value'), 'text-right')}>
                                    Nilai
                                </th>
                            )}
                            {showWeight && (
                                <th
                                    rowSpan={2}
                                    style={at.weight}
                                    className={cn(HEAD, LEFT, edge('weight'), 'text-right')}
                                    title="Bobot = Nilai RAB item ÷ Σ Nilai RAB item berupah × 100%"
                                >
                                    Bobot
                                    {/* Same formula as WorkItemService (the sheet's bobot). */}
                                    <span className="block text-[10px] leading-tight font-normal tracking-normal normal-case">Nilai ÷ Σ Nilai berupah</span>
                                </th>
                            )}
                            {months.map((month) => (
                                <th key={month.key} colSpan={month.span} className={cn(HEAD, 'border-b border-l border-border text-center')}>
                                    {month.label}
                                </th>
                            ))}
                            <th rowSpan={2} className={cn(HEAD, RIGHT_TOTAL, 'text-right')}>
                                Total %
                                <span className="block text-[10px] leading-tight font-normal tracking-normal normal-case">
                                    cek Pengawas
                                    <InfoTip label="Total %">
                                        Jumlah persen yang diisi Pengawas di tiap pengecekan. Inilah progres resmi item —{' '}
                                        <strong>dasar pencairan upah</strong> dan syarat Berangkat ke Lokasi.
                                    </InfoTip>
                                </span>
                            </th>
                            {showTarget && (
                                <th rowSpan={2} className={cn(HEAD, RIGHT_TARGET, 'text-right')}>
                                    Daily Target Selesai
                                    <InfoTip label="Daily Target Selesai">
                                        Daily target item ini yang sudah selesai (dicentang Pengawas) ÷ semua daily target item ini. Dihitung otomatis dari
                                        kerja tukang — <strong>pembanding</strong>, bukan dasar upah.
                                    </InfoTip>
                                </th>
                            )}
                            <th rowSpan={2} className={cn(HEAD, RIGHT_SHARE, 'text-right')}>
                                Kontribusi
                            </th>
                        </tr>
                        <tr>
                            {days.map((day) => {
                                const date = parseISO(day);
                                const isToday = day === today;

                                return (
                                    <th
                                        key={day}
                                        data-today={isToday || undefined}
                                        className={cn(
                                            'bg-daiku-yellow-light px-0 py-1.5 text-center text-xs font-medium tabular-nums text-daiku-muted',
                                            isToday && input ? 'min-w-24 border-x-2 border-daiku-yellow-dark bg-daiku-yellow text-daiku-dark' : 'w-10 min-w-10',
                                            isToday && !input && 'bg-warning/20 text-foreground',
                                            !isToday && date.getDay() === 0 && 'bg-daiku-gray',
                                        )}
                                        title={format(date, 'EEEE, d MMMM yyyy', { locale: localeId })}
                                    >
                                        {isToday && input ? (
                                            <span className="flex flex-col items-center leading-tight">
                                                <span className="text-[10px] font-bold uppercase">Isi hari ini</span>
                                                <span className="font-semibold">{format(date, 'd MMM', { locale: localeId })}</span>
                                            </span>
                                        ) : (
                                            date.getDate()
                                        )}
                                    </th>
                                );
                            })}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row, index) => {
                            const checkItem = checkItems.get(row.id);
                            const newTotal = input && checkItem ? input.preview.progress[row.id] : undefined;
                            const changed = newTotal !== undefined && newTotal !== row.total;
                            const shownTotal = changed ? newTotal : row.total;
                            const late = row.status === 'TERLAMBAT';
                            const cell = 'border-t border-border';
                            const status = gap !== null ? targetStatus(shownTotal, row.target, gap) : null;

                            return (
                                <tr key={row.id}>
                                    <td style={at.no} className={cn(cell, LEFT, 'bg-background px-2 py-2 text-center align-top text-daiku-muted tabular-nums')}>
                                        {index + 1}
                                    </td>
                                    <td style={at.item} className={cn(cell, LEFT, edge('item'), 'bg-background px-3 py-2 align-top')}>
                                        <p className="leading-snug font-medium text-foreground">{row.description}</p>
                                        <p className="text-xs text-daiku-muted">
                                            {row.volume_label} · {row.team_lead}
                                            {row.timelines.length === 0 && ' · belum masuk timeline'}
                                        </p>
                                        {late && <p className="text-xs font-medium text-error-ink">Terlambat dari timeline</p>}
                                    </td>
                                    {showValue && (
                                        <td
                                            style={at.value}
                                            title={row.value !== null ? formatRupiah(row.value) : undefined}
                                            className={cn(cell, LEFT, edge('value'), 'bg-background px-2 py-2 text-right align-top whitespace-nowrap tabular-nums')}
                                        >
                                            {row.value !== null ? formatRupiahCompact(row.value) : '—'}
                                        </td>
                                    )}
                                    {showWeight && (
                                        <td style={at.weight} className={cn(cell, LEFT, edge('weight'), 'bg-background px-2 py-2 text-right align-top text-daiku-muted tabular-nums')}>
                                            {row.weight !== null ? formatProgress(row.weight) : '—'}
                                        </td>
                                    )}
                                    {days.map((day) => {
                                        const block = row.timelines.find((timeline) => inside(day, timeline.start, timeline.end));
                                        const isToday = day === today;

                                        return (
                                            <td
                                                key={day}
                                                title={block ? `${block.name} · ${rangeLabel(block.start, block.end)}` : undefined}
                                                className={cn(
                                                    cell,
                                                    'px-0 py-1 text-center align-middle',
                                                    isToday && input ? 'border-x-2 border-daiku-yellow-dark bg-daiku-yellow-light px-2' : [block && 'bg-info/25', !block && isToday && 'bg-warning/10'],
                                                )}
                                            >
                                                {isToday && input && checkItem ? (
                                                    // An earlier pengecekan of today stays readable above the new input.
                                                    <span className="flex flex-col items-center gap-1">
                                                        {row.cells[day] ? (
                                                            <span className="text-[10px] font-medium whitespace-nowrap text-daiku-muted">sudah {added(row.cells[day])}</span>
                                                        ) : null}
                                                        {input.field(checkItem)}
                                                        <TargetDot count={row.target?.days?.[day]} />
                                                    </span>
                                                ) : (
                                                    <>
                                                        <CellValue value={row.cells[day]} pending={day === pendingDate} />
                                                        <TargetDot count={row.target?.days?.[day]} />
                                                    </>
                                                )}
                                            </td>
                                        );
                                    })}
                                    <td className={cn(cell, RIGHT_TOTAL, 'bg-background px-2 py-2 text-right align-top font-semibold tabular-nums')}>
                                        <span className={cn(shownTotal >= 100 && 'text-success-ink')}>{formatProgress(shownTotal)}</span>
                                        {changed && newTotal !== undefined && (
                                            <span className="block text-[11px] font-normal whitespace-nowrap text-daiku-muted" title={`Sebelumnya ${formatProgress(row.total)}`}>
                                                {added(newTotal - row.total)} hari ini
                                            </span>
                                        )}
                                    </td>
                                    {showTarget && (
                                        <td
                                            className={cn(cell, RIGHT_TARGET, 'bg-background px-2 py-2 text-right align-top tabular-nums')}
                                            title={status ? TARGET_STATUS[status].hint : 'Belum ada daily target untuk item ini.'}
                                        >
                                            {row.target ? (
                                                <>
                                                    {formatProgress(row.target.percent)}
                                                    <span className="block text-[11px] whitespace-nowrap text-daiku-muted">
                                                        {row.target.done} dari {row.target.planned} target
                                                    </span>
                                                    {status && <StatusLine status={status} />}
                                                </>
                                            ) : (
                                                <span className="text-[11px] text-daiku-muted">belum ada daily target</span>
                                            )}
                                        </td>
                                    )}
                                    <td className={cn(cell, RIGHT_SHARE, 'bg-background px-2 py-2 text-right align-top tabular-nums')}>
                                        {row.contribution !== null ? formatProgress(changed && row.weight !== null ? (shownTotal * row.weight) / 100 : row.contribution) : '—'}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                    {recap && (
                        <tfoot>
                            <tr className="font-semibold">
                                <td style={at.no} className={cn('border-t-2 border-border', LEFT, 'bg-daiku-gray')} />
                                <td style={at.item} className={cn('border-t-2 border-border', LEFT, edge('item'), 'bg-daiku-gray px-3 py-2')}>
                                    Jumlah · progres proyek
                                </td>
                                {showValue && (
                                    <td
                                        style={at.value}
                                        title={formatRupiah(totalValue)}
                                        className={cn('border-t-2 border-border', LEFT, edge('value'), 'bg-daiku-gray px-2 py-2 text-right whitespace-nowrap tabular-nums')}
                                    >
                                        {formatRupiahCompact(totalValue)}
                                    </td>
                                )}
                                {showWeight && (
                                    <td style={at.weight} className={cn('border-t-2 border-border', LEFT, edge('weight'), 'bg-daiku-gray px-2 py-2 text-right tabular-nums')}>
                                        100%
                                    </td>
                                )}
                                {days.map((day) => {
                                    const isToday = day === today;
                                    const value = isToday && input ? input.preview.weightedProgress : cumulative.get(day);

                                    return (
                                        <td
                                            key={day}
                                            className={cn(
                                                'border-t-2 border-border bg-daiku-gray px-0 py-2 text-center text-[11px] tabular-nums',
                                                isToday && input && 'border-x-2 border-daiku-yellow-dark bg-daiku-yellow-light text-info-ink',
                                            )}
                                        >
                                            {value !== undefined && value !== null ? formatProgress(value) : ''}
                                        </td>
                                    );
                                })}
                                <td className={cn('border-t-2 border-border', RIGHT_TOTAL, 'bg-daiku-gray px-2 py-2 text-right tabular-nums')} />
                                {showTarget && (
                                    <td
                                        className={cn('border-t-2 border-border', RIGHT_TARGET, 'bg-daiku-gray px-2 py-2 text-right tabular-nums')}
                                        title="Daily target selesai tiap item, ditimbang bobot item (hanya item yang punya daily target)"
                                    >
                                        {formatProgress(recap.target_total)}
                                        <span className="block text-[11px] font-normal text-daiku-muted">proyek</span>
                                    </td>
                                )}
                                <td className={cn('border-t-2 border-border', RIGHT_SHARE, 'bg-daiku-yellow px-2 py-2 text-right text-daiku-dark tabular-nums')}>
                                    {projectTotal !== null ? formatProgress(projectTotal) : '—'}
                                    <span className="block text-[11px] font-normal">progres proyek</span>
                                </td>
                            </tr>
                        </tfoot>
                    )}
                </table>
            </div>
            <Legend pending={pendingDate !== null} target={showTarget} gap={gap} />
        </div>
    );
}

/** A % added on a pengecekan date: a small chip, centred in its day over the timeline colour; `pending` = sent, waiting for the PM. */
function CellValue({ value, pending = false }: { value: number | undefined; pending?: boolean }) {
    if (value === undefined || value === 0) return null;

    return (
        <span
            title={pending ? 'Terkirim — menunggu ACC PM' : undefined}
            className={cn(
                'inline-flex min-w-9 justify-center rounded-md px-1 py-0.5 text-[11px] font-semibold tabular-nums shadow-xs',
                pending ? 'bg-warning/20 text-warning-ink ring-1 ring-warning' : 'bg-background text-foreground ring-1 ring-border',
            )}
        >
            {added(value)}
        </span>
    );
}

/** Sprint 38 — daily targets of the item finished that day: a small dot beside the %. */
function TargetDot({ count }: { count: number | undefined }) {
    if (!count) return null;

    return (
        <span
            title={`${count} daily target selesai`}
            aria-label={`${count} daily target selesai`}
            className="ml-0.5 inline-block size-1.5 rounded-full bg-success-ink align-middle"
        />
    );
}

/** Sprint 38 — the two columns in words: "✓ Sejalan", "⚠ Progres lebih tinggi", … */
function StatusLine({ status, inline = false }: { status: TargetStatus; inline?: boolean }) {
    const { label, className } = TARGET_STATUS[status];
    const Icon = status === 'aligned' ? CircleCheck : status === 'above' ? TriangleAlert : null;

    return (
        <span className={cn('items-center gap-0.5 text-[11px] font-medium', inline ? 'inline-flex' : 'mt-0.5 flex justify-end text-right', className)}>
            {Icon && <Icon className="size-3 shrink-0" aria-hidden />}
            {label}
        </span>
    );
}

/** Sprint 38 — ⓘ beside a column title: what the number means (a tap on phones, unlike a `title`). */
function InfoTip({ label, children }: { label: string; children: ReactNode }) {
    return (
        <Popover>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    aria-label={`Penjelasan ${label}`}
                    className="ml-1 inline-flex align-middle text-daiku-muted transition-colors hover:text-foreground focus-visible:text-foreground focus-visible:outline-none"
                >
                    <Info className="size-3.5" />
                </button>
            </PopoverTrigger>
            <PopoverContent className="w-64 text-xs leading-relaxed">{children}</PopoverContent>
        </Popover>
    );
}

const EXPLAIN_KEY = 'daiku.progressSheet.explainClosed.v2';

/**
 * Sprint 38 — above the sheet: how to read Total % against Daily Target
 * Selesai (closable, remembered on this device) and which items need a look —
 * from the recorded pengecekan, not today's typing.
 */
function TargetSummary({ rows, gap }: { rows: Row[]; gap: number | null }) {
    const [closed, setClosed] = useState(() => {
        try {
            return window.localStorage.getItem(EXPLAIN_KEY) === '1';
        } catch {
            return false;
        }
    });

    if (gap === null || !rows.some((row) => row.target !== null)) return null;

    const byStatus = (wanted: TargetStatus) => rows.filter((row) => targetStatus(row.total, row.target, gap) === wanted).map((row) => row.description);
    const above = byStatus('above');
    const below = byStatus('below');

    function close() {
        setClosed(true);
        try {
            window.localStorage.setItem(EXPLAIN_KEY, '1');
        } catch {
            // Private mode — closed for this visit only.
        }
    }

    return (
        <div className="mb-4 space-y-2">
            {!closed && (
                <div className="flex items-start gap-2.5 rounded-lg border border-border bg-daiku-gray/60 px-3.5 py-3 text-sm text-foreground">
                    <Info className="mt-0.5 size-4 shrink-0 text-info-ink" aria-hidden />
                    <p className="min-w-0 flex-1">
                        <span className="font-medium">Cara baca (dari kiri):</span> <span className="font-medium">Total %</span> = progres item yang diisi
                        Pengawas — dasar upah. <span className="font-medium">Daily Target Selesai</span> di sebelahnya = berapa daily target tukang untuk
                        item itu yang sudah selesai. Keterangan di bawahnya menjawab: <span className="font-medium">apakah Total % sesuai dengan kerja
                        tukang?</span> (beda ≥ {gap} poin = di atas / di bawah target).
                    </p>
                    <Button size="icon" variant="ghost" className="-my-1 size-7" aria-label="Tutup penjelasan" onClick={close}>
                        <X className="size-4" />
                    </Button>
                </div>
            )}
            {above.length > 0 && (
                <Notice tone="warning">
                    {above.length} item Total %-nya di atas daily target yang selesai: <span className="font-medium">{above.join(', ')}</span>. Cek
                    daily target yang belum dicentang, atau persennya, sebelum mengirim.
                </Notice>
            )}
            {below.length > 0 && (
                <Notice tone="info">
                    {below.length} item Total %-nya di bawah daily target yang selesai: <span className="font-medium">{below.join(', ')}</span> — naikkan
                    persennya di pengecekan berikutnya bila pekerjaannya memang sudah sejauh itu.
                </Notice>
            )}
        </div>
    );
}

/**
 * Sprint 31 — the pengecekan the Pengawas sent, waiting for the PM: what was
 * sent (per item, the money asked) and, for the PM, the decision right here.
 */
function PendingCheckBanner({
    report,
    rows,
    gap,
    canDecide,
    sentBySelf,
    onDecide,
}: {
    report: Report;
    rows: Row[];
    /** Sprint 38 — target_gap; null for viewers without the recap. */
    gap: number | null;
    canDecide: boolean;
    sentBySelf: boolean;
    onDecide: () => void;
}) {
    const asked = report.payment?.status === 'DIAJUKAN' ? report.payment : null;
    // Sprint 38 — an item whose total (with this pengecekan) is far from its Progres Target.
    const warnOf = (itemId: number) => {
        const row = rows.find((candidate) => candidate.id === itemId);
        const difference = row && gap !== null ? targetGap(row.total, row.target, gap) : null;

        return row?.target && difference !== null && difference > 0 ? targetGapText(difference, row.target) : null;
    };

    return (
        <div role="status" className="mb-4 flex flex-col gap-3 rounded-xl border-2 border-warning bg-warning/10 p-4 sm:flex-row sm:items-start">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-warning/25 text-warning-ink">
                <Hourglass className="size-5" />
            </span>
            <div className="min-w-0 flex-1 space-y-1.5">
                <p className="font-semibold text-foreground">{canDecide ? 'Pengecekan menunggu ACC Anda' : 'Pengecekan terkirim — menunggu ACC PM'}</p>
                <p className="text-sm text-daiku-muted">
                    {report.label} · dikirim {formatDateTime(report.submitted_at)}
                    {report.supervisor ? ` oleh ${report.supervisor}` : ''}
                    {asked ? ` · mengajukan upah ${formatRupiah(asked.amount)}` : ''}
                </p>
                {report.progress.length > 0 && (
                    <div className="flex flex-wrap gap-1.5">
                        {report.progress.map((row, index) => {
                            const warn = warnOf(row.item_id);

                            return (
                                <span
                                    key={index}
                                    title={warn ? `Total item ini ${warn}` : undefined}
                                    className="inline-flex items-center gap-1 rounded-md bg-background px-2 py-0.5 text-xs text-foreground tabular-nums ring-1 ring-warning"
                                >
                                    {row.description ?? 'Item'} <span className="font-semibold">{added(row.percent)}</span>
                                    {warn && <TriangleAlert className="size-3 text-warning-ink" aria-label={`Total ${warn}`} />}
                                </span>
                            );
                        })}
                    </div>
                )}
                {!canDecide && sentBySelf && (
                    <p className="text-xs text-daiku-muted">Persen yang dikirim tampil di sel bertanda oranye. Pengecekan berikutnya bisa diisi setelah PM memutuskan.</p>
                )}
            </div>
            {canDecide && (
                <Button className="w-full sm:w-auto" onClick={onDecide}>
                    Setujui / Tolak
                </Button>
            )}
        </div>
    );
}

/**
 * Sprint 31 Sub 07 — "Isi persentase progres hari ini": the Pengawas's call
 * to action above the sheet; "Mulai isi" puts the cursor in the first empty
 * % (today's column, or the first card on a phone). Closable for this visit.
 */
function TodayPrompt({ label, canSend, sendDays }: { label: string; canSend: boolean; sendDays: string }) {
    const [closed, setClosed] = useState(false);

    if (closed) return null;

    function start() {
        const inputs = Array.from(document.querySelectorAll<HTMLInputElement>('input[aria-label^="Tambahan persen"]')).filter(
            (input) => input.offsetParent !== null && !input.disabled,
        );
        const target = inputs.find((input) => input.value.trim() === '') ?? inputs[0];
        target?.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
        target?.focus({ preventScroll: true });
    }

    return (
        <div role="status" className="mb-4 flex flex-col gap-3 rounded-xl border-2 border-daiku-yellow bg-daiku-yellow-light p-4 sm:flex-row sm:items-center">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-daiku-yellow text-daiku-dark">
                <PencilLine className="size-5" />
            </span>
            <div className="min-w-0 flex-1">
                <p className="font-semibold text-foreground">{canSend ? 'Isi persentase progres & kirim pengecekan' : 'Isi persentase progres (draf)'}</p>
                <p className="text-sm text-daiku-muted">
                    Pengecekan {label}: ketik tambahan % di kolom kuning <span className="font-medium text-foreground">Isi hari ini</span> hanya untuk item yang
                    dikerjakan — <span className="font-medium text-foreground">item lain biarkan kosong</span>.{' '}
                    {canSend ? (
                        <>
                            Hari ini hari kirim: tekan <span className="font-medium text-foreground">Kirim Pengecekan</span> di bawah (sekali seminggu).
                        </>
                    ) : (
                        <>
                            Tersimpan otomatis sebagai draf — <span className="font-medium text-foreground">dikirim hari {sendDays}</span>.
                        </>
                    )}
                </p>
            </div>
            <div className="flex gap-2">
                <Button className="flex-1 sm:flex-none" onClick={start}>
                    Mulai isi
                </Button>
                <Button size="icon" variant="ghost" aria-label="Tutup" onClick={() => setClosed(true)}>
                    <X className="size-4" />
                </Button>
            </div>
        </div>
    );
}

function Legend({ pending, target, gap }: { pending: boolean; target: boolean; gap: number | null }) {
    const swatch = (className: string, label: ReactNode) => (
        <span className="inline-flex items-center gap-1.5">
            <span className={cn('inline-block size-3 rounded-sm', className)} /> {label}
        </span>
    );

    return (
        <p className="flex flex-wrap items-center gap-4 border-t border-border px-3 py-2 text-xs text-daiku-muted">
            {swatch('bg-info/25', 'Jadwal (timeline item)')}
            {swatch('bg-daiku-yellow ring-1 ring-daiku-yellow-dark', 'Isi hari ini')}
            {pending && swatch('bg-warning/20 ring-1 ring-warning', 'Terkirim, menunggu ACC PM')}
            {target && (
                <span className="inline-flex items-center gap-1.5">
                    <span className="inline-block size-1.5 rounded-full bg-success-ink" /> Daily target item selesai hari itu
                </span>
            )}
            {target && gap !== null && (
                <span className="inline-flex items-center gap-1.5">
                    <TriangleAlert className="size-3 text-warning-ink" aria-hidden /> Total % ≥ {gap} poin di atas Daily Target Selesai (hanya penanda)
                </span>
            )}
            <span>Angka di sel = persen yang ditambahkan pada pengecekan tanggal itu. Baris bawah = progres proyek kumulatif.</span>
        </p>
    );
}

/** Phones: one card per item — timelines, status, total, the dates it moved. */
function PhoneCards({ rows, pendingDate, gap }: { rows: Row[]; pendingDate: string | null; gap: number | null }) {
    return (
        <ul className="divide-y divide-border rounded-lg ring-1 ring-border md:hidden">
            {rows.map((row, index) => {
                const status = gap !== null ? targetStatus(row.total, row.target, gap) : null;

                return (
                <li key={row.id} className="space-y-1.5 px-3 py-3">
                    <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                            <p className="text-sm font-medium text-foreground">
                                {index + 1}. {row.description}
                            </p>
                            <p className="text-xs text-daiku-muted">
                                {row.volume_label} · total {formatProgress(row.total)}
                                {row.contribution !== null && ` · kontribusi ${formatProgress(row.contribution)}`}
                            </p>
                        </div>
                        {row.status && <StatusChip status={row.status} tone={SCHEDULE_STATUS[row.status].tone} label={SCHEDULE_STATUS[row.status].label} />}
                    </div>
                    <p className="text-xs text-daiku-muted">
                        {row.timelines.length > 0
                            ? row.timelines.map((timeline) => `${timeline.name} ${rangeLabel(timeline.start, timeline.end)}`).join(' · ')
                            : 'Belum masuk timeline'}
                    </p>
                    {row.target && (
                        <div className="flex flex-wrap items-center gap-x-2 text-xs">
                            <span className="text-daiku-muted">
                                Daily target selesai {formatProgress(row.target.percent)} ({row.target.done} dari {row.target.planned})
                            </span>
                            {status && <StatusLine status={status} inline />}
                        </div>
                    )}
                    {Object.keys(row.cells).length > 0 && (
                        <div className="flex flex-wrap gap-1.5">
                            {Object.entries(row.cells)
                                .sort(([a], [b]) => a.localeCompare(b))
                                .map(([date, value]) => (
                                    <span
                                        key={date}
                                        className={cn(
                                            'rounded-md px-2 py-0.5 text-xs tabular-nums',
                                            date === pendingDate ? 'bg-warning/20 text-warning-ink ring-1 ring-warning' : 'bg-daiku-gray text-daiku-muted',
                                        )}
                                    >
                                        {format(parseISO(date), 'd MMM', { locale: localeId })} {added(value)}
                                    </span>
                                ))}
                        </div>
                    )}
                </li>
                );
            })}
        </ul>
    );
}
