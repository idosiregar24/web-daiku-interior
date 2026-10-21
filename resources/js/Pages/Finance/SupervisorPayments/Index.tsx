import { DataTable } from '@/Components/shared/DataTable';
import { ModuleTabs } from '@/Components/shared/ModuleTabs';
import { PageHeader } from '@/Components/shared/PageHeader';
import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogClose, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { TabsList, TabsTrigger, Tabs } from '@/Components/ui/tabs';
import { useLastDefined } from '@/hooks/useLastDefined';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate, formatRupiah } from '@/lib/format';
import type { BankAccount, PaginatedData, SupervisorPayment } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { HardHat } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

type BankOption = Pick<BankAccount, 'id' | 'label'>;

interface SupervisorPaymentsIndexProps {
    payments: PaginatedData<SupervisorPayment>;
    filters: { status: 'to_pay' | 'paid' };
    canPay: boolean;
    bankAccounts: BankOption[];
}

/**
 * Sprint 24 D4 — Jasa Pengawas the PM approved ("Bayar Pengawas: DP ·
 * Proyek X"), paid by Finance as a PENGELUARAN "Jasa Pengawas"; the
 * "Sudah Dibayar" tab is the history. CEO reads.
 */
export default function SupervisorPaymentsIndex({ payments, filters, canPay, bankAccounts }: SupervisorPaymentsIndexProps) {
    const [paying, setPaying] = useState<SupervisorPayment | null>(null);
    const toPay = filters.status === 'to_pay';

    const columns: ColumnDef<SupervisorPayment>[] = [
        {
            id: 'label',
            header: 'Pembayaran',
            cell: ({ row }) => (
                <Link href={route('projects.show', { project: row.original.project_id, tab: 'execution' })} className="font-medium hover:underline">
                    {row.original.label}
                </Link>
            ),
        },
        { id: 'supervisor', header: 'Pengawas', cell: ({ row }) => row.original.supervisor?.name ?? '—' },
        { id: 'amount', header: 'Nominal', cell: ({ row }) => formatRupiah(row.original.amount) },
        {
            id: 'when',
            header: toPay ? 'Disetujui PM' : 'Dibayar',
            cell: ({ row }) =>
                toPay
                    ? `${formatDate(row.original.decided_at)} · ${row.original.decider?.name ?? ''}`
                    : `${formatDate(row.original.paid_at)} · ${row.original.payer?.name ?? ''}`,
        },
        { id: 'status', header: 'Status', cell: ({ row }) => <StatusChip domain="supervisorPayment" status={row.original.status} /> },
        {
            id: 'actions',
            header: '',
            cell: ({ row }) =>
                canPay && row.original.status === 'DISETUJUI_PM' ? (
                    <Button size="sm" variant="outline" onClick={() => setPaying(row.original)}>
                        Bayar
                    </Button>
                ) : null,
        },
    ];

    return (
        <AppLayout>
            <Head title="Upah Tukang" />
            <PageHeader title="Upah Tukang" icon={HardHat} description="Pembayaran upah tukang (DP, mingguan, pelunasan) yang sudah disetujui PM proyeknya." />
            <ModuleTabs />

            <DataTable
                columns={columns}
                data={payments.data}
                pagination={payments}
                emptyMessage={toPay ? 'Tidak ada upah tukang yang menunggu dibayar.' : 'Belum ada pembayaran.'}
                toolbar={
                    <Tabs value={filters.status} onValueChange={(value) => router.get(route('finance.supervisor-payments.index'), value === 'paid' ? { status: 'paid' } : {})}>
                        <TabsList>
                            <TabsTrigger value="to_pay">Menunggu Dibayar</TabsTrigger>
                            <TabsTrigger value="paid">Sudah Dibayar</TabsTrigger>
                        </TabsList>
                    </Tabs>
                }
                mobileCard={(payment) => (
                    <div className="space-y-1">
                        <p className="font-medium text-foreground">{payment.label}</p>
                        <p className="text-xs text-muted-foreground">
                            {payment.supervisor?.name} · {formatRupiah(payment.amount)}
                        </p>
                        <div className="flex items-center justify-between gap-2">
                            <StatusChip domain="supervisorPayment" status={payment.status} />
                            {canPay && payment.status === 'DISETUJUI_PM' && (
                                <Button size="sm" onClick={() => setPaying(payment)}>
                                    Bayar
                                </Button>
                            )}
                        </div>
                    </div>
                )}
            />

            {canPay && <PayDialog payment={paying} bankAccounts={bankAccounts} onOpenChange={(open) => !open && setPaying(null)} />}
        </AppLayout>
    );
}

// Mirrors PayStaffRequest.
const schema = z.object({ bank_account_id: z.string().min(1, 'Rekening sumber wajib dipilih') });

function PayDialog({ payment, bankAccounts, onOpenChange }: { payment: SupervisorPayment | null; bankAccounts: BankOption[]; onOpenChange: (open: boolean) => void }) {
    const form = useForm<z.infer<typeof schema>>({ resolver: zodResolver(schema), defaultValues: { bank_account_id: '' } });
    const shown = useLastDefined(payment);

    useEffect(() => {
        if (payment) form.reset({ bank_account_id: '' });
    }, [payment, form]);

    function onSubmit(values: z.infer<typeof schema>) {
        if (!payment) return;
        router.post(
            route('finance.supervisor-payments.pay', { supervisorPayment: payment.id }),
            { bank_account_id: Number(values.bank_account_id) },
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onError: (errors) => Object.values(errors).forEach((message) => form.setError('bank_account_id', { message })),
            },
        );
    }

    return (
        <Dialog open={payment !== null} onOpenChange={onOpenChange}>
            <ResponsiveDialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Bayar Upah Tukang: {shown?.label}</DialogTitle>
                    <DialogDescription>
                        {formatRupiah(shown?.amount)} untuk {shown?.supervisor?.name} — dicatat sebagai pengeluaran "Upah Tukang".
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="bank_account_id"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Rekening sumber</FormLabel>
                                    <Select value={field.value} onValueChange={field.onChange}>
                                        <FormControl>
                                            <SelectTrigger>
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
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    Batal
                                </Button>
                            </DialogClose>
                            <Button type="submit" disabled={form.formState.isSubmitting}>
                                Catat Pembayaran
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </ResponsiveDialogContent>
        </Dialog>
    );
}
