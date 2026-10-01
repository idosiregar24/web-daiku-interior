import { AssetInstallmentPaymentDialog } from '@/Components/modules/finance/AssetInstallmentPaymentDialog';
import { ASSET_INSTALLMENT_STATUS_LABELS, installmentProgress } from '@/Components/modules/finance/assetInstallmentStatus';
import { DataTable } from '@/Components/shared/DataTable';
import { PageHeader } from '@/Components/shared/PageHeader';
import { ProgressBar } from '@/Components/shared/ProgressBar';
import { SearchInput } from '@/Components/shared/SearchInput';
import { StatCard } from '@/Components/shared/StatCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate, formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { AssetInstallmentStatus, BankAccount, InstallmentAsset, PaginatedData } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { AlarmClock, CalendarClock, CheckCircle2, Eye, Wallet } from 'lucide-react';
import { useEffect, useState } from 'react';

interface AssetInstallmentIndexProps {
    assets: PaginatedData<InstallmentAsset>;
    filters: { status?: string; search?: string };
    summary: {
        totalInstall: number;
        totalPaid: number;
        totalRemaining: number;
        outstandingCount: number;
        overdueCount: number;
        dueThisMonth: number;
    };
    canPay: boolean;
    bankAccounts: Pick<BankAccount, 'id' | 'label'>[];
}

const PROGRESS_TONE: Record<AssetInstallmentStatus, 'default' | 'success' | 'error'> = {
    BERJALAN: 'default',
    JATUH_TEMPO: 'error',
    LUNAS: 'success',
};

/**
 * PRD §4.7 "Aset & Cicilan" — company assets still being paid off. Plans
 * are set by Logistics on the Aset Inventaris form; Finance records the
 * payments (PENGELUARAN / Angsuran). Readable by CEO, PM, Finance and
 * Logistics (§7.1 "Asset Inventory").
 */
export default function AssetInstallmentIndex({ assets, filters, summary, canPay, bankAccounts }: AssetInstallmentIndexProps) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [paying, setPaying] = useState<InstallmentAsset | null>(null);

    function applyFilter(next: Partial<AssetInstallmentIndexProps['filters']>) {
        router.get(route('finance.assetInstallments.index'), { ...filters, ...next }, { preserveState: true, replace: true });
    }

    useEffect(() => {
        if (search === (filters.search ?? '')) {
            return;
        }

        const timeout = setTimeout(() => applyFilter({ search: search || undefined }), 350);

        return () => clearTimeout(timeout);
    }, [search]);

    const columns: ColumnDef<InstallmentAsset>[] = [
        {
            accessorKey: 'name',
            header: 'Aset',
            cell: ({ row }) => (
                <div>
                    <Link
                        href={route('finance.assetInstallments.show', { asset: row.original.id })}
                        className="font-medium text-daiku-dark hover:underline"
                    >
                        {row.original.name}
                    </Link>
                    <p className="text-xs text-daiku-muted">{row.original.category ?? '—'}</p>
                </div>
            ),
        },
        {
            accessorKey: 'installment_status',
            header: 'Status',
            cell: ({ row }) => (
                <StatusChip
                    status={row.original.installment_status}
                    label={ASSET_INSTALLMENT_STATUS_LABELS[row.original.installment_status]}
                />
            ),
        },
        {
            id: 'progress',
            header: 'Progres',
            enableSorting: false,
            cell: ({ row }) => (
                <ProgressBar
                    value={installmentProgress(row.original)}
                    label={`Progres cicilan ${row.original.name}`}
                    tone={PROGRESS_TONE[row.original.installment_status]}
                    showValue
                    className="w-36"
                />
            ),
        },
        {
            accessorKey: 'total_install',
            header: () => <div className="text-right">Total</div>,
            cell: ({ row }) => <div className="text-right tabular-nums">{formatRupiah(row.original.total_install)}</div>,
        },
        {
            accessorKey: 'paid_install',
            header: () => <div className="text-right">Terbayar</div>,
            cell: ({ row }) => (
                <div className="text-right text-daiku-muted tabular-nums">{formatRupiah(row.original.paid_install)}</div>
            ),
        },
        {
            accessorKey: 'remaining_install',
            header: () => <div className="text-right">Sisa</div>,
            cell: ({ row }) => (
                <div
                    className={cn(
                        'text-right font-semibold tabular-nums',
                        row.original.installment_status === 'JATUH_TEMPO'
                            ? 'text-error-ink'
                            : row.original.installment_status === 'LUNAS'
                              ? 'text-success-ink'
                              : 'text-daiku-dark',
                    )}
                >
                    {formatRupiah(row.original.remaining_install)}
                </div>
            ),
        },
        {
            id: 'schedule',
            header: 'Cicilan / Bulan',
            enableSorting: false,
            cell: ({ row }) => (
                <div className="text-xs">
                    <p className="text-daiku-dark tabular-nums">
                        {row.original.installment_amount ? formatRupiah(row.original.installment_amount) : '—'}
                    </p>
                    <p className="text-daiku-muted">
                        {row.original.installment_due_day ? `Jatuh tempo tgl ${row.original.installment_due_day}` : 'Tanpa jatuh tempo'}
                    </p>
                </div>
            ),
        },
        {
            accessorKey: 'last_paid_at',
            header: 'Bayar Terakhir',
            cell: ({ row }) => <span className="text-daiku-muted">{formatDate(row.original.last_paid_at)}</span>,
        },
        {
            id: 'actions',
            header: '',
            enableSorting: false,
            cell: ({ row }) => (
                <div className="flex justify-end gap-1">
                    {canPay && row.original.installment_status !== 'LUNAS' && (
                        <Button variant="outline" size="sm" onClick={() => setPaying(row.original)}>
                            <Wallet className="size-4" />
                            Bayar
                        </Button>
                    )}
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={route('finance.assetInstallments.show', { asset: row.original.id })}>
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
            <Head title="Cicilan Aset" />

            <PageHeader
                title="Cicilan Aset"
                icon={CalendarClock}
                description="Aset perusahaan yang masih dalam cicilan — rencana diisi Logistik, pembayaran dicatat Finance."
            />

            <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Total Cicilan" value={formatRupiah(summary.totalInstall)} icon={CalendarClock} />
                <StatCard label="Sudah Dibayar" value={formatRupiah(summary.totalPaid)} icon={CheckCircle2} tone="success" />
                <StatCard
                    label="Sisa Cicilan"
                    value={formatRupiah(summary.totalRemaining)}
                    icon={Wallet}
                    hint={`${summary.outstandingCount} aset masih berjalan`}
                />
                <StatCard
                    label="Tagihan Bulan Ini"
                    value={formatRupiah(summary.dueThisMonth)}
                    icon={AlarmClock}
                    tone={summary.overdueCount > 0 ? 'error' : 'default'}
                    hint={summary.overdueCount > 0 ? `${summary.overdueCount} aset lewat jatuh tempo` : 'Belum ada yang lewat jatuh tempo'}
                    className={cn(summary.overdueCount > 0 && 'bg-error/5 ring-error/40')}
                />
            </div>

            <DataTable
                columns={columns}
                data={assets.data}
                emptyMessage="Belum ada aset dengan rencana cicilan. Rencana cicilan diisi di menu Aset Inventaris."
                pagination={assets}
                toolbar={
                    <div className="flex flex-wrap items-center gap-2">
                        <SearchInput
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Cari nama atau lokasi aset…"
                            className="w-full sm:w-64"
                            aria-label="Cari aset"
                        />
                        <Select
                            value={filters.status ?? 'all'}
                            onValueChange={(value) => applyFilter({ status: value === 'all' ? undefined : value })}
                        >
                            <SelectTrigger className="w-44" aria-label="Filter status cicilan">
                                <SelectValue placeholder="Semua status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua status</SelectItem>
                                {(Object.keys(ASSET_INSTALLMENT_STATUS_LABELS) as AssetInstallmentStatus[]).map((status) => (
                                    <SelectItem key={status} value={status}>
                                        {ASSET_INSTALLMENT_STATUS_LABELS[status]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                }
            />

            {canPay && (
                <AssetInstallmentPaymentDialog
                    asset={paying}
                    bankAccounts={bankAccounts}
                    onOpenChange={(open) => !open && setPaying(null)}
                />
            )}
        </AppLayout>
    );
}
