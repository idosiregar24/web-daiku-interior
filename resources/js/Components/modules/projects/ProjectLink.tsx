import { cn } from '@/lib/utils';
import type { Project } from '@/types';
import { Link } from '@inertiajs/react';

/** Detail Proyek tabs a cross-project list can point at (Projects/Show `?tab=`). */
export type ProjectTab = 'overview' | 'milestone' | 'task' | 'progress' | 'finance' | 'budget' | 'documents' | 'material' | 'qa' | 'overtime';

/**
 * Sprint 13 #3 — a project's name in a cross-project list (QA, Lembur,
 * Pengajuan Barang, Termin, Invoice, Task), linking straight to the tab of
 * Detail Proyek the row belongs to. A tab the viewer doesn't get falls
 * back to the overview there.
 */
export function ProjectLink({
    project,
    tab,
    className,
}: {
    project: Pick<Project, 'id' | 'name'> | null | undefined;
    tab?: ProjectTab;
    className?: string;
}) {
    if (!project) {
        return <span className="text-daiku-muted">—</span>;
    }

    return (
        <Link
            href={route('projects.show', { project: project.id, ...(tab ? { tab } : {}) })}
            className={cn('hover:text-foreground hover:underline decoration-daiku-yellow underline-offset-2', className)}
        >
            {project.name}
        </Link>
    );
}
