import { DatePicker } from '@/Components/shared/DatePicker';
import { Notice } from '@/Components/shared/Notice';
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
import { Form, FormControl, FormDescription, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { formatRupiah } from '@/lib/format';
import type { BankAccount } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const MAX_AMOUNT = 9_999_999_999_999.99;

function today() {
    return format(new Date(), 'yyyy-MM-dd');
}

// Mirrors StoreFundTransferRequest (FundTransferService re-checks all of it).
const schema = z
    .object({
        from_bank_account_id: z.string().min(1, 'Rekening asal wajib dipilih'),
        to_bank_account_id: z.string().min(1, 'Rekening tujuan wajib dipilih'),
        amount: z
            .string()
            .min(1, 'Nominal wajib diisi')
            .refine((v) => !isNaN(Number(v)), 'Nominal harus berupa angka')
            .refine((v) => Number(v) > 0, 'Nominal harus lebih dari 0')
            .refine((v) => Number(v) <= MAX_AMOUNT, 'Nominal terlalu besar'),
        date: z
            .date({ message: 'Tanggal wajib diisi' })
            .refine((d) => format(d, 'yyyy-MM-dd') <= today(), 'Tanggal pindah dana tidak boleh di masa depan'),
        description: z.string().trim().min(1, 'Keterangan wajib diisi').max(150, 'Keterangan maksimal 150 karakter'),
    })
    .refine((values) => values.from_bank_account_id !== values.to_bank_account_id, {
        path: ['to_bank_account_id'],
        message: 'Rekening tujuan harus berbeda dari rekening asal',
    });

type FormValues = z.infer<typeof schema>;

const FIELDS: (keyof FormValues)[] = ['from_bank_account_id', 'to_bank_account_id', 'amount', 'date', 'description'];

function emptyValues(): FormValues {
    return { from_bank_account_id: '', to_bank_account_id: '', amount: '', date: new Date(), description: '' };
}

type TransferAccount = Pick<BankAccount, 'id' | 'label' | 'current_balance'>;

interface FundTransferDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Active accounts only, loaded with their derived balance (BankAccount::withBalance()). */
    bankAccounts: TransferAccount[];
}

/**
 * PRD §4.7 "Pindah Dana" between the company's own accounts — one submit
 * books two PINDAH_DANA legs (keluar dari rekening asal, masuk ke
 * rekening tujuan), see FundTransferService. The source balance is shown
 * as a warning only: it is derived from the recorded transactions, so it
 * doesn't block recording a transfer that really happened.
 */
export function FundTransferDialog({ open, onOpenChange, bankAccounts }: FundTransferDialogProps) {
    const [processing, setProcessing] = useState(false);
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: emptyValues() });

    useEffect(() => {
        if (open) {
            form.reset(emptyValues());
        }
    }, [open]);

    const fromId = form.watch('from_bank_account_id');
    const amount = Number(form.watch('amount') || 0);
    const source = bankAccounts.find((account) => String(account.id) === fromId);
    const exceedsBalance = source?.current_balance !== undefined && amount > source.current_balance;

    function onSubmit(values: FormValues) {
        router.post(
            route('finance.transfers.store'),
            {
                from_bank_account_id: Number(values.from_bank_account_id),
                to_bank_account_id: Number(values.to_bank_account_id),
                amount: Number(values.amount),
                date: format(values.date, 'yyyy-MM-dd'),
                description: values.description,
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (errors: Record<string, string>) => {
                    Object.entries(errors).forEach(([field, message]) => {
                        const target = FIELDS.includes(field as keyof FormValues) ? (field as keyof FormValues) : 'amount';
                        form.setError(target, { message });
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
                    <DialogTitle>Pindah Dana</DialogTitle>
                    <DialogDescription>
                        Memindahkan uang antar rekening perusahaan. Tercatat sebagai pengeluaran di rekening asal dan
                        pemasukan di rekening tujuan, tanpa mengubah total arus kas perusahaan.
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <FormField
                                control={form.control}
                                name="from_bank_account_id"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Dari Rekening</FormLabel>
                                        <Select value={field.value} onValueChange={field.onChange}>
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue placeholder="Pilih rekening asal" />
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
                                        {source?.current_balance !== undefined && (
                                            <FormDescription>Saldo saat ini {formatRupiah(source.current_balance)}</FormDescription>
                                        )}
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="to_bank_account_id"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Ke Rekening</FormLabel>
                                        <Select value={field.value} onValueChange={field.onChange}>
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue placeholder="Pilih rekening tujuan" />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                {bankAccounts.map((account) => (
                                                    <SelectItem
                                                        key={account.id}
                                                        value={String(account.id)}
                                                        disabled={String(account.id) === fromId}
                                                    >
                                                        {account.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <div className="grid gap-4 sm:grid-cols-2">
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
                                name="date"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Tanggal</FormLabel>
                                        <FormControl>
                                            <DatePicker value={field.value} onChange={field.onChange} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        {exceedsBalance && (
                            <Notice tone="warning">
                                Nominal melebihi saldo tercatat rekening asal ({formatRupiah(source?.current_balance)}). Pastikan
                                saldo awal dan transaksi rekening ini sudah lengkap.
                            </Notice>
                        )}
                        <FormField
                            control={form.control}
                            name="description"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Keterangan</FormLabel>
                                    <FormControl>
                                        <Input {...field} maxLength={150} placeholder="mis. Top up rekening operasional" />
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
                            <Button type="submit" disabled={processing}>
                                Simpan
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
