import { cn } from '@/lib/utils';
import type { ReactNode } from 'react';

/**
 * Label/value grid for detail pages (project info, debt detail, loan
 * terms). Pair with <DetailItem>; set columns via `className`
 * (default: 1 → 2 columns).
 */
export function DetailList({ className, children }: { className?: string; children: ReactNode }) {
    return <dl className={cn('grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2', className)}>{children}</dl>;
}

export function DetailItem({
    label,
    className,
    valueClassName,
    children,
}: {
    label: ReactNode;
    className?: string;
    valueClassName?: string;
    children: ReactNode;
}) {
    return (
        <div className={cn('min-w-0', className)}>
            <dt className="text-xs font-medium text-muted-foreground">{label}</dt>
            <dd className={cn('mt-1 text-foreground', valueClassName)}>{children}</dd>
        </div>
    );
}
