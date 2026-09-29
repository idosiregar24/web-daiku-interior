import { STAFF_LOAN_STATUS_LABEL, staffLoanStatus } from '@/Components/modules/finance/staffLoanStatus';
import { DataTable } from '@/Components/shared/DataTable';
import { DatePicker } from '@/Components/shared/DatePicker';
import { PageHeader } from '@/Components/shared/PageHeader';
import { DetailItem, DetailList } from '@/Components/shared/DetailList';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatCard } from '@/Components/shared/StatCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate, formatDateTime, formatRupiah } from '@/lib/format';
import type { BankAccount, PageProps, StaffLoan, StaffLoanPayment } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router, usePage } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { format } from 'date-fns';
import { FileText, HandCoins, History, Plus, Receipt, Wallet } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const columns: ColumnDef<StaffLoanPayment>[] = [
    {
        accessorKey: 'paid_date',
        header: 'Tanggal Bayar',
        cell: ({ row }) => formatDate(row.original.paid_date),
    },
    {
        accessorKey: 'amount',
        header: 'Nominal',
        cell: ({ row }) => <span className="font-medium tabular-nums text-success-ink">{formatRupiah(row.original.amount)}</span>,
    },
    {
        id: 'source',
        header: 'Sumber',
        enableSorting: false,
        cell: ({ row }) => (
            <StatusChip
                // Tone only (label overridden): info for wage deductions, neutral for cash.
                status={row.original.task_id ? 'ONPROGRESS' : 'NEUTRAL'}
                label={row.original.task_id ? 'Potong upah' : 'Tunai'}
            />
        ),
    },
    {
        accessorKey: 'note',
        header: 'Catatan',
        enableSorting: false,
        cell: ({ row }) => <span className="text-daiku-muted">{row.original.note ?? '—'}</span>,
    },
    {
        id: 'creator',
        header: 'Dicatat oleh',
        enableSorting: false,
        cell: ({ row }) => (
            <div className="text-xs text-daiku-muted">
                <p>{row.original.creator?.name ?? '—'}</p>
                <p>{formatDateTime(row.original.created_at)}</p>
            </div>
        ),
    },
];

/** PRD §4.7 "Pinjaman Tukang" detail + payment history. Payments: FINANCE only. */
type BankOption = Pick<BankAccount, 'id' | 'label'>;

export default function StaffLoanShow({ loan, bankAccounts }: { loan: StaffLoan; bankAccounts: BankOption[] }) {
    const { auth } = usePage<PageProps>().props;
    const canManage = auth.user.role === 'FINANCE' || auth.user.role === 'SUPERADMIN';
    const [paymentOpen, setPaymentOpen] = useState(false);
    const status = staffLoanStatus(loan);

    return (
        <AppLayout
            breadcrumbs={[{ label: loan.staff?.name ?? `Pinjaman #${loan.id}` }]}
        >
            <Head title={`Pinjaman ${loan.staff?.name ?? ''}`} />

            <PageHeader
                title={`Pinjaman ${loan.staff?.name ?? ''}`}
                icon={HandCoins}
                description={loan.description ?? undefined}
                actions={
                    canManage &&
                    status === 'BERJALAN' && (
                        <Button size="sm" onClick={() => setPaymentOpen(true)}>
                            <Plus className="size-4" />
                            Catat Pembayaran
                        </Button>
                    )
                }
            />

            <div className="mb-6 grid gap-4 sm:grid-cols-3">
                <StatCard label="Nominal Pinjaman" value={formatRupiah(loan.amount)} icon={HandCoins} />
                <StatCard label="Sudah Dibayar" value={formatRupiah(loan.paid_amount)} icon={Receipt} tone="success" />
                <StatCard
                    label="Sisa Pinjaman"
                    value={formatRupiah(loan.remaining)}
                    icon={Wallet}
                    tone={status === 'LUNAS' ? 'success' : 'warning'}
                    hint={<StatusChip status={status} label={STAFF_LOAN_STATUS_LABEL[status]} />}
                />
            </div>

            <SectionCard title="Detail Pinjaman" icon={FileText} className="mb-6">
                <DetailList className="sm:grid-cols-4">
                    <DetailItem label="Cicilan per Upah" valueClassName="font-medium">
                        {formatRupiah(loan.installment_amount)}
                    </DetailItem>
                    <DetailItem label="Rekening Sumber" valueClassName="font-medium">
                        {loan.bank_account?.label ?? '—'}
                    </DetailItem>
                    <DetailItem label="Tanggal Pinjam" valueClassName="font-medium">
                        {formatDate(loan.created_at)}
                    </DetailItem>
                    <DetailItem label="Dicatat oleh" valueClassName="font-medium">
                        {loan.creator?.name ?? '—'}
                    </DetailItem>
                </DetailList>
            </SectionCard>

            <h2 className="mb-3 flex items-center gap-2 text-sm font-semibold text-foreground">
                <History className="size-4 text-muted-foreground" />
                Riwayat Pembayaran
            </h2>
            <DataTable columns={columns} data={loan.payments ?? []} emptyMessage="Belum ada pembayaran." />

            {canManage && (
                <PaymentDialog loan={loan} bankAccounts={bankAccounts} open={paymentOpen} onOpenChange={setPaymentOpen} />
            )}
        </AppLayout>
    );
}

interface PaymentDialogProps {
    loan: StaffLoan;
    bankAccounts: BankOption[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

function PaymentDialog({ loan, bankAccounts, open, onOpenChange }: PaymentDialogProps) {
    const remaining = Number(loan.remaining);

    // Mirrors StoreStaffLoanPaymentRequest + the service's "≤ sisa pinjaman" rule.
    const schema = z.object({
        amount: z
            .string()
            .min(1, 'Nominal pembayaran wajib diisi')
            .refine((v) => !isNaN(Number(v)) && Number(v) > 0, 'Nominal pembayaran harus lebih dari 0')
            .refine((v) => Number(v) <= remaining, `Nominal melebihi sisa pinjaman (${formatRupiah(remaining)})`),
        paid_date: z
            .date({ message: 'Tanggal bayar wajib diisi' })
            .refine((d) => d <= new Date(), 'Tanggal bayar tidak boleh di masa depan'),
        note: z.string().max(255, 'Catatan maksimal 255 karakter').optional(),
        bank_account_id: z.string().min(1, 'Rekening penerima wajib dipilih'),
    });

    type FormValues = z.infer<typeof schema>;

    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { amount: '', paid_date: new Date(), note: '', bank_account_id: '' },
    });

    useEffect(() => {
        if (open) {
            form.reset({ amount: '', paid_date: new Date(), note: '', bank_account_id: '' });
        }
    }, [open]);

    function onSubmit(values: FormValues) {
        router.post(
            route('finance.staffLoans.storePayment', { staffLoan: loan.id }),
            {
                amount: Number(values.amount),
                paid_date: format(values.paid_date, 'yyyy-MM-dd'),
                note: values.note || null,
                bank_account_id: Number(values.bank_account_id),
            },
            {
                preserveScroll: true,
                onError: (errors) => {
                    Object.entries(errors).forEach(([field, message]) => {
                        form.setError(field as keyof FormValues, { message: message as string });
                    });
                },
                onSuccess: () => onOpenChange(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Catat Pembayaran</DialogTitle>
                    <DialogDescription>
                        Pengembalian tunai dari {loan.staff?.name ?? 'tukang'}. Sisa pinjaman: {formatRupiah(remaining)}.
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                control={form.control}
                                name="amount"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Nominal (Rp)</FormLabel>
                                        <FormControl>
                                            <Input type="number" min="0" step="0.01" {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="paid_date"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Tanggal Bayar</FormLabel>
                                        <FormControl>
                                            <DatePicker value={field.value} onChange={field.onChange} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <FormField
                            control={form.control}
                            name="bank_account_id"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Rekening Penerima</FormLabel>
                                    <Select value={field.value} onValueChange={field.onChange}>
                                        <FormControl>
                                            <SelectTrigger className="w-full">
                                                <SelectValue placeholder="Pilih rekening" />
                                            </SelectTrigger>
                                        </FormControl>
                                        <SelectContent>
                                            {bankAccounts.map((account) => (
                                                <SelectItem key={account.id} value={String(account.id)}>
                                                    {account.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="note"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Catatan (opsional)</FormLabel>
                                    <FormControl>
                                        <Input {...field} placeholder="mis. Bayar tunai di kantor" />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    Batal
                                </Button>
                            </DialogClose>
                            <Button type="submit" disabled={form.formState.isSubmitting}>
                                Simpan
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
