import { cn } from '@/lib/utils';
import { ArrowRight, type LucideIcon } from 'lucide-react';
import { ReactNode } from 'react';

interface PageHeaderProps {
    title: string;
    description?: ReactNode;
    /**
     * The page's action buttons, rendered at the row's end (full width on a
     * phone). Sprint 23 K4b: a detail page puts **every** action here —
     * the main one as a default `<Button>`, the rest `variant="outline"` or
     * inside `<MoreActions>` — not in cards further down. Buttons that save
     * a form stay with their form; per-row actions stay in their row.
     */
    actions?: ReactNode;
    /**
     * Sprint 23 K4b — one short line under the title saying what happens
     * next and whose turn it is ("Kirim link ke klien lalu tunggu
     * persetujuannya."). Omit when nothing is waiting.
     */
    step?: ReactNode;
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
export function PageHeader({ title, description, actions, step, icon: Icon, className }: PageHeaderProps) {
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
                    {step && (
                        <p className="mt-2 inline-flex items-start gap-2 rounded-lg bg-daiku-yellow-light px-3 py-1.5 text-sm text-foreground">
                            <ArrowRight className="mt-0.5 size-4 shrink-0 text-daiku-yellow-dark" aria-hidden />
                            <span>{step}</span>
                        </p>
                    )}
                </div>
            </div>
            {actions && (
                <div className="flex w-full shrink-0 flex-wrap items-center gap-2 *:max-sm:flex-1 sm:w-auto sm:justify-end">
                    {actions}
                </div>
            )}
        </div>
    );
}
