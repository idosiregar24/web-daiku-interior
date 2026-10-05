import { Skeleton } from '@/Components/ui/skeleton';
import { BELOW_MD, useMediaQuery } from '@/hooks/useMediaQuery';
import type { ColumnDef } from '@tanstack/react-table';
import { lazy, type ReactElement, type ReactNode, Suspense } from 'react';
import { EmptyState } from './EmptyState';
import { type Paginator } from './Pagination';
import { TableCard } from './TableCard';

export interface DataTableProps<TData, TValue> {
    columns: ColumnDef<TData, TValue>[];
    data: TData[];
    /** Shown in place of rows while a request is in flight. */
    isLoading?: boolean;
    /** Shown when `data` is empty and not loading. */
    emptyMessage?: string;
    /** Filters/search rendered inside the table card, above the header row. */
    toolbar?: ReactNode;
    /** Rendered under the rows inside the card (totals, notes). */
    footer?: ReactNode;
    /** Laravel paginator for `data` — page controls go in the card footer (hidden on a single page). */
    pagination?: Paginator;
    className?: string;
    /**
     * Sprint 13 P3 — below `md` render each row as this card instead of
     * the grid (toolbar, footer and pagination stay). Pages used on a
     * phone in the field or warehouse pass it; without it the grid shows
     * (scrolling sideways) on every screen.
     */
    mobileCard?: (row: TData) => ReactNode;
    /** Stable key per row for the card list; defaults to `row.id`, then the index. */
    rowKey?: (row: TData, index: number) => string | number;
}

// The TanStack grid is its own chunk (Sprint 13 H8): a phone showing cards,
// or a page that never renders a table, doesn't download it.
const DataTableGrid = lazy(() => import('./DataTableGrid')) as unknown as <TData, TValue>(
    props: DataTableProps<TData, TValue>,
) => ReactElement;

function defaultKey<TData>(row: TData, index: number): string | number {
    const id = (row as { id?: unknown }).id;

    return typeof id === 'string' || typeof id === 'number' ? id : index;
}

/**
 * Every data table — see .claude/rules/frontend-standards.md §4. On a
 * phone with `mobileCard`, a card list; otherwise the TanStack grid
 * (DataTableGrid, loaded on demand behind a skeleton of the same card).
 */
export function DataTable<TData, TValue>(props: DataTableProps<TData, TValue>) {
    const { data, mobileCard, rowKey = defaultKey, isLoading, emptyMessage = 'Belum ada data.', toolbar, footer, pagination, className } = props;
    const compact = useMediaQuery(BELOW_MD);

    if (mobileCard && compact) {
        return (
            <TableCard toolbar={toolbar} footer={footer} pagination={pagination} className={className}>
                {isLoading ? (
                    <div className="flex flex-col gap-3 p-4">
                        {Array.from({ length: 3 }).map((_, i) => (
                            <Skeleton key={i} className="h-16 w-full" />
                        ))}
                    </div>
                ) : data.length === 0 ? (
                    <EmptyState title={emptyMessage} />
                ) : (
                    <ul className="divide-y divide-border">
                        {data.map((row, index) => (
                            <li key={rowKey(row, index)} className="px-4 py-3">
                                {mobileCard(row)}
                            </li>
                        ))}
                    </ul>
                )}
            </TableCard>
        );
    }

    return (
        <Suspense
            fallback={
                <TableCard toolbar={toolbar} footer={footer} pagination={pagination} className={className}>
                    <div className="flex flex-col gap-2 p-4">
                        {Array.from({ length: Math.min(Math.max(data.length, 3), 8) }).map((_, i) => (
                            <Skeleton key={i} className="h-8 w-full" />
                        ))}
                    </div>
                </TableCard>
            }
        >
            <DataTableGrid {...props} />
        </Suspense>
    );
}
