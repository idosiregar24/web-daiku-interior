import { cn } from '@/lib/utils';
import type { LucideIcon } from 'lucide-react';
import { ReactNode } from 'react';

interface PageHeaderProps {
    title: string;
    description?: ReactNode;
    /** Primary action(s) — typically a <Button>, rendered at the row's end. */
    actions?: ReactNode;
    /** Optional module icon shown in a brand-tinted tile before the title. */
    icon?: LucideIcon;
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
export function PageHeader({ title, description, actions, icon: Icon, className }: PageHeaderProps) {
    return (
        <div
            className={cn(
                'mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between',
                className,
            )}
        >
            <div className="flex min-w-0 items-start gap-3">
                {Icon && (
                    <span className="mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-xl bg-daiku-yellow-light text-daiku-yellow-dark">
                        <Icon className="size-5" aria-hidden />
                    </span>
                )}
                <div className="min-w-0">
                    <h1 className="text-xl font-semibold tracking-tight text-foreground sm:text-2xl">{title}</h1>
                    {description && (
                        <p className="mt-1 text-sm text-muted-foreground">{description}</p>
                    )}
                </div>
            </div>
            {actions && <div className="flex shrink-0 flex-wrap items-center gap-2">{actions}</div>}
        </div>
    );
}
