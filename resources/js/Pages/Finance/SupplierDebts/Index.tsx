import { DataTable } from '@/Components/shared/DataTable';
import { PageHeader } from '@/Components/shared/PageHeader';
import { Pagination } from '@/Components/shared/Pagination';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate, formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { PageProps, PaginatedData } from '@/types';
import {
    SUPPLIER_DEBT_STATUS_LABELS,
    type SupplierDebtDisplayStatus,
    type SupplierDebtWithStatus as SupplierDebtRow,
} from '@/Components/modules/finance/supplierDebtStatus';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { Eye, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';

interface SupplierDebtIndexProps {
    debts: PaginatedData<SupplierDebtRow>;
    filters: { status?: string; search?: string };
    summary: {
        totalOutstanding: number;
        outstandingCount: number;
        overdueTotal: number;
        overdueCount: number;
    };
}

/** PRD §4.7 "Hutang Supplier" — §7.1 "Finance – Transaction": CEO/PM read, Finance create/update. */
export default function SupplierDebtIndex({ debts, filters, summary }: SupplierDebtIndexProps) {
    const { auth } = usePage<PageProps>().props;
    const canManage = auth.user.role === 'FINANCE' || auth.user.role === 'SUPERADMIN';
    const [search, setSearch] = useState(filters.search ?? '');

    function applyFilter(next: Partial<SupplierDebtIndexProps['filters']>) {
        router.get(route('finance.supplierDebts.index'), { ...filters, ...next }, { preserveState: true, replace: true });
    }

    useEffect(() => {
        if (search === (filters.search ?? '')) {
            return;
        }

        const timeout = setTimeout(() => applyFilter({ search: search || undefined }), 350);

        return () => clearTimeout(timeout);
    }, [search]);

    const columns: ColumnDef<SupplierDebtRow>[] = [
        {
            accessorKey: 'supplier_name',
            header: 'Supplier',
            cell: ({ row }) => (
                <div>
                    <p className="font-medium text-daiku-dark">{row.original.supplier_name}</p>
                    <p className="text-xs text-daiku-muted">{row.original.project?.name ?? 'Tanpa proyek'}</p>
                </div>
            ),
        },
        {
            accessorKey: 'status',
            header: 'Status',
            cell: ({ row }) => (
                <StatusChip status={row.original.status} label={SUPPLIER_DEBT_STATUS_LABELS[row.original.status]} />
            ),
        },
        {
            accessorKey: 'due_date',
            header: 'Jatuh Tempo',
            cell: ({ row }) => (
                <span
                    className={cn(
                        row.original.status === 'JATUH_TEMPO' ? 'font-medium text-error' : 'text-daiku-muted',
                    )}
                >
                    {formatDate(row.original.due_date)}
                </span>
            ),
        },
        {
            accessorKey: 'total_amount',
            header: () => <div className="text-right">Total</div>,
            cell: ({ row }) => <div className="text-right">{formatRupiah(row.original.total_amount)}</div>,
        },
        {
            accessorKey: 'paid_amount',
            header: () => <div className="text-right">Terbayar</div>,
            cell: ({ row }) => (
                <div className="text-right text-daiku-muted">{formatRupiah(row.original.paid_amount)}</div>
            ),
        },
        {
            accessorKey: 'remaining',
            header: () => <div className="text-right">Sisa</div>,
            cell: ({ row }) => (
                <div
                    className={cn(
                        'text-right font-semibold',
                        row.original.status === 'JATUH_TEMPO'
                            ? 'text-error'
                            : row.original.status === 'LUNAS'
                              ? 'text-success'
                              : 'text-daiku-dark',
                    )}
                >
                    {formatRupiah(row.original.remaining)}
                </div>
            ),
        },
        {
            id: 'actions',
            header: '',
            enableSorting: false,
            cell: ({ row }) => (
                <div className="flex justify-end">
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={route('finance.supplierDebts.show', { supplierDebt: row.original.id })}>
                            <Eye className="size-4" />
                            Detail
                        </Link>
                    </Button>
                </div>
            ),
        },
    ];

    return (
        <AppLayout breadcrumbs={[{ label: 'Finance', routeName: 'finance.dashboard' }, { label: 'Hutang Supplier' }]}>
            <Head title="Hutang Supplier" />

            <PageHeader
                title="Hutang Supplier"
                description="Tracking hutang ke supplier beserta riwayat pembayarannya."
                actions={
                    canManage && (
                        <Button size="sm" asChild>
                            <Link href={route('finance.supplierDebts.create')}>
                                <Plus className="size-4" />
                                Catat Hutang
                            </Link>
                        </Button>
                    )
                }
            />

            <div className="mb-4 grid gap-4 sm:grid-cols-2">
                <Card>
                    <CardContent className="pt-6">
                        <p className="text-xs text-daiku-muted">Total Sisa Hutang ({summary.outstandingCount} hutang)</p>
                        <p className="text-lg font-semibold text-daiku-dark">{formatRupiah(summary.totalOutstanding)}</p>
                    </CardContent>
                </Card>
                <Card className={cn(summary.overdueCount > 0 && 'border-error/40 bg-error/5')}>
                    <CardContent className="pt-6">
                        <p className="text-xs text-daiku-muted">Lewat Jatuh Tempo ({summary.overdueCount} hutang)</p>
                        <p className={cn('text-lg font-semibold', summary.overdueCount > 0 ? 'text-error' : 'text-daiku-dark')}>
                            {formatRupiah(summary.overdueTotal)}
                        </p>
                    </CardContent>
                </Card>
            </div>

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <Input
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Cari nama supplier..."
                    className="w-full sm:w-64"
                />
                <Select
                    value={filters.status ?? 'all'}
                    onValueChange={(value) => applyFilter({ status: value === 'all' ? undefined : value })}
                >
                    <SelectTrigger className="w-48">
                        <SelectValue placeholder="Semua status" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Semua status</SelectItem>
                        {(Object.keys(SUPPLIER_DEBT_STATUS_LABELS) as SupplierDebtDisplayStatus[]).map((status) => (
                            <SelectItem key={status} value={status}>
                                {SUPPLIER_DEBT_STATUS_LABELS[status]}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <DataTable columns={columns} data={debts.data} emptyMessage="Belum ada hutang supplier." />
            <Pagination paginator={debts} />
        </AppLayout>
    );
}
