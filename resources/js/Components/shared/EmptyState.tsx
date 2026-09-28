import { cn } from '@/lib/utils';
import { Inbox, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

interface EmptyStateProps {
    title: ReactNode;
    description?: ReactNode;
    icon?: LucideIcon;
    /** Optional call to action, e.g. a "Tambah ..." button. */
    action?: ReactNode;
    className?: string;
}

/**
 * "Nothing here yet" block for empty tables, lists and widgets — one
 * look everywhere instead of a bare grey sentence per page.
 */
export function EmptyState({ title, description, icon: Icon = Inbox, action, className }: EmptyStateProps) {
    return (
        <div className={cn('flex flex-col items-center justify-center gap-2 px-4 py-10 text-center', className)}>
            <span className="mb-1 flex size-10 items-center justify-center rounded-full bg-daiku-gray text-daiku-muted ring-1 ring-daiku-border ring-inset">
                <Icon className="size-5" aria-hidden />
            </span>
            <p className="text-sm font-medium whitespace-normal text-foreground">{title}</p>
            {description && <p className="max-w-sm text-xs whitespace-normal text-muted-foreground">{description}</p>}
            {action && <div className="mt-2">{action}</div>}
        </div>
    );
}
