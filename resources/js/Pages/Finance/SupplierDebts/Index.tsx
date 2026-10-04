import { DataTable } from '@/Components/shared/DataTable';
import { PageHeader } from '@/Components/shared/PageHeader';
import { SearchInput } from '@/Components/shared/SearchInput';
import { StatCard } from '@/Components/shared/StatCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
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
import { AlarmClock, Eye, Plus, Receipt } from 'lucide-react';
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
            id: 'vendor',
            header: 'Supplier',
            cell: ({ row }) => (
                <div>
                    <p className="font-medium text-daiku-dark">{row.original.vendor?.name}</p>
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
                        row.original.status === 'JATUH_TEMPO' ? 'font-medium text-error-ink' : 'text-daiku-muted',
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
                            ? 'text-error-ink'
                            : row.original.status === 'LUNAS'
                              ? 'text-success-ink'
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
        <AppLayout>
            <Head title="Hutang Supplier" />

            <PageHeader
                title="Hutang Supplier"
                icon={Receipt}
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

            <div className="mb-6 grid gap-4 sm:grid-cols-2">
                <StatCard
                    label={`Total Sisa Hutang (${summary.outstandingCount} hutang)`}
                    value={formatRupiah(summary.totalOutstanding)}
                    icon={Receipt}
                />
                <StatCard
                    label={`Lewat Jatuh Tempo (${summary.overdueCount} hutang)`}
                    value={formatRupiah(summary.overdueTotal)}
                    icon={AlarmClock}
                    tone={summary.overdueCount > 0 ? 'error' : 'default'}
                    className={cn(summary.overdueCount > 0 && 'bg-error/5 ring-error/40')}
                />
            </div>

            <DataTable
                columns={columns}
                data={debts.data}
                emptyMessage="Belum ada hutang supplier."
                pagination={debts}
                toolbar={
                    <div className="flex flex-wrap items-center gap-2">
                        <SearchInput
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
                }
            />
        </AppLayout>
    );
}
