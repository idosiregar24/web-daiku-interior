import { cn } from '@/lib/utils';
import type { ReactNode } from 'react';
import { Pagination, type Paginator } from './Pagination';

interface TableCardProps {
    /** Filters/search rendered inside the card, above the table. */
    toolbar?: ReactNode;
    /** Rendered under the table inside the card (totals, notes). */
    footer?: ReactNode;
    /** Laravel paginator — page controls go in the card footer (hidden on a single page). */
    pagination?: Paginator;
    className?: string;
    children: ReactNode;
}

/**
 * The card shell every data table sits in: toolbar strip, the table
 * (horizontally scrollable on small screens), then footer/pagination.
 * DataTable renders through this; pages with hand-built `<table>`s whose
 * rows need custom markup use it directly so both look identical.
 *
 * Header cells inside should use `TABLE_HEAD_CLASS` on the `<thead>`.
 */
export function TableCard({ toolbar, footer, pagination, className, children }: TableCardProps) {
    return (
        <div className={cn('overflow-hidden rounded-xl border border-border bg-card shadow-xs', className)}>
            {toolbar && <div className="border-b border-border p-3">{toolbar}</div>}
            {/* `relative` makes the scroller the containing block of absolutely
                positioned descendants (Radix Select's hidden native <select>,
                `sr-only` text) — otherwise they sit at their column's offset
                outside this box and widen the whole page on a phone. */}
            <div className="relative overflow-x-auto">{children}</div>
            {footer && <div className="border-t border-border px-4 py-3">{footer}</div>}
            {pagination && pagination.last_page > 1 && (
                <div className="border-t border-border px-4 py-3">
                    <Pagination paginator={pagination} className="mt-0" />
                </div>
            )}
        </div>
    );
}

/** `<thead>` styling for hand-built tables — matches DataTable's header row. */
export const TABLE_HEAD_CLASS = 'bg-daiku-yellow-light/70 text-[11px] tracking-wider text-daiku-muted uppercase';
