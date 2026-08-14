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

interface DataTableProps<TData, TValue> {
    columns: ColumnDef<TData, TValue>[];
    data: TData[];
    /** Shown in place of rows while a request is in flight. */
    isLoading?: boolean;
    /** Shown when `data` is empty and not loading. */
    emptyMessage?: string;
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
}: DataTableProps<TData, TValue>) {
    const table = useReactTable({
        data,
        columns,
        getCoreRowModel: getCoreRowModel(),
        getSortedRowModel: getSortedRowModel(),
    });

    return (
        <div className="overflow-hidden rounded-lg border border-daiku-border">
            <Table>
                <TableHeader className="bg-daiku-yellow-light">
                    {table.getHeaderGroups().map((headerGroup) => (
                        <TableRow key={headerGroup.id}>
                            {headerGroup.headers.map((header) => {
                                const sortable = header.column.getCanSort();
                                const sorted = header.column.getIsSorted();

                                return (
                                    <TableHead key={header.id}>
                                        {header.isPlaceholder ? null : (
                                            <button
                                                type="button"
                                                disabled={!sortable}
                                                onClick={header.column.getToggleSortingHandler()}
                                                className="flex items-center gap-1 disabled:cursor-default"
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
                                                        <ArrowUpDown className="size-3.5 text-daiku-muted/60" />
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
                                    <TableCell key={j}>
                                        <Skeleton className="h-4 w-full" />
                                    </TableCell>
                                ))}
                            </TableRow>
                        ))
                    ) : table.getRowModel().rows.length ? (
                        table.getRowModel().rows.map((row) => (
                            <TableRow key={row.id}>
                                {row.getVisibleCells().map((cell) => (
                                    <TableCell key={cell.id}>
                                        {flexRender(cell.column.columnDef.cell, cell.getContext())}
                                    </TableCell>
                                ))}
                            </TableRow>
                        ))
                    ) : (
                        <TableRow>
                            <TableCell
                                colSpan={columns.length}
                                className="h-24 text-center text-daiku-muted"
                            >
                                {emptyMessage}
                            </TableCell>
                        </TableRow>
                    )}
                </TableBody>
            </Table>
        </div>
    );
}
