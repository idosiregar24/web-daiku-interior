import { StatusChip } from '@/Components/shared/StatusChip';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Milestone, Task, TaskStatus } from '@/types';
import { AlertCircle, CalendarDays, User } from 'lucide-react';
import { useMemo, useState } from 'react';

const COLUMNS: { status: TaskStatus; label: string }[] = [
    { status: 'PENDING', label: 'Pending' },
    { status: 'ONPROGRESS', label: 'On Progress' },
    { status: 'PENGECEKAN', label: 'Pengecekan' },
    { status: 'DONE', label: 'Done' },
    { status: 'OVER', label: 'Over' },
];

const NO_MILESTONE = 'none';

interface TaskKanbanBoardProps {
    tasks: Task[];
    milestones: Milestone[];
    /** PM (canManage) opens the status dialog by clicking a card. */
    onCardClick?: (task: Task) => void;
}

/**
 * CSV Sprint 6 "PM task board: kanban-lite per milestone (groupBy
 * status)" — PRD §8.4 "PM: ... task board per proyek". Read-only
 * columns (no drag-and-drop): a status move goes through TaskStatusDialog
 * so kendala/note travel with it, same as everywhere else.
 */
export function TaskKanbanBoard({ tasks, milestones, onCardClick }: TaskKanbanBoardProps) {
    const [milestoneFilter, setMilestoneFilter] = useState<string>('all');

    const columns = useMemo(() => {
        const visible = tasks.filter((task) => {
            if (milestoneFilter === 'all') return true;
            if (milestoneFilter === NO_MILESTONE) return task.milestone_id === null;
            return String(task.milestone_id) === milestoneFilter;
        });

        return COLUMNS.map((column) => ({
            ...column,
            tasks: visible
                .filter((task) => task.status === column.status)
                .sort((a, b) => (a.due_date ?? '').localeCompare(b.due_date ?? '')),
        }));
    }, [tasks, milestoneFilter]);

    const today = new Date().toISOString().slice(0, 10);

    return (
        <div>
            <div className="mb-3 flex items-center gap-2">
                <Select value={milestoneFilter} onValueChange={setMilestoneFilter}>
                    <SelectTrigger className="w-60" aria-label="Filter milestone">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Semua milestone</SelectItem>
                        {milestones.map((milestone) => (
                            <SelectItem key={milestone.id} value={String(milestone.id)}>
                                {milestone.name}
                            </SelectItem>
                        ))}
                        <SelectItem value={NO_MILESTONE}>Tanpa milestone</SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div className="grid auto-cols-[minmax(15rem,1fr)] grid-flow-col gap-3 overflow-x-auto pb-2">
                {columns.map((column) => (
                    <section key={column.status} aria-label={`${column.label} (${column.tasks.length})`} className="flex flex-col rounded-lg bg-daiku-gray p-2">
                        <header className="mb-2 flex items-center justify-between px-1">
                            <StatusChip status={column.status} label={column.label} />
                            <span className="text-xs font-medium text-daiku-muted tabular-nums">{column.tasks.length}</span>
                        </header>
                        <div className="flex flex-col gap-2">
                            {column.tasks.length === 0 && <p className="px-1 py-4 text-center text-xs text-daiku-muted">Kosong</p>}
                            {column.tasks.map((task) => {
                                const late = task.status !== 'DONE' && task.due_date !== null && task.due_date.slice(0, 10) < today;
                                const Card = onCardClick ? 'button' : 'div';

                                return (
                                    <Card
                                        key={task.id}
                                        {...(onCardClick ? { type: 'button' as const, onClick: () => onCardClick(task) } : {})}
                                        className={cn(
                                            'rounded-md border border-daiku-border bg-white p-2.5 text-left shadow-xs',
                                            onCardClick && 'transition-colors hover:border-daiku-yellow-dark focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                        )}
                                    >
                                        <p className="text-sm font-medium text-daiku-dark">{task.title}</p>
                                        {milestoneFilter === 'all' && task.milestone && (
                                            <p className="mt-0.5 text-xs text-daiku-muted">{task.milestone.name}</p>
                                        )}
                                        <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-daiku-muted">
                                            <span className="flex items-center gap-1">
                                                <User className="size-3" aria-hidden />
                                                {task.assignee?.name ?? 'Belum ditugaskan'}
                                            </span>
                                            <span className={cn('flex items-center gap-1', late && 'font-medium text-error')}>
                                                <CalendarDays className="size-3" aria-hidden />
                                                {formatDate(task.due_date)}
                                            </span>
                                            {task.priority === 'HIGH' && <span className="font-medium text-daiku-dark">Prioritas tinggi</span>}
                                        </div>
                                        {task.kendala && (
                                            <p className="mt-2 flex items-start gap-1 rounded bg-warning/10 px-1.5 py-1 text-xs text-daiku-dark">
                                                <AlertCircle className="mt-0.5 size-3 shrink-0 text-warning" aria-hidden />
                                                <span className="line-clamp-2">Kendala: {task.kendala}</span>
                                            </p>
                                        )}
                                    </Card>
                                );
                            })}
                        </div>
                    </section>
                ))}
            </div>
        </div>
    );
}
