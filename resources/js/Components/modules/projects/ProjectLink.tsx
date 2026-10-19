import { cn } from '@/lib/utils';
import type { Project } from '@/types';
import { Link } from '@inertiajs/react';

/**
 * Detail Proyek tabs a cross-project list can point at (Projects/Show `?tab=`).
 * Sprint 26 H3 — six tabs; a sub-tab goes in `view` (`tab="milestone" view="qc"`).
 * The pre-Sprint-26 keys (qa, progress, overtime, budget, documents) still open.
 */
export type ProjectTab = 'overview' | 'execution' | 'milestone' | 'task' | 'material' | 'finance';

/**
 * Sprint 13 #3 — a project's name in a cross-project list (QA, Lembur,
 * Pengajuan Barang, Termin, Invoice, Task), linking straight to the tab of
 * Detail Proyek the row belongs to. A tab the viewer doesn't get falls
 * back to the overview there.
 */
export function ProjectLink({
    project,
    tab,
    view,
    className,
}: {
    project: Pick<Project, 'id' | 'name'> | null | undefined;
    tab?: ProjectTab;
    /** A view inside `tab` (Sprint 26 H3b). */
    view?: string;
    className?: string;
}) {
    if (!project) {
        return <span className="text-daiku-muted">—</span>;
    }

    return (
        <Link
            href={route('projects.show', { project: project.id, ...(tab ? { tab } : {}), ...(tab && view ? { view } : {}) })}
            className={cn('hover:text-foreground hover:underline decoration-daiku-yellow underline-offset-2', className)}
        >
            {project.name}
        </Link>
    );
}
