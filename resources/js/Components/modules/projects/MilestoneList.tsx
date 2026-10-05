import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { formatDate } from '@/lib/format';
import type { Milestone } from '@/types';
import { Pencil, Trash2 } from 'lucide-react';

/**
 * Sprint 13 P2 — the project's milestones as a plain list for a phone
 * (the Gantt and calendar need width). Same actions as the Gantt rows:
 * Tandai Selesai (queues QA), edit, delete — for those who manage it.
 */
export function MilestoneList({
    milestones,
    canManage,
    onEdit,
    onDelete,
    onMarkDone,
}: {
    milestones: Milestone[];
    canManage: boolean;
    onEdit: (milestone: Milestone) => void;
    onDelete: (milestone: Milestone) => void;
    onMarkDone: (milestone: Milestone) => void;
}) {
    const sorted = [...milestones].sort((a, b) => a.order - b.order);

    return (
        <ol className="divide-y divide-border overflow-hidden rounded-xl bg-card ring-1 ring-border">
            {sorted.map((milestone, index) => {
                const canMarkDone = canManage && ['PENDING', 'IN_PROGRESS', 'OVERDUE'].includes(milestone.status);

                return (
                    <li key={milestone.id} className="flex flex-col gap-2 px-4 py-3">
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <p className="font-medium text-foreground">
                                    {index + 1}. {milestone.name}
                                </p>
                                <p className="text-xs text-muted-foreground">Target {formatDate(milestone.target_date)}</p>
                            </div>
                            <StatusChip status={milestone.status} />
                        </div>
                        {canManage && (
                            <div className="flex items-center gap-2">
                                {canMarkDone && (
                                    <Button size="sm" variant="outline" className="flex-1" onClick={() => onMarkDone(milestone)}>
                                        Tandai Selesai
                                    </Button>
                                )}
                                <Button variant="ghost" size="icon-sm" aria-label={`Ubah ${milestone.name}`} onClick={() => onEdit(milestone)}>
                                    <Pencil className="size-4" />
                                </Button>
                                <Button variant="ghost" size="icon-sm" aria-label={`Hapus ${milestone.name}`} onClick={() => onDelete(milestone)}>
                                    <Trash2 className="size-4 text-error-ink" />
                                </Button>
                            </div>
                        )}
                    </li>
                );
            })}
        </ol>
    );
}
