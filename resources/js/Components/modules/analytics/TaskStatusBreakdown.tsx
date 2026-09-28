import { cn } from '@/lib/utils';
import type { TaskStatus } from '@/types';

// Status meaning, so status tokens (same mapping as StatusChip), in the
// task lifecycle order — never re-sorted by count.
const SEGMENTS: { status: TaskStatus; label: string; className: string }[] = [
    { status: 'PENDING', label: 'Pending', className: 'bg-daiku-muted/40' },
    { status: 'ONPROGRESS', label: 'On Progress', className: 'bg-info' },
    { status: 'PENGECEKAN', label: 'Pengecekan', className: 'bg-warning' },
    { status: 'DONE', label: 'Done', className: 'bg-success' },
    { status: 'OVER', label: 'Over', className: 'bg-error' },
];

/**
 * Part-to-whole of task statuses in active projects (dataviz: stacked bar,
 * 2px surface gap between segments, legend with label + count + share so
 * identity never rests on color alone).
 */
export function TaskStatusBreakdown({ data }: { data: Record<TaskStatus, number> }) {
    const total = SEGMENTS.reduce((sum, segment) => sum + (data[segment.status] ?? 0), 0);

    if (total === 0) {
        return <p className="py-6 text-center text-sm text-muted-foreground">Belum ada task di proyek aktif.</p>;
    }

    return (
        <div>
            <p className="mb-4 flex items-baseline gap-2">
                <span className="text-3xl leading-none font-semibold tracking-tight text-foreground">{total}</span>
                <span className="text-sm text-muted-foreground">task di proyek aktif</span>
            </p>
            <div
                className="flex h-3 gap-0.5 overflow-hidden rounded-full"
                role="img"
                aria-label={`Status task: ${SEGMENTS.map((s) => `${s.label} ${data[s.status] ?? 0}`).join(', ')}`}
            >
                {SEGMENTS.filter((segment) => (data[segment.status] ?? 0) > 0).map((segment) => (
                    <div
                        key={segment.status}
                        className={cn('h-full first:rounded-l-full last:rounded-r-full', segment.className)}
                        style={{ width: `${((data[segment.status] ?? 0) / total) * 100}%` }}
                        title={`${segment.label}: ${data[segment.status]} task`}
                    />
                ))}
            </div>

            <dl className="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-5">
                {SEGMENTS.map((segment) => {
                    const count = data[segment.status] ?? 0;

                    return (
                        <div key={segment.status}>
                            <dt className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                <span aria-hidden className={cn('size-2 rounded-sm', segment.className)} />
                                {segment.label}
                            </dt>
                            <dd className="mt-0.5 text-sm font-semibold text-foreground">
                                {count}
                                <span className="ml-1 text-xs font-normal text-muted-foreground">
                                    {Math.round((count / total) * 100)}%
                                </span>
                            </dd>
                        </div>
                    );
                })}
            </dl>
        </div>
    );
}
