import { SupplierDebtPaymentDialog } from '@/Components/modules/finance/SupplierDebtPaymentDialog';
import {
    SUPPLIER_DEBT_STATUS_LABELS,
    type SupplierDebtWithStatus,
} from '@/Components/modules/finance/supplierDebtStatus';
import { DataTable } from '@/Components/shared/DataTable';
import { DetailItem, DetailList } from '@/Components/shared/DetailList';
import { Notice } from '@/Components/shared/Notice';
import { PageHeader } from '@/Components/shared/PageHeader';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatCard } from '@/Components/shared/StatCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate, formatDateTime, formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { BankAccount, PageProps, SupplierDebtPayment } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { AlertTriangle, CheckCircle2, FileText, History, Receipt, Wallet } from 'lucide-react';
import { useState } from 'react';

interface SupplierDebtShowProps {
    debt: SupplierDebtWithStatus;
    bankAccounts: Pick<BankAccount, 'id' | 'label'>[];
}

const paymentColumns: ColumnDef<SupplierDebtPayment>[] = [
    {
        accessorKey: 'paid_date',
        header: 'Tanggal Bayar',
        cell: ({ row }) => formatDate(row.original.paid_date),
    },
    {
        accessorKey: 'amount',
        header: () => <div className="text-right">Nominal</div>,
        cell: ({ row }) => <div className="text-right font-medium text-error-ink">-{formatRupiah(row.original.amount)}</div>,
    },
    {
        id: 'bank_account',
        header: 'Rekening',
        cell: ({ row }) => <span className="text-daiku-muted">{row.original.bank_account?.label ?? '—'}</span>,
    },
    {
        accessorKey: 'note',
        header: 'Catatan',
        cell: ({ row }) => row.original.note ?? '—',
    },
    {
        id: 'creator',
        header: 'Dicatat oleh',
        cell: ({ row }) => (
            <div>
                <p>{row.original.creator?.name ?? '—'}</p>
                <p className="text-xs text-daiku-muted">{formatDateTime(row.original.created_at)}</p>
            </div>
        ),
    },
];

/** PRD §4.7 "Hutang Supplier + riwayat pembayaran". Payment recording: Finance only (server-enforced). */
export default function SupplierDebtShow({ debt, bankAccounts }: SupplierDebtShowProps) {
    const { auth } = usePage<PageProps>().props;
    const canManage = auth.user.role === 'FINANCE' || auth.user.role === 'SUPERADMIN';
    const [paymentOpen, setPaymentOpen] = useState(false);
    const isOverdue = debt.status === 'JATUH_TEMPO';
    const isPaidOff = debt.status === 'LUNAS';

    return (
        <AppLayout
            breadcrumbs={[{ label: debt.supplier_name }]}
        >
            <Head title={`Hutang ${debt.supplier_name}`} />

            <PageHeader
                title={debt.supplier_name}
                icon={Receipt}
                description={debt.project ? `Proyek: ${debt.project.name}` : 'Tidak terkait proyek'}
                actions={
                    canManage &&
                    !isPaidOff && (
                        <Button size="sm" onClick={() => setPaymentOpen(true)}>
                            <Wallet className="size-4" />
                            Catat Pembayaran
                        </Button>
                    )
                }
            />

            {isOverdue && (
                <Notice tone="error" icon={AlertTriangle} className="mb-6">
                    Hutang ini sudah lewat jatuh tempo ({formatDate(debt.due_date)}) dan masih tersisa{' '}
                    {formatRupiah(debt.remaining)}.
                </Notice>
            )}

            <div className="mb-6 grid gap-4 sm:grid-cols-3">
                <StatCard label="Total Hutang" value={formatRupiah(debt.total_amount)} icon={Receipt} />
                <StatCard label="Terbayar" value={formatRupiah(debt.paid_amount)} icon={CheckCircle2} tone="success" />
                <StatCard
                    label="Sisa Hutang"
                    value={formatRupiah(debt.remaining)}
                    icon={Wallet}
                    tone={isOverdue ? 'error' : 'default'}
                    className={cn(isOverdue && 'bg-error/5 ring-error/40')}
                />
            </div>

            <SectionCard title="Detail Hutang" icon={FileText} className="mb-6">
                <DetailList>
                    <DetailItem label="Status">
                        <StatusChip status={debt.status} label={SUPPLIER_DEBT_STATUS_LABELS[debt.status]} />
                    </DetailItem>
                    <DetailItem label="Jatuh Tempo" valueClassName={cn(isOverdue && 'font-medium text-error-ink')}>
                        {formatDate(debt.due_date)}
                    </DetailItem>
                    <DetailItem label="Dicatat oleh">
                        {debt.creator?.name ?? '—'}{' '}
                        <span className="text-daiku-muted">· {formatDateTime(debt.created_at)}</span>
                    </DetailItem>
                    <DetailItem label="Keterangan" className="sm:col-span-2" valueClassName="whitespace-pre-line">
                        {debt.description || '—'}
                    </DetailItem>
                </DetailList>
            </SectionCard>

            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold text-foreground">
                <History className="size-4 text-muted-foreground" />
                Riwayat Pembayaran
            </h2>
            <DataTable
                columns={paymentColumns}
                data={debt.payments ?? []}
                emptyMessage="Belum ada pembayaran."
            />

            {canManage && !isPaidOff && (
                <SupplierDebtPaymentDialog
                    open={paymentOpen}
                    onOpenChange={setPaymentOpen}
                    debt={debt}
                    bankAccounts={bankAccounts}
                />
            )}
        </AppLayout>
    );
}
