import { cn } from '@/lib/utils';
import { ReactNode } from 'react';

interface PageHeaderProps {
    title: string;
    description?: string;
    /** Primary action(s) — typically a <Button>, rendered at the row's end. */
    actions?: ReactNode;
    className?: string;
}

/**
 * In-content page title block (title + optional description + primary
 * action), used below the AppLayout topbar — see
 * .claude/rules/frontend-standards.md and design-standards.md §4.
 *
 *   <PageHeader
 *     title="Data Lead"
 *     description="Kelola calon klien dan pipeline penjualan."
 *     actions={<Button>Tambah Lead</Button>}
 *   />
 */
export function PageHeader({ title, description, actions, className }: PageHeaderProps) {
    return (
        <div
            className={cn(
                'mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between',
                className,
            )}
        >
            <div>
                <h1 className="text-lg font-semibold text-daiku-dark">{title}</h1>
                {description && (
                    <p className="mt-1 text-sm text-daiku-muted">{description}</p>
                )}
            </div>
            {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
        </div>
    );
}
