import { DatePicker } from '@/Components/shared/DatePicker';
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
import { formatRupiah } from '@/lib/format';
import type { BankAccount, SupplierDebt } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { useEffect, useMemo } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

interface SupplierDebtPaymentDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    debt: Pick<SupplierDebt, 'id' | 'vendor' | 'remaining'>;
    bankAccounts: Pick<BankAccount, 'id' | 'label'>[];
}

/**
 * "Catat Pembayaran" for a supplier debt — mirrors
 * StoreSupplierDebtPaymentRequest; the "≤ sisa hutang" cap is re-checked
 * server-side under a row lock (SupplierDebtService::recordPayment()).
 */
export function SupplierDebtPaymentDialog({ open, onOpenChange, debt, bankAccounts }: SupplierDebtPaymentDialogProps) {
    const remaining = Number(debt.remaining);

    const schema = useMemo(
        () =>
            z.object({
                amount: z
                    .string()
                    .min(1, 'Nominal pembayaran wajib diisi.')
                    .refine((v) => !isNaN(Number(v)) && Number(v) > 0, 'Nominal pembayaran harus lebih dari 0.')
                    .refine((v) => Number(v) <= remaining, 'Nominal pembayaran melebihi sisa hutang.'),
                paid_date: z.date({ message: 'Tanggal bayar wajib diisi.' }),
                bank_account_id: z.string().min(1, 'Rekening bank wajib dipilih.'),
                note: z.string().max(255, 'Catatan maksimal 255 karakter.').optional(),
            }),
        [remaining],
    );

    type FormValues = z.infer<typeof schema>;

    const emptyValues: FormValues = { amount: '', paid_date: new Date(), bank_account_id: '', note: '' };

    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: emptyValues });

    useEffect(() => {
        if (open) {
            form.reset(emptyValues);
        }
    }, [open]);

    function onSubmit(values: FormValues) {
        router.post(
            route('finance.supplierDebts.storePayment', { supplierDebt: debt.id }),
            {
                amount: Number(values.amount),
                paid_date: format(values.paid_date, 'yyyy-MM-dd'),
                bank_account_id: Number(values.bank_account_id),
                note: values.note || null,
            },
            {
                preserveScroll: true,
                onError: (errors: Record<string, string>) => {
                    Object.entries(errors).forEach(([field, message]) => {
                        form.setError(field as keyof FormValues, { message });
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
                        {debt.vendor?.name} — sisa hutang {formatRupiah(remaining)}. Pembayaran otomatis tercatat
                        sebagai pengeluaran Hutang Ideal.
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
                                        <FormLabel required>Nominal (Rp)</FormLabel>
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
                                        <FormLabel required>Tanggal Bayar</FormLabel>
                                        <FormControl>
                                            <DatePicker value={field.value} onChange={field.onChange} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <Button
                            type="button"
                            variant="link"
                            size="sm"
                            className="h-auto p-0"
                            onClick={() => form.setValue('amount', String(remaining), { shouldValidate: true })}
                        >
                            Bayar lunas ({formatRupiah(remaining)})
                        </Button>
                        <FormField
                            control={form.control}
                            name="bank_account_id"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Rekening</FormLabel>
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
                                    <FormLabel>Catatan</FormLabel>
                                    <FormControl>
                                        <Input {...field} placeholder="mis. Cicilan ke-2" />
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
