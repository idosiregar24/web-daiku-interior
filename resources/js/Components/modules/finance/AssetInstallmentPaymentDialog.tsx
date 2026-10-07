import { suggestedInstallment } from '@/Components/modules/finance/assetInstallmentStatus';
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
import type { Asset, BankAccount } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { endOfDay, format } from 'date-fns';
import { useEffect, useMemo } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

interface AssetInstallmentPaymentDialogProps {
    /** null = closed. */
    asset: Pick<Asset, 'id' | 'name' | 'installment_amount' | 'remaining_install'> | null;
    bankAccounts: Pick<BankAccount, 'id' | 'label'>[];
    onOpenChange: (open: boolean) => void;
}

/**
 * "Bayar Cicilan" — mirrors StoreAssetInstallmentPaymentRequest; the
 * "≤ sisa cicilan" cap is re-checked server-side under a row lock
 * (AssetInstallmentService::recordPayment()). Recorded as PENGELUARAN /
 * Angsuran on the chosen account.
 */
export function AssetInstallmentPaymentDialog({ asset, bankAccounts, onOpenChange }: AssetInstallmentPaymentDialogProps) {
    const remaining = Number(asset?.remaining_install ?? 0);

    const schema = useMemo(
        () =>
            z.object({
                amount: z
                    .string()
                    .min(1, 'Nominal pembayaran wajib diisi.')
                    .refine((v) => !isNaN(Number(v)) && Number(v) > 0, 'Nominal pembayaran harus lebih dari 0.')
                    .refine((v) => Number(v) <= remaining, `Nominal pembayaran melebihi sisa cicilan (${formatRupiah(remaining)}).`),
                paid_at: z
                    .date({ message: 'Tanggal bayar wajib diisi.' })
                    .refine((d) => d <= endOfDay(new Date()), 'Tanggal bayar tidak boleh di masa depan.'),
                bank_account_id: z.string().min(1, 'Rekening sumber wajib dipilih.'),
                note: z.string().max(255, 'Catatan maksimal 255 karakter.').optional(),
            }),
        [remaining],
    );

    type FormValues = z.infer<typeof schema>;

    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { amount: '', paid_at: new Date(), bank_account_id: '', note: '' },
    });

    useEffect(() => {
        if (asset) {
            const suggested = suggestedInstallment(asset);

            form.reset({
                amount: suggested > 0 ? String(suggested) : '',
                paid_at: new Date(),
                bank_account_id: bankAccounts.length === 1 ? String(bankAccounts[0].id) : '',
                note: '',
            });
        }
    }, [asset]);

    function onSubmit(values: FormValues) {
        if (!asset) return;

        router.post(
            route('finance.assetInstallments.storePayment', { asset: asset.id }),
            {
                amount: Number(values.amount),
                paid_at: format(values.paid_at, 'yyyy-MM-dd'),
                bank_account_id: Number(values.bank_account_id),
                note: values.note || null,
            },
            {
                preserveScroll: true,
                onError: (errors: Record<string, string>) => {
                    Object.entries(errors).forEach(([field, message]) => {
                        form.setError(field in form.getValues() ? (field as keyof FormValues) : 'amount', { message });
                    });
                },
                onSuccess: () => onOpenChange(false),
            },
        );
    }

    return (
        <Dialog open={asset !== null} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Bayar Cicilan</DialogTitle>
                    <DialogDescription>
                        {asset?.name} — sisa cicilan {formatRupiah(remaining)}. Pembayaran otomatis tercatat sebagai
                        pengeluaran Angsuran.
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
                                            <Input type="number" min="0" step="0.01" inputMode="decimal" {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="paid_at"
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
                                    <FormLabel required>Rekening Sumber</FormLabel>
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
                                        <Input {...field} placeholder="mis. Cicilan ke-5" />
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
