import { EmptyState } from '@/Components/shared/EmptyState';
import { Notice } from '@/Components/shared/Notice';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { formatDateTime, formatRupiah } from '@/lib/format';
import { formatProgress } from '@/lib/progress';
import { cn } from '@/lib/utils';
import type { ProjectExecution, WorkItemScheduleStatus, WorkSchedule } from '@/types';
import { format, parseISO } from 'date-fns';
import { id as localeId } from 'date-fns/locale';
import { CalendarRange, Hourglass, PencilLine, Pencil, Sheet, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { ExecutionDecisionDialog, type ExecutionDecisionTarget } from './ExecutionDecisionDialog';
import { decisionTargetOf, workCheckState } from './WeeklyReportsCard';
import { WorkCheckForm, type SheetInput } from './WorkCheckForm';
import { ItemScheduleDialog, SequenceScheduleDialog } from './WorkScheduleDialogs';
import { dayRange, inside, rangeLabel, SCHEDULE_STATUS } from './workSchedule';

/** A continuous day grid is drawn up to this many days; longer → only the pengecekan dates (+ today). */
const MAX_DAYS = 120;

interface Row {
    id: number;
    description: string;
    volume_label: string;
    team_lead: string;
    weight: number | null;
    cells: Record<string, number>;
    total: number;
    contribution: number | null;
    status: WorkItemScheduleStatus | null;
    production_start: string | null;
    production_end: string | null;
    installation_start: string | null;
    installation_end: string | null;
}

type Report = ProjectExecution['weeklyReports'][number];

/** "+30%", "−10%" (a correction), "" for nothing. */
function added(value: number | undefined): string {
    if (value === undefined || value === 0) return '';

    return `${value > 0 ? '+' : '−'}${formatProgress(Math.abs(value))}`;
}

/** The items as the sheet shows them — schedule (every viewer), recap (pengecekan viewers), whichever reached this viewer. */
function rowsOf(execution: ProjectExecution): Row[] {
    const { workSchedule: schedule, progressRecap: recap } = execution;
    const recapById = new Map((recap?.items ?? []).map((item) => [item.id, item]));
    const base: {
        id: number;
        description: string;
        volume_label: string;
        team_lead: string;
    }[] = schedule?.items ?? recap?.items ?? [];

    return base.map((item) => {
        const timed = schedule?.items.find((row) => row.id === item.id);
        const summed = recapById.get(item.id);

        return {
            id: item.id,
            description: item.description,
            volume_label: item.volume_label,
            team_lead: item.team_lead,
            weight: summed?.weight ?? null,
            cells: summed?.cells ?? {},
            total: summed?.total ?? timed?.progress ?? 0,
            contribution: summed?.contribution ?? null,
            status: timed?.status ?? null,
            production_start: timed?.production_start ?? null,
            production_end: timed?.production_end ?? null,
            installation_start: timed?.installation_start ?? null,
            installation_end: timed?.installation_end ?? null,
        };
    });
}

/**
 * Sprint 31 Sub 07 — "Lembar Progres": the SIMULASI PERSENTASI sheet in one
 * table. Per RAB item and per day: the planned production (blue) and
 * installation (green) windows, the % the Pengawas added on each pengecekan
 * date written in its cell, and — for the Pengawas / Admin Proyek — today's
 * column is where this pengecekan is typed. Right of the days: Total %,
 * Kontribusi (Total × Bobot), status against the schedule. Under the table
 * the rest of the pengecekan (summary, notes, Kirim) — WorkCheckForm. Phones
 * get a card per item (the input cards with their quick buttons).
 */
export function WorkProgressSheet({ projectId, execution }: { projectId: number; execution: ProjectExecution }) {
    const { workSchedule: schedule, progressRecap: recap, workCheck, can } = execution;
    const [editing, setEditing] = useState<WorkSchedule['items'][number] | null>(null);
    const [sequencing, setSequencing] = useState(false);
    const state = workCheckState(execution);
    const rows = useMemo(() => rowsOf(execution), [execution]);
    const canSchedule = can.scheduleItems && schedule !== null;
    const unscheduled = (schedule?.items ?? []).filter((item) => item.status === 'BELUM_DIJADWALKAN');
    const firstUnscheduled = unscheduled[0] ?? null;
    // "Simpan & lanjut": the next unscheduled item after the one open (RAB order), else the first left.
    const nextAfter = (id: number) => {
        const order = (schedule?.items ?? []).map((item) => item.id);
        const after = unscheduled.filter((item) => item.id !== id && order.indexOf(item.id) > order.indexOf(id));

        return after[0] ?? unscheduled.find((item) => item.id !== id) ?? null;
    };
    const today = schedule?.today ?? format(new Date(), 'yyyy-MM-dd');
    // The pengecekan sent and waiting for the PM: its % stay visible (marked) and the PM decides right here.
    const pending = execution.weeklyReports.find((report) => report.status === 'DIAJUKAN') ?? null;
    const pendingDate = pending?.submitted_at ? format(parseISO(pending.submitted_at), 'yyyy-MM-dd') : null;
    const [deciding, setDeciding] = useState<ExecutionDecisionTarget | null>(null);
    // Nothing new is typed while this week's last pengecekan waits for the PM.
    const typing = state.showsCheck && workCheck !== null && execution.currentWeek !== null && (state.weekOpen || pending === null);

    if (rows.length === 0) {
        return (
            <SectionCard title="Lembar Progres" icon={Sheet}>
                <EmptyState title="Belum ada item berupah." description="Lembar Progres terisi setelah Satuan Upah tiap item diisi di Upah Tukang." />
            </SectionCard>
        );
    }

    const table = (input?: SheetInput) => (
        <SheetTable
            rows={rows}
            execution={execution}
            today={today}
            pendingDate={pendingDate}
            input={input}
            onEdit={canSchedule ? (id) => setEditing(schedule.items.find((item) => item.id === id) ?? null) : undefined}
        />
    );

    return (
        <SectionCard
            title="Lembar Progres"
            description="Jadwal & progres tiap item — seperti lembar Simulasi Persentasi."
            icon={Sheet}
            action={
                canSchedule ? (
                    <Button size="sm" variant="outline" onClick={() => setSequencing(true)}>
                        <CalendarRange className="size-4" />
                        Jadwalkan Berurutan
                    </Button>
                ) : undefined
            }
        >
            {schedule && (schedule.summary.late > 0 || schedule.summary.unscheduled > 0) && (
                <div className="mb-4 space-y-2">
                    {schedule.summary.late > 0 && (
                        <Notice tone="error">{schedule.summary.late} item terlambat dari jadwalnya (jadwal sudah lewat, progres belum 100 %).</Notice>
                    )}
                    {schedule.summary.unscheduled > 0 &&
                        (canSchedule && firstUnscheduled ? (
                            // Sprint 33 — the next thing to do, not just a count: open the first unscheduled item (already filled in).
                            <div className="flex flex-col gap-3 rounded-xl border-2 border-daiku-yellow bg-daiku-yellow-light p-4 sm:flex-row sm:items-center">
                                <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-daiku-yellow text-daiku-dark">
                                    <CalendarRange className="size-5" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="font-semibold text-foreground">
                                        Atur jadwal kerja · {schedule.summary.unscheduled} dari {schedule.summary.total} item belum dijadwalkan
                                    </p>
                                    <p className="text-sm text-daiku-muted">
                                        Mulai dari <span className="font-medium text-foreground">{firstUnscheduled.description}</span> — tanggal sudah
                                        diusulkan (setelah item sebelumnya selesai), tinggal periksa lalu "Simpan & lanjut". Atau jadwalkan semuanya
                                        sekaligus.
                                    </p>
                                </div>
                                <div className="flex flex-col gap-2 sm:flex-row">
                                    <Button onClick={() => setEditing(firstUnscheduled)}>Atur {firstUnscheduled.description}</Button>
                                    <Button variant="outline" onClick={() => setSequencing(true)}>
                                        Sekaligus berurutan
                                    </Button>
                                </div>
                            </div>
                        ) : (
                            <Notice tone="info">
                                {schedule.summary.unscheduled} dari {schedule.summary.total} item belum dijadwalkan — diisi Pengawas.
                            </Notice>
                        ))}
                </div>
            )}

            {pending && (
                <PendingCheckBanner
                    report={pending}
                    canDecide={can.decide}
                    sentBySelf={state.showsCheck}
                    onDecide={() => setDeciding(decisionTargetOf(pending, execution))}
                />
            )}

            {typing && state.weekOpen && <TodayPrompt label={state.nextLabel} />}

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
                    <PhoneCards
                        rows={rows}
                        pendingDate={pendingDate}
                        onEdit={canSchedule ? (id) => setEditing(schedule.items.find((item) => item.id === id) ?? null) : undefined}
                    />
                    <div className="hidden md:block">{table()}</div>
                    {recap && recap.total !== null && (
                        <p className="mt-3 text-sm text-daiku-muted md:hidden">Progres proyek {formatProgress(recap.total)}</p>
                    )}
                </>
            )}

            {canSchedule && (
                <>
                    <ItemScheduleDialog
                        item={editing}
                        schedule={schedule}
                        onOpenChange={(open) => !open && setEditing(null)}
                        next={editing ? nextAfter(editing.id) : null}
                        onNext={setEditing}
                    />
                    <SequenceScheduleDialog open={sequencing} onOpenChange={setSequencing} projectId={projectId} schedule={schedule} />
                </>
            )}
            <ExecutionDecisionDialog target={deciding} onOpenChange={(open) => !open && setDeciding(null)} />
        </SectionCard>
    );
}

function SheetTable({
    rows,
    execution,
    today,
    pendingDate,
    input,
    onEdit,
}: {
    rows: Row[];
    execution: ProjectExecution;
    today: string;
    pendingDate: string | null;
    input?: SheetInput;
    onEdit?: (id: number) => void;
}) {
    const { workSchedule: schedule, progressRecap: recap, workCheck } = execution;
    const showMoney = recap !== null;

    // Continuous days over the schedule, the pengecekan dates and (when typing) today; else just those dates.
    const days = useMemo(() => {
        const marks = [...(recap?.dates ?? []), ...(input ? [today] : [])];
        const bounds = [schedule?.from, schedule?.to, ...marks].filter((day): day is string => Boolean(day)).sort();
        if (bounds.length === 0) return [];
        const range = dayRange(bounds[0], bounds[bounds.length - 1]);

        return schedule?.from && range.length <= MAX_DAYS ? range : Array.from(new Set(marks)).sort();
    }, [schedule?.from, schedule?.to, recap?.dates, input, today]);

    const cumulative = new Map((recap?.per_date ?? []).map((row) => [row.date, row.cumulative]));
    const scroller = useRef<HTMLDivElement>(null);

    // Today's column in view (a long project's grid is wider than the screen) — once, on open.
    useEffect(() => {
        const box = scroller.current;
        const cell = box?.querySelector<HTMLElement>('th[data-today]');
        if (!box || !cell) return;
        // Only when it is out of sight — otherwise the figures on the left stay in view.
        const overflow = cell.offsetLeft + cell.offsetWidth - box.clientWidth;
        if (overflow > 0) box.scrollLeft = overflow + 16;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
    const checkItems = new Map((workCheck?.items ?? []).map((item) => [item.id, item]));

    return (
        <div ref={scroller} className="overflow-x-auto rounded-lg ring-1 ring-border">
            <table className="text-sm">
                <thead>
                    <tr className="bg-daiku-yellow-light text-xs text-daiku-muted">
                        <th className="sticky left-0 z-10 w-60 min-w-60 max-w-60 bg-daiku-yellow-light px-3 py-2 text-left font-semibold uppercase">
                            Item RAB
                        </th>
                        <th className="sticky left-60 z-10 w-20 min-w-20 border-r border-border bg-daiku-yellow-light px-2 py-2 text-right font-semibold uppercase">
                            Total
                        </th>
                        {showMoney && <th className="px-2 py-2 text-right font-semibold uppercase">Bobot</th>}
                        {showMoney && <th className="px-2 py-2 text-right font-semibold uppercase">Kontribusi</th>}
                        {schedule && <th className="px-2 py-2 text-left font-semibold uppercase">Status</th>}
                        {onEdit && <th className="border-r border-border px-2 py-2" aria-label="Jadwal" />}
                        {days.map((day, index) => {
                            const date = parseISO(day);
                            const isToday = day === today;

                            return (
                                <th
                                    key={day}
                                    data-today={isToday || undefined}
                                    className={cn(
                                        'px-0 py-2 text-center font-medium tabular-nums',
                                        isToday && input
                                            ? 'min-w-24 border-x-2 border-daiku-yellow-dark bg-daiku-yellow text-daiku-dark'
                                            : 'w-11 min-w-11',
                                        isToday && !input && 'bg-warning/10 text-foreground',
                                        !isToday && date.getDay() === 0 && 'bg-daiku-gray/70',
                                    )}
                                    title={format(date, 'EEEE, d MMMM yyyy', {
                                        locale: localeId,
                                    })}
                                >
                                    {isToday && input ? (
                                        <span className="flex flex-col items-center leading-tight">
                                            <span className="text-[10px] font-bold uppercase">Isi hari ini</span>
                                            <span className="font-semibold">
                                                {format(date, 'd MMM', {
                                                    locale: localeId,
                                                })}
                                            </span>
                                        </span>
                                    ) : (
                                        <>
                                            {(index === 0 || date.getDate() === 1) && (
                                                <span className="block text-[10px] uppercase">
                                                    {format(date, 'MMM', {
                                                        locale: localeId,
                                                    })}
                                                </span>
                                            )}
                                            {date.getDate()}
                                        </>
                                    )}
                                </th>
                            );
                        })}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => {
                        const checkItem = checkItems.get(row.id);
                        const newTotal = input && checkItem ? input.preview.progress[row.id] : undefined;
                        const changed = newTotal !== undefined && newTotal !== row.total;

                        return (
                            <tr key={row.id} className="border-t border-border">
                                <td className="sticky left-0 z-10 w-60 min-w-60 max-w-60 bg-background px-3 py-2 align-top">
                                    <p className="font-medium text-foreground">{row.description}</p>
                                    <p className="text-xs text-daiku-muted">
                                        {row.volume_label} · {row.team_lead}
                                    </p>
                                </td>
                                <td className="sticky left-60 z-10 w-20 min-w-20 border-r border-border bg-background px-2 py-2 text-right font-medium tabular-nums">
                                    {changed ? (
                                        <>
                                            <span className="block text-foreground">{formatProgress(newTotal)}</span>
                                            <span className="block text-[11px] font-normal whitespace-nowrap text-daiku-muted">
                                                dari {formatProgress(row.total)}
                                            </span>
                                        </>
                                    ) : (
                                        formatProgress(row.total)
                                    )}
                                </td>
                                {showMoney && (
                                    <td className="px-2 py-2 text-right text-daiku-muted tabular-nums">
                                        {row.weight !== null ? formatProgress(row.weight) : '—'}
                                    </td>
                                )}
                                {showMoney && (
                                    <td className="px-2 py-2 text-right tabular-nums">
                                        {row.contribution !== null ? formatProgress(row.contribution) : '—'}
                                    </td>
                                )}
                                {schedule && (
                                    <td className="px-2 py-2 whitespace-nowrap">
                                        {row.status && (
                                            <StatusChip
                                                status={row.status}
                                                tone={SCHEDULE_STATUS[row.status].tone}
                                                label={SCHEDULE_STATUS[row.status].label}
                                            />
                                        )}
                                    </td>
                                )}
                                {onEdit && (
                                    <td className="border-r border-border px-1 py-2">
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            className="size-8"
                                            aria-label={`Atur jadwal ${row.description}`}
                                            onClick={() => onEdit(row.id)}
                                        >
                                            <Pencil className="size-4" />
                                        </Button>
                                    </td>
                                )}
                                {days.map((day) => {
                                    const production = inside(day, row.production_start, row.production_end);
                                    const installation = inside(day, row.installation_start, row.installation_end);
                                    const isToday = day === today;

                                    return (
                                        <td
                                            key={day}
                                            className={cn(
                                                'px-0 py-1 text-center align-middle',
                                                isToday && input
                                                    ? 'border-x-2 border-daiku-yellow-dark bg-daiku-yellow-light px-2'
                                                    : [
                                                          production && 'bg-info/25',
                                                          installation && 'bg-success/25',
                                                          isToday && !production && !installation && 'bg-warning/10',
                                                      ],
                                            )}
                                        >
                                            {isToday && input && checkItem ? (
                                                // An earlier pengecekan of today stays readable above the new input.
                                                <span className="flex flex-col items-center gap-1">
                                                    {row.cells[day] ? (
                                                        <span className="text-[10px] font-medium whitespace-nowrap text-daiku-muted">
                                                            sudah {added(row.cells[day])}
                                                        </span>
                                                    ) : null}
                                                    {input.field(checkItem)}
                                                </span>
                                            ) : (
                                                <CellValue value={row.cells[day]} pending={day === pendingDate} />
                                            )}
                                        </td>
                                    );
                                })}
                            </tr>
                        );
                    })}
                </tbody>
                {recap && (
                    <tfoot className="border-t-2 border-border bg-daiku-gray/60">
                        <tr className="font-semibold">
                            <td className="sticky left-0 z-10 w-60 min-w-60 max-w-60 bg-daiku-gray px-3 py-2">Progres proyek</td>
                            <td className="sticky left-60 z-10 w-20 min-w-20 border-r border-border bg-daiku-gray px-2 py-2 text-right tabular-nums">
                                {formatProgress(input ? input.preview.weightedProgress : recap.total)}
                            </td>
                            {showMoney && <td />}
                            <td className="px-2 py-2 text-right tabular-nums">{formatProgress(recap.total)}</td>
                            {schedule && <td />}
                            {onEdit && <td className="border-r border-border" />}
                            {days.map((day) => {
                                const isToday = day === today;
                                const value = isToday && input ? input.preview.weightedProgress : cumulative.get(day);

                                return (
                                    <td
                                        key={day}
                                        className={cn(
                                            'px-0 py-2 text-center text-[11px] tabular-nums',
                                            isToday && input && 'border-x-2 border-daiku-yellow-dark bg-daiku-yellow-light text-info-ink',
                                        )}
                                    >
                                        {value !== undefined && value !== null ? formatProgress(value) : ''}
                                    </td>
                                );
                            })}
                        </tr>
                    </tfoot>
                )}
            </table>
            <Legend pending={pendingDate !== null} />
        </div>
    );
}

/** A % added on a pengecekan date: a small chip, centred in its day over the schedule colour; `pending` = sent, waiting for the PM. */
function CellValue({ value, pending = false }: { value: number | undefined; pending?: boolean }) {
    if (value === undefined || value === 0) return null;

    return (
        <span
            title={pending ? 'Terkirim — menunggu ACC PM' : undefined}
            className={cn(
                'inline-flex min-w-10 justify-center rounded-md px-1 py-0.5 text-[11px] font-semibold tabular-nums shadow-xs',
                pending ? 'bg-warning/20 text-warning-ink ring-1 ring-warning' : 'bg-background text-foreground ring-1 ring-border',
            )}
        >
            {added(value)}
        </span>
    );
}

/**
 * Sprint 31 — the pengecekan the Pengawas sent, waiting for the PM: what was
 * sent (per item, the money asked) and, for the PM, the decision right here.
 */
function PendingCheckBanner({
    report,
    canDecide,
    sentBySelf,
    onDecide,
}: {
    report: Report;
    canDecide: boolean;
    sentBySelf: boolean;
    onDecide: () => void;
}) {
    const asked = report.payment?.status === 'DIAJUKAN' ? report.payment : null;

    return (
        <div role="status" className="mb-4 flex flex-col gap-3 rounded-xl border-2 border-warning bg-warning/10 p-4 sm:flex-row sm:items-start">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-warning/25 text-warning-ink">
                <Hourglass className="size-5" />
            </span>
            <div className="min-w-0 flex-1 space-y-1.5">
                <p className="font-semibold text-foreground">
                    {canDecide ? 'Pengecekan menunggu ACC Anda' : 'Pengecekan terkirim — menunggu ACC PM'}
                </p>
                <p className="text-sm text-daiku-muted">
                    {report.label} · dikirim {formatDateTime(report.submitted_at)}
                    {report.supervisor ? ` oleh ${report.supervisor}` : ''}
                    {asked ? ` · mengajukan upah ${formatRupiah(asked.amount)}` : ''}
                </p>
                {report.progress.length > 0 && (
                    <div className="flex flex-wrap gap-1.5">
                        {report.progress.map((row, index) => (
                            <span
                                key={index}
                                className="rounded-md bg-background px-2 py-0.5 text-xs text-foreground tabular-nums ring-1 ring-warning"
                            >
                                {row.description ?? 'Item'} <span className="font-semibold">{added(row.percent)}</span>
                            </span>
                        ))}
                    </div>
                )}
                {!canDecide && sentBySelf && (
                    <p className="text-xs text-daiku-muted">
                        Persen yang dikirim tampil di sel bertanda oranye. Pengecekan berikutnya bisa diisi setelah PM memutuskan.
                    </p>
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
function TodayPrompt({ label }: { label: string }) {
    const [closed, setClosed] = useState(false);

    if (closed) return null;

    function start() {
        const inputs = Array.from(document.querySelectorAll<HTMLInputElement>('input[aria-label^="Tambahan persen"]')).filter(
            (input) => input.offsetParent !== null && !input.disabled,
        );
        const target = inputs.find((input) => input.value.trim() === '') ?? inputs[0];
        target?.scrollIntoView({
            behavior: 'smooth',
            block: 'center',
            inline: 'center',
        });
        target?.focus({ preventScroll: true });
    }

    return (
        <div
            role="status"
            className="mb-4 flex flex-col gap-3 rounded-xl border-2 border-daiku-yellow bg-daiku-yellow-light p-4 sm:flex-row sm:items-center"
        >
            <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-daiku-yellow text-daiku-dark">
                <PencilLine className="size-5" />
            </span>
            <div className="min-w-0 flex-1">
                <p className="font-semibold text-foreground">Isi persentase progres hari ini</p>
                <p className="text-sm text-daiku-muted">
                    Pengecekan {label}: ketik tambahan % di kolom kuning <span className="font-medium text-foreground">Isi hari ini</span> hanya untuk
                    item yang dikerjakan — <span className="font-medium text-foreground">item lain biarkan kosong</span>. Lalu tekan{' '}
                    <span className="font-medium text-foreground">Kirim Pengecekan</span> di bawah. Isian tersimpan otomatis sebagai draf.
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

function Legend({ pending }: { pending: boolean }) {
    const swatch = (className: string, label: ReactNode) => (
        <span className="inline-flex items-center gap-1.5">
            <span className={cn('inline-block size-3 rounded-sm', className)} /> {label}
        </span>
    );

    return (
        <p className="flex flex-wrap items-center gap-4 border-t border-border px-3 py-2 text-xs text-daiku-muted">
            {swatch('bg-info/25', 'Produksi')}
            {swatch('bg-success/25', 'Pemasangan')}
            {swatch('bg-daiku-yellow ring-1 ring-daiku-yellow-dark', 'Isi hari ini')}
            {pending && swatch('bg-warning/20 ring-1 ring-warning', 'Terkirim, menunggu ACC PM')}
            <span>Angka di sel = persen yang ditambahkan pada pengecekan tanggal itu.</span>
        </p>
    );
}

/** Phones: one card per item — schedule, status, total, the dates it moved. */
function PhoneCards({ rows, pendingDate, onEdit }: { rows: Row[]; pendingDate: string | null; onEdit?: (id: number) => void }) {
    return (
        <ul className="divide-y divide-border rounded-lg ring-1 ring-border md:hidden">
            {rows.map((row) => (
                <li key={row.id} className="space-y-1.5 px-3 py-3">
                    <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                            <p className="text-sm font-medium text-foreground">{row.description}</p>
                            <p className="text-xs text-daiku-muted">
                                {row.team_lead} · total {formatProgress(row.total)}
                                {row.contribution !== null && ` · kontribusi ${formatProgress(row.contribution)}`}
                            </p>
                        </div>
                        {row.status && (
                            <StatusChip status={row.status} tone={SCHEDULE_STATUS[row.status].tone} label={SCHEDULE_STATUS[row.status].label} />
                        )}
                    </div>
                    <p className="text-xs text-daiku-muted">
                        Produksi {rangeLabel(row.production_start, row.production_end)} · Pemasangan{' '}
                        {rangeLabel(row.installation_start, row.installation_end)}
                    </p>
                    {Object.keys(row.cells).length > 0 && (
                        <div className="flex flex-wrap gap-1.5">
                            {Object.entries(row.cells)
                                .sort(([a], [b]) => a.localeCompare(b))
                                .map(([date, value]) => (
                                    <span
                                        key={date}
                                        className={cn(
                                            'rounded-md px-2 py-0.5 text-xs tabular-nums',
                                            date === pendingDate
                                                ? 'bg-warning/20 text-warning-ink ring-1 ring-warning'
                                                : 'bg-daiku-gray text-daiku-muted',
                                        )}
                                    >
                                        {format(parseISO(date), 'd MMM', {
                                            locale: localeId,
                                        })}{' '}
                                        {added(value)}
                                    </span>
                                ))}
                        </div>
                    )}
                    {onEdit && (
                        <Button size="sm" variant="outline" className="w-full" onClick={() => onEdit(row.id)}>
                            <Pencil className="size-4" />
                            Atur Jadwal
                        </Button>
                    )}
                </li>
            ))}
        </ul>
    );
}
