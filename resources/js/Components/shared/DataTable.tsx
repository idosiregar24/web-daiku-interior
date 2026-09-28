import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { Skeleton } from '@/Components/ui/skeleton';
import {
    type ColumnDef,
    flexRender,
    getCoreRowModel,
    getSortedRowModel,
    useReactTable,
} from '@tanstack/react-table';
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import type { ReactNode } from 'react';
import { EmptyState } from './EmptyState';
import { type Paginator } from './Pagination';
import { TableCard } from './TableCard';

interface DataTableProps<TData, TValue> {
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
}

/**
 * Thin TanStack Table wrapper over the shadcn table primitives — see
 * .claude/rules/frontend-standards.md §4. Sorting here is client-side over
 * whatever page of `data` was passed in; large-dataset filtering/sorting
 * belongs on the backend (query string → Inertia props), not in this
 * component — don't reach for TanStack's filter APIs for that case.
 */
export function DataTable<TData, TValue>({
    columns,
    data,
    isLoading = false,
    emptyMessage = 'Belum ada data.',
    toolbar,
    footer,
    pagination,
    className,
}: DataTableProps<TData, TValue>) {
    const table = useReactTable({
        data,
        columns,
        getCoreRowModel: getCoreRowModel(),
        getSortedRowModel: getSortedRowModel(),
    });

    return (
        <TableCard toolbar={toolbar} footer={footer} pagination={pagination} className={className}>
            <Table>
                <TableHeader className="bg-daiku-yellow-light/70">
                    {table.getHeaderGroups().map((headerGroup) => (
                        <TableRow key={headerGroup.id} className="border-daiku-border hover:bg-transparent">
                            {headerGroup.headers.map((header) => {
                                const sortable = header.column.getCanSort();
                                const sorted = header.column.getIsSorted();

                                return (
                                    <TableHead
                                        key={header.id}
                                        className="h-10 px-4 text-[11px] font-semibold tracking-wider text-daiku-muted uppercase"
                                    >
                                        {header.isPlaceholder ? null : (
                                            <button
                                                type="button"
                                                disabled={!sortable}
                                                onClick={header.column.getToggleSortingHandler()}
                                                className="flex items-center gap-1 uppercase hover:text-foreground disabled:cursor-default disabled:hover:text-daiku-muted"
                                            >
                                                {flexRender(
                                                    header.column.columnDef.header,
                                                    header.getContext(),
                                                )}
                                                {sortable &&
                                                    (sorted === 'asc' ? (
                                                        <ArrowUp className="size-3.5" />
                                                    ) : sorted === 'desc' ? (
                                                        <ArrowDown className="size-3.5" />
                                                    ) : (
                                                        <ArrowUpDown className="size-3 text-daiku-muted/50" />
                                                    ))}
                                            </button>
                                        )}
                                    </TableHead>
                                );
                            })}
                        </TableRow>
                    ))}
                </TableHeader>
                <TableBody>
                    {isLoading ? (
                        Array.from({ length: 5 }).map((_, i) => (
                            <TableRow key={i}>
                                {columns.map((_, j) => (
                                    <TableCell key={j} className="px-4 py-3">
                                        <Skeleton className="h-4 w-full" />
                                    </TableCell>
                                ))}
                            </TableRow>
                        ))
                    ) : table.getRowModel().rows.length ? (
                        table.getRowModel().rows.map((row) => (
                            <TableRow key={row.id} className="hover:bg-daiku-gray/60">
                                {row.getVisibleCells().map((cell) => (
                                    <TableCell key={cell.id} className="px-4 py-3">
                                        {flexRender(cell.column.columnDef.cell, cell.getContext())}
                                    </TableCell>
                                ))}
                            </TableRow>
                        ))
                    ) : (
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={columns.length} className="p-0">
                                <EmptyState title={emptyMessage} />
                            </TableCell>
                        </TableRow>
                    )}
                </TableBody>
            </Table>
        </TableCard>
    );
}
