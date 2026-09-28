import { SupplierDebtPaymentDialog } from '@/Components/modules/finance/SupplierDebtPaymentDialog';
import {
    SUPPLIER_DEBT_STATUS_LABELS,
    type SupplierDebtWithStatus,
} from '@/Components/modules/finance/supplierDebtStatus';
import { DataTable } from '@/Components/shared/DataTable';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate, formatDateTime, formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { BankAccount, PageProps, SupplierDebtPayment } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { AlertTriangle, Wallet } from 'lucide-react';
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
        cell: ({ row }) => <div className="text-right font-medium text-error">-{formatRupiah(row.original.amount)}</div>,
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
            breadcrumbs={[
                { label: 'Finance', routeName: 'finance.dashboard' },
                { label: 'Hutang Supplier', routeName: 'finance.supplierDebts.index' },
                { label: debt.supplier_name },
            ]}
        >
            <Head title={`Hutang ${debt.supplier_name}`} />

            <PageHeader
                title={debt.supplier_name}
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
                <div className="mb-4 flex items-center gap-2 rounded-lg border border-error/30 bg-error/10 p-3 text-sm text-error">
                    <AlertTriangle className="size-4 shrink-0" />
                    Hutang ini sudah lewat jatuh tempo ({formatDate(debt.due_date)}) dan masih tersisa{' '}
                    {formatRupiah(debt.remaining)}.
                </div>
            )}

            <div className="mb-4 grid gap-4 sm:grid-cols-3">
                <Card>
                    <CardContent className="pt-6">
                        <p className="text-xs text-daiku-muted">Total Hutang</p>
                        <p className="text-lg font-semibold text-daiku-dark">{formatRupiah(debt.total_amount)}</p>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="pt-6">
                        <p className="text-xs text-daiku-muted">Terbayar</p>
                        <p className="text-lg font-semibold text-success">{formatRupiah(debt.paid_amount)}</p>
                    </CardContent>
                </Card>
                <Card className={cn(isOverdue && 'border-error/40 bg-error/5')}>
                    <CardContent className="pt-6">
                        <p className="text-xs text-daiku-muted">Sisa Hutang</p>
                        <p className={cn('text-lg font-semibold', isOverdue ? 'text-error' : 'text-daiku-dark')}>
                            {formatRupiah(debt.remaining)}
                        </p>
                    </CardContent>
                </Card>
            </div>

            <Card className="mb-4">
                <CardHeader>
                    <CardTitle className="text-base">Detail Hutang</CardTitle>
                </CardHeader>
                <CardContent>
                    <dl className="grid gap-4 text-sm sm:grid-cols-2">
                        <div>
                            <dt className="text-xs text-daiku-muted">Status</dt>
                            <dd className="mt-1">
                                <StatusChip status={debt.status} label={SUPPLIER_DEBT_STATUS_LABELS[debt.status]} />
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-daiku-muted">Jatuh Tempo</dt>
                            <dd className={cn('mt-1', isOverdue && 'font-medium text-error')}>
                                {formatDate(debt.due_date)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-daiku-muted">Dicatat oleh</dt>
                            <dd className="mt-1">
                                {debt.creator?.name ?? '—'}{' '}
                                <span className="text-daiku-muted">· {formatDateTime(debt.created_at)}</span>
                            </dd>
                        </div>
                        <div className="sm:col-span-2">
                            <dt className="text-xs text-daiku-muted">Keterangan</dt>
                            <dd className="mt-1 whitespace-pre-line">{debt.description || '—'}</dd>
                        </div>
                    </dl>
                </CardContent>
            </Card>

            <h2 className="mb-2 text-sm font-semibold text-daiku-dark">Riwayat Pembayaran</h2>
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
