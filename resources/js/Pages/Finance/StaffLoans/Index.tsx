import { STAFF_LOAN_STATUS_LABEL, staffLoanStatus } from '@/Components/modules/finance/staffLoanStatus';
import { DataTable } from '@/Components/shared/DataTable';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StatCard } from '@/Components/shared/StatCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate, formatRupiah } from '@/lib/format';
import type { PageProps, PaginatedData, StaffLoan, User } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { HandCoins, Plus, Users, Wallet } from 'lucide-react';

interface StaffLoanIndexProps {
    loans: PaginatedData<StaffLoan>;
    filters: { staff_id?: string; status?: string };
    summary: { totalAmount: number; totalRemaining: number; ongoingCount: number };
    staff: Pick<User, 'id' | 'name'>[];
}

/** PRD §4.7 "Pinjaman Tukang" — §7.1 "Finance – Transaction": CEO/PM read, Finance create. */
export default function StaffLoanIndex({ loans, filters, summary, staff }: StaffLoanIndexProps) {
    const { auth } = usePage<PageProps>().props;
    const canManage = auth.user.role === 'FINANCE' || auth.user.role === 'SUPERADMIN';

    function applyFilter(next: Partial<StaffLoanIndexProps['filters']>) {
        router.get(route('finance.staffLoans.index'), { ...filters, ...next }, { preserveState: true, replace: true });
    }

    const columns: ColumnDef<StaffLoan>[] = [
        {
            accessorKey: 'staff',
            header: 'Tukang',
            enableSorting: false,
            cell: ({ row }) => (
                <div>
                    <Link
                        href={route('finance.staffLoans.show', { staffLoan: row.original.id })}
                        className="font-medium text-daiku-dark hover:underline"
                    >
                        {row.original.staff?.name ?? '—'}
                    </Link>
                    {row.original.description && (
                        <p className="line-clamp-1 text-xs text-daiku-muted">{row.original.description}</p>
                    )}
                </div>
            ),
        },
        {
            accessorKey: 'created_at',
            header: 'Tanggal',
            cell: ({ row }) => <span className="text-daiku-muted">{formatDate(row.original.created_at)}</span>,
        },
        {
            accessorKey: 'amount',
            header: 'Pinjaman',
            cell: ({ row }) => <span className="tabular-nums">{formatRupiah(row.original.amount)}</span>,
        },
        {
            accessorKey: 'installment_amount',
            header: 'Cicilan / Upah',
            cell: ({ row }) => (
                <span className="tabular-nums text-daiku-muted">{formatRupiah(row.original.installment_amount)}</span>
            ),
        },
        {
            accessorKey: 'remaining',
            header: 'Sisa',
            cell: ({ row }) => (
                <span className="font-medium tabular-nums">{formatRupiah(row.original.remaining)}</span>
            ),
        },
        {
            id: 'status',
            header: 'Status',
            enableSorting: false,
            cell: ({ row }) => {
                const status = staffLoanStatus(row.original);

                return <StatusChip status={status} label={STAFF_LOAN_STATUS_LABEL[status]} />;
            },
        },
        {
            id: 'bank_account',
            header: 'Rekening',
            enableSorting: false,
            cell: ({ row }) => <span className="text-daiku-muted">{row.original.bank_account?.label ?? '—'}</span>,
        },
    ];

    return (
        <AppLayout>
            <Head title="Pinjaman Tukang" />

            <PageHeader
                title="Pinjaman Tukang"
                icon={HandCoins}
                description="Kasbon tukang dan sisa cicilan yang dipotong dari upah per task."
                actions={
                    canManage && (
                        <Button size="sm" asChild>
                            <Link href={route('finance.staffLoans.create')}>
                                <Plus className="size-4" />
                                Catat Pinjaman
                            </Link>
                        </Button>
                    )
                }
            />

            <div className="mb-6 grid gap-4 sm:grid-cols-3">
                <StatCard label="Total Pinjaman" value={formatRupiah(summary.totalAmount)} icon={HandCoins} />
                <StatCard
                    label="Total Sisa Pinjaman"
                    value={formatRupiah(summary.totalRemaining)}
                    icon={Wallet}
                    tone={summary.totalRemaining > 0 ? 'warning' : 'default'}
                />
                <StatCard label="Pinjaman Berjalan" value={summary.ongoingCount} icon={Users} />
            </div>

            <DataTable
                columns={columns}
                data={loans.data}
                emptyMessage="Belum ada pinjaman tukang."
                pagination={loans}
                toolbar={
                    <div className="flex flex-wrap items-center gap-2">
                        <Select
                            value={filters.staff_id ?? 'all'}
                            onValueChange={(value) => applyFilter({ staff_id: value === 'all' ? undefined : value })}
                        >
                            <SelectTrigger className="w-56">
                                <SelectValue placeholder="Semua tukang" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua tukang</SelectItem>
                                {staff.map((member) => (
                                    <SelectItem key={member.id} value={String(member.id)}>
                                        {member.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select
                            value={filters.status ?? 'all'}
                            onValueChange={(value) => applyFilter({ status: value === 'all' ? undefined : value })}
                        >
                            <SelectTrigger className="w-44">
                                <SelectValue placeholder="Semua status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua status</SelectItem>
                                <SelectItem value="BERJALAN">Berjalan</SelectItem>
                                <SelectItem value="LUNAS">Lunas</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                }
            />
        </AppLayout>
    );
}
