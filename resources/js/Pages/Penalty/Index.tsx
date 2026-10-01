import { PenaltyPaymentDialog, type UnpaidPenalty } from '@/Components/modules/finance/PenaltyPaymentDialog';
import { EmptyState } from '@/Components/shared/EmptyState';
import { PageHeader } from '@/Components/shared/PageHeader';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatCard } from '@/Components/shared/StatCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { Button } from '@/Components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate, formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { BankAccount, PaginatedData, Penalty, PenaltyPaymentStatus, User } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { AlertOctagon, CheckCircle2, HandCoins, Hourglass, PiggyBank, Users } from 'lucide-react';
import { useMemo, useState } from 'react';

interface StaffPenaltySummary {
    staffId: number;
    name: string;
    count: number;
    total: number;
    unpaidCount: number;
    outstanding: number;
    lastDate: string | null;
}

interface PenaltyIndexProps {
    penalties: PaginatedData<Penalty>;
    filters: { staff_id?: string; status?: PenaltyPaymentStatus };
    fieldStaff: Pick<User, 'id' | 'name'>[];
    perStaff: StaffPenaltySummary[];
    penaltyCount: number;
    unpaidCount: number;
    grandTotal: number;
    paidTotal: number;
    outstandingTotal: number;
    canViewFamilyFund: boolean;
    canRecordPayment: boolean;
    unpaidPenalties: UnpaidPenalty[];
    bankAccounts: Pick<BankAccount, 'id' | 'label'>[];
}

const STATUS_LABEL: Record<PenaltyPaymentStatus, string> = {
    BELUM_DIBAYAR: 'Belum Dibayar',
    LUNAS: 'Lunas',
};

/**
 * PRD §7.1 "Penalty – View" — Field Staff see only their own
 * (server-scoped in PenaltyController::index()), CEO/PM read-only.
 * Sprint 9 decision #10: the tukang pays manually (wages are not
 * deducted); Finance records it per tukang ("Catat Pembayaran").
 */
export default function PenaltyIndex({
    penalties,
    filters,
    fieldStaff,
    perStaff,
    penaltyCount,
    unpaidCount,
    grandTotal,
    paidTotal,
    outstandingTotal,
    canViewFamilyFund,
    canRecordPayment,
    unpaidPenalties,
    bankAccounts,
}: PenaltyIndexProps) {
    const isOwnView = fieldStaff.length === 0;
    const [payingStaff, setPayingStaff] = useState<{ id: number; name: string } | null>(null);

    const payingPenalties = useMemo(
        () => (payingStaff ? unpaidPenalties.filter((penalty) => penalty.staff_id === payingStaff.id) : []),
        [payingStaff, unpaidPenalties],
    );

    function applyFilter(next: Partial<typeof filters>) {
        router.get(route('penalties.index'), { ...filters, ...next }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout>
            <Head title="Penalti" />

            <PageHeader
                title="Penalti"
                icon={AlertOctagon}
                description="Penalti form harian yang belum diisi — dijatuhkan otomatis setiap jam 21:00 WIB, dibayar tukang secara tunai/transfer (upah tidak dipotong)."
                actions={
                    canViewFamilyFund && (
                        <Button variant="outline" size="sm" asChild>
                            <Link href={route('family-fund.index')}>
                                <PiggyBank className="size-4" />
                                Dana Family Gathering
                            </Link>
                        </Button>
                    )
                }
            />

            <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label={isOwnView ? 'Total Penalti Saya' : 'Total Penalti'}
                    value={formatRupiah(grandTotal)}
                    icon={AlertOctagon}
                    hint={`${penaltyCount} pelanggaran`}
                />
                <StatCard
                    label="Sudah Dibayar"
                    value={formatRupiah(paidTotal)}
                    icon={CheckCircle2}
                    tone="success"
                    hint="Masuk saldo Dana Family Gathering"
                />
                <StatCard
                    label="Belum Dibayar"
                    value={formatRupiah(outstandingTotal)}
                    icon={Hourglass}
                    tone={outstandingTotal > 0 ? 'warning' : 'default'}
                    hint={isOwnView ? 'Bayar tunai/transfer ke Finance' : `${unpaidCount} penalti`}
                />
                {!isOwnView && <StatCard label="Tukang Terkena Penalti" value={perStaff.length} icon={Users} />}
            </div>

            <div className={cn('grid gap-6', !isOwnView && 'lg:grid-cols-[minmax(0,1fr)_22rem]')}>
                <div className="order-2 lg:order-1">
                    <TableCard
                        pagination={penalties}
                        toolbar={
                            <div className="flex flex-col gap-2 sm:flex-row">
                                {!isOwnView && (
                                    <Select
                                        value={filters.staff_id ?? 'all'}
                                        onValueChange={(value) => applyFilter({ staff_id: value === 'all' ? undefined : value })}
                                    >
                                        <SelectTrigger className="sm:w-56" aria-label="Filter tukang">
                                            <SelectValue placeholder="Semua tukang" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="all">Semua tukang</SelectItem>
                                            {fieldStaff.map((staff) => (
                                                <SelectItem key={staff.id} value={String(staff.id)}>
                                                    {staff.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                )}
                                <Select
                                    value={filters.status ?? 'all'}
                                    onValueChange={(value) =>
                                        applyFilter({ status: value === 'all' ? undefined : (value as PenaltyPaymentStatus) })
                                    }
                                >
                                    <SelectTrigger className="sm:w-44" aria-label="Filter status pembayaran">
                                        <SelectValue placeholder="Semua status" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Semua status</SelectItem>
                                        <SelectItem value="BELUM_DIBAYAR">Belum Dibayar</SelectItem>
                                        <SelectItem value="LUNAS">Lunas</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        }
                    >
                        <table className="w-full text-sm">
                            <thead className={TABLE_HEAD_CLASS}>
                                <tr>
                                    <th className="px-4 py-2.5 text-left font-semibold">Tukang</th>
                                    <th className="px-4 py-2.5 text-left font-semibold">Jenis</th>
                                    <th className="px-4 py-2.5 text-left font-semibold">Tanggal</th>
                                    <th className="px-4 py-2.5 text-left font-semibold">Status</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Nominal</th>
                                </tr>
                            </thead>
                            <tbody>
                                {penalties.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={5} className="p-0">
                                            <EmptyState title="Belum ada penalti." />
                                        </td>
                                    </tr>
                                ) : (
                                    penalties.data.map((penalty) => {
                                        const status: PenaltyPaymentStatus = penalty.is_deducted ? 'LUNAS' : 'BELUM_DIBAYAR';
                                        const account = penalty.finance_transaction?.bank_account?.label;

                                        return (
                                            <tr key={penalty.id} className="border-t border-border transition-colors hover:bg-daiku-gray/60">
                                                <td className="px-4 py-3 font-medium">{penalty.staff?.name ?? '—'}</td>
                                                <td className="px-4 py-3 text-daiku-muted">{penalty.type.replace(/_/g, ' ')}</td>
                                                <td className="px-4 py-3 text-daiku-muted">{formatDate(penalty.date_occurred)}</td>
                                                <td className="px-4 py-3">
                                                    <StatusChip status={status} label={STATUS_LABEL[status]} />
                                                    {penalty.is_deducted && (
                                                        <span className="mt-0.5 block text-xs text-daiku-muted">
                                                            {formatDate(penalty.finance_transaction?.date ?? penalty.collected_at)}
                                                            {account && ` · ${account}`}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-right font-medium text-error-ink tabular-nums">
                                                    {formatRupiah(penalty.amount)}
                                                </td>
                                            </tr>
                                        );
                                    })
                                )}
                            </tbody>
                        </table>
                    </TableCard>
                </div>

                {!isOwnView && (
                    <SectionCard
                        title="Per Tukang"
                        icon={Users}
                        description={
                            canRecordPayment
                                ? 'Klik nama untuk memfilter tabel, ikon untuk mencatat pembayaran.'
                                : 'Klik nama untuk memfilter tabel.'
                        }
                        className="order-1 h-fit lg:order-2"
                        contentClassName="p-2 sm:p-2"
                    >
                        {perStaff.length === 0 ? (
                            <p className="p-2 text-sm text-daiku-muted">Belum ada penalti.</p>
                        ) : (
                            <ul className="divide-y divide-border">
                                {perStaff.map((row) => {
                                    const active = filters.staff_id === String(row.staffId);

                                    return (
                                        <li key={row.staffId} className="flex items-center gap-1.5 py-0.5">
                                            <button
                                                type="button"
                                                onClick={() => applyFilter({ staff_id: active ? undefined : String(row.staffId) })}
                                                aria-pressed={active}
                                                className={cn(
                                                    'flex min-w-0 flex-1 items-center justify-between gap-3 rounded-md px-2.5 py-2 text-left text-sm transition-colors hover:bg-daiku-gray/70',
                                                    active && 'bg-daiku-yellow-light hover:bg-daiku-yellow-light',
                                                )}
                                            >
                                                <span className="min-w-0">
                                                    <span className="block truncate font-medium text-daiku-dark">{row.name}</span>
                                                    <span className="block text-xs text-daiku-muted">
                                                        {row.count}× · terakhir {formatDate(row.lastDate)}
                                                    </span>
                                                </span>
                                                <span className="shrink-0 text-right">
                                                    <span className="block font-semibold tabular-nums">{formatRupiah(row.total)}</span>
                                                    {row.outstanding > 0 ? (
                                                        <span className="block text-xs text-warning-ink tabular-nums">
                                                            Belum {formatRupiah(row.outstanding)}
                                                        </span>
                                                    ) : (
                                                        <span className="block text-xs text-success-ink">Lunas</span>
                                                    )}
                                                </span>
                                            </button>
                                            {canRecordPayment && row.unpaidCount > 0 && (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="icon-sm"
                                                    title={`Catat pembayaran ${row.name}`}
                                                    aria-label={`Catat pembayaran ${row.name}`}
                                                    onClick={() => setPayingStaff({ id: row.staffId, name: row.name })}
                                                >
                                                    <HandCoins className="size-4" />
                                                </Button>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        )}
                    </SectionCard>
                )}
            </div>

            {canRecordPayment && (
                <PenaltyPaymentDialog
                    staff={payingStaff}
                    penalties={payingPenalties}
                    bankAccounts={bankAccounts}
                    onOpenChange={(open) => !open && setPayingStaff(null)}
                />
            )}
        </AppLayout>
    );
}
