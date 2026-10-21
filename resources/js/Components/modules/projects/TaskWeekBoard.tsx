import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Textarea } from '@/Components/ui/textarea';
import { useConfirm } from '@/hooks/useConfirm';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { type TaskReload, taskVisit } from '@/Components/modules/tasks/taskSave';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import { isWorkDay } from '@/lib/workDays';
import type { Milestone, Task, TaskStatus } from '@/types';
import { router } from '@inertiajs/react';
import { addDays, format, startOfWeek } from 'date-fns';
import { AlertCircle, Check, CheckCheck, ChevronLeft, ChevronRight, GripVertical, Loader2, Lock, MoveRight, Undo2, User } from 'lucide-react';
import { type DragEvent, useMemo, useState } from 'react';

const DAY_HEAD = new Intl.DateTimeFormat('id-ID', { weekday: 'short', day: 'numeric', month: 'short' });
// Column header: "Sen 19" — the week's range is printed above the board.
const DAY_SHORT = new Intl.DateTimeFormat('id-ID', { weekday: 'short', day: 'numeric' });
const ALL = 'all';

const ymd = (date: Date) => format(date, 'yyyy-MM-dd');
const parse = (value: string) => new Date(`${value.slice(0, 10)}T00:00:00`);

interface TaskWeekBoardProps {
    tasks: Task[];
    milestones: Milestone[];
    /** PM / Asisten PM / the project's Pengawas on an open project — may move targets. */
    canMove: boolean;
    /** Sprint 29 L5 — page props a move reloads (Detail Proyek: `RELOAD.tasks`); omitted = full reload. */
    reload?: TaskReload;
    /** Sprint 29 Sub 04 — the project's Pengawas / PM: Setujui, Kembalikan, Centang selesai (TaskPolicy::review()). */
    canReview?: boolean;
}

/**
 * Sprint 24 D9 — daily targets laid out per day (Mon → Sun). Drag a card
 * onto another day — or, on a phone, tap the card then "Pindah ke sini" —
 * to move it. Same rule as "Geser Tanggal" (tasks.reschedule): only an
 * unfinished target, only inside its timeline and only onto a working day
 * (Sprint 28 K13 — no Minggu; days outside are greyed while moving; the
 * server checks again), the Tukang is told.
 * Sprint 29 L5 — the card moves at once (optimistic) and the save reloads
 * only `reload`; a refused move puts it back on its old day with the
 * server's reason under the board's hint (and as a toast).
 */
export function TaskWeekBoard({ tasks, milestones, canMove, reload, canReview = false }: TaskWeekBoardProps) {
    const today = ymd(new Date());
    const [weekStart, setWeekStart] = useState(() => startOfWeek(new Date(), { weekStartsOn: 1 }));
    const [milestoneFilter, setMilestoneFilter] = useState(ALL);
    const [assigneeFilter, setAssigneeFilter] = useState(ALL);
    // The card being moved (dragged, or tapped on a phone).
    const [moving, setMoving] = useState<Task | null>(null);
    const [overDay, setOverDay] = useState<string | null>(null);
    // Shown in their new day until the server answers.
    const [pending, setPending] = useState<Record<number, string>>({});
    // The last refused move — its card is back on its old day.
    const [refused, setRefused] = useState<string | null>(null);
    // Sprint 29 Sub 04 — reviewed cards show their new status until the server answers (K3).
    const [reviewing, setReviewing] = useState<Record<number, TaskStatus>>({});
    const [returning, setReturning] = useState<Task | null>(null);
    const { ask, dialog } = useConfirm();

    const days = useMemo(() => Array.from({ length: 7 }, (_, index) => ymd(addDays(weekStart, index))), [weekStart]);

    const assignees = useMemo(() => {
        const byId = new Map<number, string>();
        tasks.forEach((task) => task.assignee_id && byId.set(task.assignee_id, task.assignee?.name ?? '—'));

        return [...byId.entries()].sort((a, b) => a[1].localeCompare(b[1]));
    }, [tasks]);

    const columns = useMemo(() => {
        const visible = tasks.filter(
            (task) =>
                (milestoneFilter === ALL || String(task.milestone_id) === milestoneFilter) &&
                (assigneeFilter === ALL || String(task.assignee_id) === assigneeFilter),
        );

        return days.map((day) => ({
            day,
            tasks: visible
                .map((task) => (task.id in reviewing ? { ...task, status: reviewing[task.id] } : task))
                .filter((task) => (pending[task.id] ?? task.due_date?.slice(0, 10)) === day)
                .sort((a, b) => (a.assignee?.name ?? '').localeCompare(b.assignee?.name ?? '') || a.title.localeCompare(b.title)),
        }));
    }, [tasks, days, milestoneFilter, assigneeFilter, pending, reviewing]);

    const movable = (task: Task) => canMove && task.status !== 'DONE' && !(task.id in pending);

    /** Sprint 29 Sub 05 (F7) — any working day; the server checks again. */
    function accepts(day: string): boolean {
        return moving !== null && isWorkDay(day);
    }

    /** The timeline a moved card would join on `day` when it isn't its own (Q29 — same rule as TaskService::timelineOn()). */
    function otherTimeline(day: string): Milestone | null {
        if (!moving) return null;
        const inside = (milestone: Partial<Pick<Milestone, 'start_date' | 'target_date'>> | null | undefined) =>
            Boolean(milestone?.start_date && milestone?.target_date && day >= milestone.start_date.slice(0, 10) && day <= milestone.target_date.slice(0, 10));

        if (inside(moving.milestone)) return null;

        return milestones.filter((milestone) => milestone.status !== 'COMPLETED').find((milestone) => inside(milestone)) ?? null;
    }

    function move(task: Task, day: string) {
        setMoving(null);
        setOverDay(null);

        if (task.due_date?.slice(0, 10) === day || !accepts(day) || task.id in pending) return;

        setRefused(null);
        setPending((current) => ({ ...current, [task.id]: day }));
        router.patch(route('tasks.reschedule', { task: task.id }), { due_date: day, note: null }, {
            ...taskVisit(reload, { quiet: true }),
            preserveState: true,
            onError: (errors) => setRefused(`"${task.title}" kembali ke ${formatDate(task.due_date)}: ${Object.values(errors)[0] ?? 'gagal digeser.'}`),
            onFinish: () =>
                setPending((current) => {
                    const { [task.id]: _done, ...rest } = current;

                    return rest;
                }),
        });
    }

    /** Sprint 29 Sub 04 — Setujui / Centang (one card) or Setujui semua (a day); shown as Selesai at once. */
    function review(url: string, ids: number[], data: { task_ids?: number[] } = {}) {
        setRefused(null);
        setReviewing((current) => ({ ...current, ...Object.fromEntries(ids.map((id) => [id, 'DONE' as TaskStatus])) }));
        router.post(url, data, {
            ...taskVisit(reload, { quiet: true }),
            preserveState: true,
            onError: (errors) => setRefused(Object.values(errors)[0] ?? 'Gagal disimpan.'),
            onFinish: () =>
                setReviewing((current) => Object.fromEntries(Object.entries(current).filter(([id]) => !ids.includes(Number(id))))),
        });
    }

    async function approveDay(dayTasks: Task[], day: string) {
        const waiting = dayTasks.filter((task) => task.status === 'PENGECEKAN');

        if (
            waiting.length === 0 ||
            !(await ask({
                title: `Setujui ${waiting.length} target?`,
                description: `Semua target "Menunggu dicek" ${DAY_HEAD.format(parse(day))}${assigneeFilter === ALL ? '' : ` milik ${waiting[0].assignee?.name ?? 'tukang ini'}`} ditandai Selesai.`,
                confirmLabel: 'Ya, setujui semua',
                tone: 'default',
            }))
        ) {
            return;
        }

        review(route('tasks.approveMany', { project: waiting[0].project_id }), waiting.map((task) => task.id), { task_ids: waiting.map((task) => task.id) });
    }

    function onDrop(event: DragEvent, day: string) {
        event.preventDefault();
        if (moving) move(moving, day);
    }

    const weekLabel = `${formatDate(days[0])} – ${formatDate(days[6])}`;

    return (
        <div>
            <div className="mb-3 flex flex-wrap items-center gap-2">
                <div className="flex items-center gap-1">
                    <Button variant="outline" size="icon-sm" aria-label="Minggu sebelumnya" onClick={() => setWeekStart((date) => addDays(date, -7))}>
                        <ChevronLeft className="size-4" />
                    </Button>
                    <Button variant="outline" size="icon-sm" aria-label="Minggu berikutnya" onClick={() => setWeekStart((date) => addDays(date, 7))}>
                        <ChevronRight className="size-4" />
                    </Button>
                    <Button variant="ghost" size="sm" onClick={() => setWeekStart(startOfWeek(new Date(), { weekStartsOn: 1 }))}>
                        Minggu ini
                    </Button>
                </div>
                <p className="text-sm font-medium text-foreground">{weekLabel}</p>
                <div className="flex flex-wrap gap-2 sm:ml-auto">
                    <Select value={milestoneFilter} onValueChange={setMilestoneFilter}>
                        <SelectTrigger className="w-48" aria-label="Filter timeline">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>Semua timeline</SelectItem>
                            {milestones.map((milestone) => (
                                <SelectItem key={milestone.id} value={String(milestone.id)}>
                                    {milestone.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select value={assigneeFilter} onValueChange={setAssigneeFilter}>
                        <SelectTrigger className="w-44" aria-label="Filter tukang">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ALL}>Semua tukang</SelectItem>
                            {assignees.map(([id, name]) => (
                                <SelectItem key={id} value={String(id)}>
                                    {name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            </div>

            {canMove && (
                <p className="mb-3 text-xs text-muted-foreground">
                    {moving
                        ? `Memindahkan "${moving.title}" — pilih hari kerja mana saja; hari di timeline lain memindah target ke timeline itu.`
                        : 'Seret kartu ke hari lain untuk menggeser target (di HP: ketuk kartu, lalu "Pindah ke sini"). Semua hari kerja (Senin–Sabtu).'}
                </p>
            )}
            {refused && (
                <p role="alert" className="mb-3 flex items-start gap-1.5 text-xs font-medium text-error-ink">
                    <AlertCircle className="mt-px size-3.5 shrink-0" aria-hidden />
                    {refused}
                </p>
            )}

            <div className="grid auto-cols-[minmax(9.5rem,1fr)] grid-flow-col gap-2 overflow-x-auto pb-2 xl:grid-flow-row xl:grid-cols-7 xl:overflow-visible">
                {columns.map(({ day, tasks: dayTasks }) => {
                    const allowed = moving ? accepts(day) && moving.due_date?.slice(0, 10) !== day : false;
                    const blocked = moving !== null && !accepts(day);

                    return (
                        <section
                            key={day}
                            aria-label={`${DAY_HEAD.format(parse(day))} (${dayTasks.length})`}
                            onDragOver={(event) => {
                                if (!allowed) return;
                                event.preventDefault();
                                event.dataTransfer.dropEffect = 'move';
                                setOverDay(day);
                            }}
                            onDragLeave={() => setOverDay((current) => (current === day ? null : current))}
                            onDrop={(event) => onDrop(event, day)}
                            className={cn(
                                'flex min-h-36 min-w-0 flex-col rounded-lg bg-daiku-gray p-1.5 transition-colors',
                                day === today && 'ring-2 ring-daiku-yellow',
                                blocked && 'opacity-45',
                                allowed && 'outline-2 outline-offset-2 outline-info/50 outline-dashed',
                                overDay === day && allowed && 'bg-info/10',
                            )}
                        >
                            <header className="mb-1.5 flex items-center justify-between gap-1 px-1">
                                <span className={cn('truncate text-sm font-medium', day === today ? 'text-foreground' : 'text-daiku-muted')}>
                                    {DAY_SHORT.format(parse(day))}
                                    {day === today && <span className="ml-1 text-xs font-normal">· hari ini</span>}
                                </span>
                                <span className="text-xs font-medium text-daiku-muted tabular-nums">{dayTasks.length}</span>
                            </header>

                            {canReview && dayTasks.filter((task) => task.status === 'PENGECEKAN').length > 1 && (
                                <Button size="sm" variant="outline" className="mb-2 h-auto min-h-9 whitespace-normal" onClick={() => approveDay(dayTasks, day)}>
                                    <CheckCheck className="size-4" />
                                    Setujui semua ({dayTasks.filter((task) => task.status === 'PENGECEKAN').length})
                                </Button>
                            )}

                            {allowed && (
                                <button
                                    type="button"
                                    onClick={() => moving && move(moving, day)}
                                    className="mb-2 flex items-center justify-center gap-1 rounded-lg border border-dashed border-info/50 px-2 py-2 text-xs font-medium text-info-ink hover:bg-info/10"
                                >
                                    <MoveRight className="size-3.5" />
                                    Pindah ke sini
                                </button>
                            )}
                            {allowed && otherTimeline(day) && (
                                <p className="mb-2 px-1 text-center text-[11px] text-info-ink">→ timeline {otherTimeline(day)?.name}</p>
                            )}
                            {blocked && <p className="mb-2 px-1 text-center text-xs text-daiku-muted">Bukan hari kerja</p>}

                            <div className="flex flex-col gap-2">
                                {dayTasks.length === 0 && !allowed && <p className="px-1 py-4 text-center text-xs text-daiku-muted">Kosong</p>}
                                {dayTasks.map((task) => (
                                    <TargetCard
                                        key={task.id}
                                        task={task}
                                        today={today}
                                        movable={movable(task)}
                                        selected={moving?.id === task.id}
                                        saving={task.id in pending || task.id in reviewing}
                                        showTimeline={milestoneFilter === ALL}
                                        actions={
                                            canReview ? (
                                                <ReviewActions
                                                    task={task}
                                                    busy={task.id in reviewing}
                                                    onApprove={() => review(route('tasks.approve', { task: task.id }), [task.id])}
                                                    onCheck={() => review(route('tasks.checkOff', { task: task.id }), [task.id])}
                                                    onReturn={() => setReturning(task)}
                                                />
                                            ) : null
                                        }
                                        onPick={() => setMoving((current) => (current?.id === task.id ? null : task))}
                                        onDragStart={(event) => {
                                            event.dataTransfer.effectAllowed = 'move';
                                            event.dataTransfer.setData('text/plain', String(task.id));
                                            setMoving(task);
                                        }}
                                        onDragEnd={() => {
                                            setMoving(null);
                                            setOverDay(null);
                                        }}
                                    />
                                ))}
                            </div>
                        </section>
                    );
                })}
            </div>

            <ReturnDialog task={returning} reload={reload} onClose={() => setReturning(null)} />
            {dialog}
        </div>
    );
}

/** Sprint 29 Sub 04 (F2/F3) — a card's check buttons: Setujui / Kembalikan a reported target, or Centang one the Tukang didn't touch. */
function ReviewActions({ task, busy, onApprove, onCheck, onReturn }: { task: Task; busy: boolean; onApprove: () => void; onCheck: () => void; onReturn: () => void }) {
    if (task.status === 'DONE') return null;

    // The card itself picks a target to move — the buttons must not.
    const stop = (handler: () => void) => (event: React.MouseEvent) => {
        event.stopPropagation();
        handler();
    };

    if (task.status === 'PENGECEKAN') {
        return (
            <div className="mt-2 grid grid-cols-2 gap-1.5">
                <Button size="sm" className="h-9 max-md:h-11" disabled={busy} onClick={stop(onApprove)}>
                    {busy ? <Loader2 className="size-4 animate-spin" /> : <Check className="size-4" />}
                    Setujui
                </Button>
                <Button size="sm" variant="outline" className="h-9 max-md:h-11" disabled={busy} onClick={stop(onReturn)}>
                    <Undo2 className="size-4" />
                    Kembalikan
                </Button>
            </div>
        );
    }

    return (
        <Button size="sm" variant="outline" className="mt-2 h-9 w-full max-md:h-11" disabled={busy} onClick={stop(onCheck)} title="Untuk tukang yang tidak memakai HP — dicatat sebagai laporan hari ini">
            {busy ? <Loader2 className="size-4 animate-spin" /> : <Check className="size-4" />}
            Centang selesai
        </Button>
    );
}

/** Sprint 29 Sub 04 (F2) — "Kembalikan": the reason goes to the Tukang (ReturnTaskRequest). */
function ReturnDialog({ task, reload, onClose }: { task: Task | null; reload?: TaskReload; onClose: () => void }) {
    const [note, setNote] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [saving, setSaving] = useState(false);

    function submit() {
        if (!task) return;
        if (note.trim().length < 3) {
            setError('Tulis alasan target dikembalikan (minimal 3 karakter).');
            return;
        }

        router.post(route('tasks.return', { task: task.id }), { note: note.trim() }, {
            ...taskVisit(reload, { quiet: true }),
            preserveState: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onError: (errors) => setError(errors.note ?? Object.values(errors)[0] ?? 'Gagal disimpan.'),
            onSuccess: () => {
                setNote('');
                setError(null);
                onClose();
            },
        });
    }

    return (
        <Dialog open={task !== null} onOpenChange={(open) => !open && !saving && onClose()}>
            <ResponsiveDialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Kembalikan target?</DialogTitle>
                    <DialogDescription>
                        "{task?.title}" kembali ke {task?.assignee?.name ?? 'tukang'} (Dikerjakan). Alasannya dikirim ke tukang.
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-1.5">
                    <Textarea
                        rows={3}
                        value={note}
                        onChange={(event) => setNote(event.target.value)}
                        placeholder="Contoh: sambungan HPL belum rapi, perbaiki sisi kiri."
                        aria-label="Alasan dikembalikan"
                        autoFocus
                    />
                    {error && <p className="text-xs font-medium text-error-ink">{error}</p>}
                </div>
                <DialogFooter>
                    <Button variant="outline" disabled={saving} onClick={onClose}>
                        Batal
                    </Button>
                    <Button disabled={saving} onClick={submit}>
                        {saving && <Loader2 className="size-4 animate-spin" />}
                        Kembalikan
                    </Button>
                </DialogFooter>
            </ResponsiveDialogContent>
        </Dialog>
    );
}

function TargetCard({
    task,
    today,
    movable,
    selected,
    saving,
    showTimeline,
    actions,
    onPick,
    onDragStart,
    onDragEnd,
}: {
    task: Task;
    today: string;
    movable: boolean;
    selected: boolean;
    saving: boolean;
    showTimeline: boolean;
    actions?: React.ReactNode;
    onPick: () => void;
    onDragStart: (event: DragEvent) => void;
    onDragEnd: () => void;
}) {
    const late = task.status !== 'DONE' && task.due_date !== null && task.due_date.slice(0, 10) < today;

    return (
        <div
            role={movable ? 'button' : undefined}
            tabIndex={movable ? 0 : undefined}
            aria-pressed={movable ? selected : undefined}
            draggable={movable}
            onDragStart={onDragStart}
            onDragEnd={onDragEnd}
            onClick={movable ? onPick : undefined}
            onKeyDown={(event) => {
                if (movable && (event.key === 'Enter' || event.key === ' ')) {
                    event.preventDefault();
                    onPick();
                }
            }}
            className={cn(
                'min-w-0 rounded-lg border border-border bg-card p-2 text-left shadow-xs',
                movable && 'cursor-grab transition-colors hover:border-daiku-yellow-dark focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none active:cursor-grabbing',
                selected && 'border-daiku-yellow-dark ring-2 ring-daiku-yellow',
                saving && 'animate-pulse opacity-70',
                task.status === 'DONE' && 'opacity-70',
            )}
        >
            <div className="flex items-start gap-1.5">
                {movable ? (
                    <GripVertical className="mt-0.5 size-3.5 shrink-0 text-daiku-muted" aria-hidden />
                ) : (
                    <Lock className="mt-0.5 size-3.5 shrink-0 text-daiku-muted" aria-label="Tidak bisa digeser" />
                )}
                <div className="min-w-0 flex-1">
                    <p className="text-[13px] leading-snug font-medium break-words text-daiku-dark">{task.title}</p>
                    {showTimeline && task.milestone && <p className="mt-0.5 truncate text-xs text-daiku-muted">{task.milestone.name}</p>}
                </div>
            </div>
            <div className="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-daiku-muted">
                <span className="flex min-w-0 items-center gap-1">
                    <User className="size-3 shrink-0" aria-hidden />
                    <span className="truncate">{task.assignee?.name ?? 'Belum ditugaskan'}</span>
                </span>
                <StatusChip domain="task" status={task.status} waitingOn={task.status === 'PENGECEKAN' ? undefined : null} className="text-[11px]" />
                {late && <span className="font-medium text-error-ink">Terlambat</span>}
            </div>
            {task.kendala && (
                <p className="mt-2 flex items-start gap-1 rounded bg-warning/10 px-1.5 py-1 text-xs text-daiku-dark">
                    <AlertCircle className="mt-0.5 size-3 shrink-0 text-warning-ink" aria-hidden />
                    <span className="line-clamp-2">Kendala: {task.kendala}</span>
                </p>
            )}
            {actions}
        </div>
    );
}
