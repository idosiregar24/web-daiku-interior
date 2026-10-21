import { StatusChip } from '@/Components/shared/StatusChip';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Task } from '@/types';
import { Check, CircleCheck, CircleX, ChevronRight, Loader2 } from 'lucide-react';

/** A Tukang's task as the phone screens show it (+ today's form mark where known). */
export type FieldTask = Pick<Task, 'id' | 'title' | 'status' | 'kendala' | 'note' | 'due_date' | 'project' | 'milestone'> & {
    /** Today's daily form sent — undefined where the page doesn't know (no mark shown). */
    has_form_today?: boolean;
};

const TODAY = () => new Date().toISOString().slice(0, 10);

/**
 * Sprint 13 H4 — a task as a tappable card instead of a table row:
 * title, project, status, due date and (on working days) whether today's
 * daily form is in. The whole card is the button (≥ 48px touch target).
 * Sprint 24 D7 — `onCheck` adds the big ✔ "Selesai" beside it: the check
 * IS the day's actual activity (no separate daily form).
 * Sprint 29 L3 — `saving`: the card already shows the new status
 * (optimistic, see useTaskStatusSave) and stays locked, the ✔ spinning,
 * until the server answers.
 */
export function TaskCard({
    task,
    onOpen,
    onCheck,
    saving = false,
}: {
    task: FieldTask;
    onOpen: (task: FieldTask) => void;
    onCheck?: (task: FieldTask) => void;
    saving?: boolean;
}) {
    const overdue = task.status === 'OVER' || (task.due_date !== null && task.due_date.slice(0, 10) < TODAY() && task.status !== 'DONE');

    return (
        <div className="flex w-full items-stretch gap-2" aria-busy={saving || undefined}>
        <button
            type="button"
            onClick={() => onOpen(task)}
            disabled={saving}
            className="flex min-h-12 min-w-0 flex-1 items-center gap-3 rounded-xl bg-card p-4 text-left shadow-xs ring-1 ring-border transition-colors active:bg-daiku-yellow-light/60 disabled:opacity-80"
        >
            <span className="min-w-0 flex-1">
                <span className="block font-medium text-foreground">{task.title}</span>
                <span className="mt-0.5 block truncate text-xs text-muted-foreground">
                    {task.project?.name}
                    {task.milestone?.name ? ` · ${task.milestone.name}` : ''}
                </span>
                <span className="mt-2 flex flex-wrap items-center gap-2">
                    {/* The Tukang's own card: name the holder only when it isn't them ("Minta dicek · PM / Asisten PM"). */}
                    <StatusChip domain="task" status={task.status} waitingOn={task.status === 'PENGECEKAN' ? undefined : null} />
                    {task.due_date && (
                        <span className={cn('text-xs', overdue ? 'font-medium text-error-ink' : 'text-muted-foreground')}>
                            Tenggat {formatDate(task.due_date)}
                        </span>
                    )}
                </span>
                {task.has_form_today !== undefined && (
                    <span
                        className={cn(
                            'mt-2 flex items-center gap-1 text-xs font-medium',
                            task.has_form_today ? 'text-success-ink' : 'text-warning-ink',
                        )}
                    >
                        {task.has_form_today ? <CircleCheck className="size-3.5" /> : <CircleX className="size-3.5" />}
                        {task.has_form_today ? 'Sudah dilaporkan hari ini' : 'Belum dilaporkan hari ini'}
                    </span>
                )}
            </span>
            {saving ? (
                <Loader2 className="size-5 shrink-0 animate-spin text-muted-foreground" aria-label="Menyimpan" />
            ) : (
                <ChevronRight className="size-5 shrink-0 text-muted-foreground/60" />
            )}
        </button>
        {/* Sprint 29 Sub 04 — once sent ("Menunggu dicek") the Pengawas decides; no second tap. */}
        {onCheck && ((task.status !== 'DONE' && task.status !== 'PENGECEKAN') || saving) && (
            <button
                type="button"
                onClick={() => onCheck(task)}
                disabled={saving}
                aria-label={saving ? `Menyimpan "${task.title}"` : `Tandai "${task.title}" selesai`}
                className="flex min-h-12 w-16 shrink-0 flex-col items-center justify-center gap-1 rounded-xl bg-success/10 text-xs font-medium text-success-ink ring-1 ring-success/30 transition-colors active:bg-success/20 disabled:opacity-70"
            >
                {saving ? <Loader2 className="size-6 animate-spin" /> : <Check className="size-6" />}
                {saving ? 'Simpan…' : 'Selesai'}
            </button>
        )}
        </div>
    );
}
