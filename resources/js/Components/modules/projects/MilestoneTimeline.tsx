import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';
import type { Milestone, MilestoneStatus } from '@/types';
import { Check, Pencil, Trash2 } from 'lucide-react';

const DOT_CLASS: Record<MilestoneStatus, string> = {
    COMPLETED: 'bg-success border-success',
    IN_PROGRESS: 'bg-info border-info',
    QA_WAITING: 'bg-warning border-warning',
    OVERDUE: 'bg-error border-error',
    PENDING: 'bg-white border-daiku-border',
};

function formatDate(value: string) {
    return new Date(value).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
}

interface MilestoneTimelineProps {
    milestones: Milestone[];
    canManage: boolean;
    onEdit: (milestone: Milestone) => void;
    onDelete: (milestone: Milestone) => void;
}

/**
 * "Milestone list component: timeline visual per proyek"
 * (.claude/plan/sprint-02.md Week 4) — vertical connected-dot timeline,
 * replacing the plain numbered list. Chosen over a horizontal timeline
 * for responsiveness (reads the same at any width, no extra library).
 */
export function MilestoneTimeline({ milestones, canManage, onEdit, onDelete }: MilestoneTimelineProps) {
    return (
        <ol className="relative">
            {milestones.map((milestone, index) => {
                const isLast = index === milestones.length - 1;

                return (
                    <li key={milestone.id} className="relative flex gap-4 pb-8 last:pb-0">
                        {!isLast && (
                            <span
                                className="absolute top-5 left-[9px] w-px bg-daiku-border"
                                style={{ height: 'calc(100% - 0.5rem)' }}
                                aria-hidden
                            />
                        )}
                        <span
                            className={cn(
                                'z-10 mt-1 flex size-5 shrink-0 items-center justify-center rounded-full border-2',
                                DOT_CLASS[milestone.status],
                            )}
                        >
                            {milestone.status === 'COMPLETED' && <Check className="size-3 text-white" />}
                        </span>

                        <div className="flex min-w-0 flex-1 items-center justify-between gap-4 rounded-lg border border-daiku-border p-3">
                            <div className="min-w-0">
                                <p className="truncate text-sm font-medium text-daiku-dark">
                                    {index + 1}. {milestone.name}
                                </p>
                                <p className="text-xs text-daiku-muted">Target: {formatDate(milestone.target_date)}</p>
                            </div>
                            <div className="flex shrink-0 items-center gap-2">
                                <StatusChip status={milestone.status} />
                                {canManage && (
                                    <>
                                        <Button variant="ghost" size="icon-sm" onClick={() => onEdit(milestone)}>
                                            <Pencil className="size-4" />
                                        </Button>
                                        <Button variant="ghost" size="icon-sm" onClick={() => onDelete(milestone)}>
                                            <Trash2 className="size-4 text-error" />
                                        </Button>
                                    </>
                                )}
                            </div>
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}
