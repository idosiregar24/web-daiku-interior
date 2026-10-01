import { AssetInstallmentPaymentDialog } from '@/Components/modules/finance/AssetInstallmentPaymentDialog';
import { ASSET_INSTALLMENT_STATUS_LABELS, installmentProgress } from '@/Components/modules/finance/assetInstallmentStatus';
import { DataTable } from '@/Components/shared/DataTable';
import { DetailItem, DetailList } from '@/Components/shared/DetailList';
import { Notice } from '@/Components/shared/Notice';
import { PageHeader } from '@/Components/shared/PageHeader';
import { ProgressBar } from '@/Components/shared/ProgressBar';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatCard } from '@/Components/shared/StatCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate, formatDateTime, formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { AssetInstallmentPayment, BankAccount, InstallmentAsset } from '@/types';
import { Head } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { AlertTriangle, CalendarClock, CheckCircle2, FileText, History, Wallet } from 'lucide-react';
import { useState } from 'react';

interface AssetInstallmentShowProps {
    asset: InstallmentAsset;
    canPay: boolean;
    bankAccounts: Pick<BankAccount, 'id' | 'label'>[];
}

const paymentColumns: ColumnDef<AssetInstallmentPayment>[] = [
    {
        accessorKey: 'paid_at',
        header: 'Tanggal Bayar',
        cell: ({ row }) => formatDate(row.original.paid_at),
    },
    {
        accessorKey: 'amount',
        header: () => <div className="text-right">Nominal</div>,
        cell: ({ row }) => (
            <div className="text-right font-medium text-error-ink tabular-nums">-{formatRupiah(row.original.amount)}</div>
        ),
    },
    {
        id: 'bank_account',
        header: 'Rekening',
        enableSorting: false,
        cell: ({ row }) => <span className="text-daiku-muted">{row.original.bank_account?.label ?? '—'}</span>,
    },
    {
        accessorKey: 'note',
        header: 'Catatan',
        enableSorting: false,
        cell: ({ row }) => row.original.note ?? '—',
    },
    {
        id: 'creator',
        header: 'Dicatat oleh',
        enableSorting: false,
        cell: ({ row }) => (
            <div>
                <p>{row.original.creator?.name ?? '—'}</p>
                <p className="text-xs text-daiku-muted">{formatDateTime(row.original.created_at)}</p>
            </div>
        ),
    },
];

/** PRD §4.7 "Aset & Cicilan" — one asset's plan and payment history. Payments: Finance only (server-enforced). */
export default function AssetInstallmentShow({ asset, canPay, bankAccounts }: AssetInstallmentShowProps) {
    const [paying, setPaying] = useState<InstallmentAsset | null>(null);
    const status = asset.installment_status;
    const isOverdue = status === 'JATUH_TEMPO';
    const isPaidOff = status === 'LUNAS';

    return (
        <AppLayout breadcrumbs={[{ label: asset.name }]}>
            <Head title={`Cicilan ${asset.name}`} />

            <PageHeader
                title={asset.name}
                icon={CalendarClock}
                description={[asset.category, asset.location].filter(Boolean).join(' · ') || undefined}
                actions={
                    canPay &&
                    !isPaidOff && (
                        <Button size="sm" onClick={() => setPaying(asset)}>
                            <Wallet className="size-4" />
                            Bayar Cicilan
                        </Button>
                    )
                }
            />

            {isOverdue && (
                <Notice tone="error" icon={AlertTriangle} className="mb-6">
                    Cicilan bulan ini belum dibayar dan sudah lewat tanggal jatuh tempo ({asset.installment_due_day}). Sisa
                    cicilan {formatRupiah(asset.remaining_install)}.
                </Notice>
            )}

            <div className="mb-6 grid gap-4 sm:grid-cols-3">
                <StatCard label="Total Cicilan" value={formatRupiah(asset.total_install)} icon={CalendarClock}>
                    <ProgressBar
                        value={installmentProgress(asset)}
                        label={`Progres cicilan ${asset.name}`}
                        tone={isPaidOff ? 'success' : isOverdue ? 'error' : 'default'}
                        showValue
                    />
                </StatCard>
                <StatCard label="Sudah Dibayar" value={formatRupiah(asset.paid_install)} icon={CheckCircle2} tone="success" />
                <StatCard
                    label="Sisa Cicilan"
                    value={formatRupiah(asset.remaining_install)}
                    icon={Wallet}
                    tone={isOverdue ? 'error' : isPaidOff ? 'success' : 'default'}
                    className={cn(isOverdue && 'bg-error/5 ring-error/40')}
                />
            </div>

            <SectionCard title="Rencana Cicilan" icon={FileText} className="mb-6">
                <DetailList className="sm:grid-cols-4">
                    <DetailItem label="Status">
                        <StatusChip status={status} label={ASSET_INSTALLMENT_STATUS_LABELS[status]} />
                    </DetailItem>
                    <DetailItem label="Cicilan per Bulan" valueClassName="font-medium">
                        {asset.installment_amount ? formatRupiah(asset.installment_amount) : '—'}
                    </DetailItem>
                    <DetailItem label="Jatuh Tempo" valueClassName={cn('font-medium', isOverdue && 'text-error-ink')}>
                        {asset.installment_due_day ? `Setiap tanggal ${asset.installment_due_day}` : '—'}
                    </DetailItem>
                    <DetailItem label="Nilai Aset" valueClassName="font-medium">
                        {asset.value ? formatRupiah(asset.value) : '—'}
                    </DetailItem>
                    <DetailItem label="Tanggal Beli">{formatDate(asset.purchase_date)}</DetailItem>
                    <DetailItem label="Catatan" className="sm:col-span-3" valueClassName="whitespace-pre-line">
                        {asset.notes || '—'}
                    </DetailItem>
                </DetailList>
            </SectionCard>

            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold text-foreground">
                <History className="size-4 text-muted-foreground" />
                Riwayat Pembayaran
            </h2>
            <DataTable
                columns={paymentColumns}
                data={asset.installment_payments ?? []}
                emptyMessage="Belum ada pembayaran cicilan."
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
