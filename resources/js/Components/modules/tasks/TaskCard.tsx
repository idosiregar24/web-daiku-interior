import { StatusChip } from '@/Components/shared/StatusChip';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Task } from '@/types';
import { CircleCheck, CircleX, ChevronRight } from 'lucide-react';

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
 */
export function TaskCard({ task, onOpen }: { task: FieldTask; onOpen: (task: FieldTask) => void }) {
    const overdue = task.status === 'OVER' || (task.due_date !== null && task.due_date.slice(0, 10) < TODAY() && task.status !== 'DONE');

    return (
        <button
            type="button"
            onClick={() => onOpen(task)}
            className="flex w-full items-center gap-3 rounded-xl bg-card p-4 text-left shadow-xs ring-1 ring-border transition-colors active:bg-daiku-yellow-light/60"
        >
            <span className="min-w-0 flex-1">
                <span className="block font-medium text-foreground">{task.title}</span>
                <span className="mt-0.5 block truncate text-xs text-muted-foreground">
                    {task.project?.name}
                    {task.milestone?.name ? ` · ${task.milestone.name}` : ''}
                </span>
                <span className="mt-2 flex flex-wrap items-center gap-2">
                    <StatusChip status={task.status} />
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
                        {task.has_form_today ? 'Form hari ini sudah diisi' : 'Form hari ini belum diisi'}
                    </span>
                )}
            </span>
            <ChevronRight className="size-5 shrink-0 text-muted-foreground/60" />
        </button>
    );
}
