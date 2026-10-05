import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/Components/ui/table';
import { Skeleton } from '@/Components/ui/skeleton';
import { flexRender, getCoreRowModel, getSortedRowModel, useReactTable } from '@tanstack/react-table';
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-react';
import type { DataTableProps } from './DataTable';
import { EmptyState } from './EmptyState';
import { TableCard } from './TableCard';

/**
 * The TanStack Table grid behind DataTable (Sprint 13 H8: split out and
 * lazy-loaded, so pages and phones that never show a grid don't download
 * TanStack). Use DataTable — never import this directly. Sorting here is
 * client-side over the page of `data` passed in; large-dataset
 * filtering/sorting belongs on the backend (frontend-standards.md §4).
 */
export default function DataTableGrid<TData, TValue>({
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
