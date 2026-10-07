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
import { Textarea } from '@/Components/ui/textarea';
import { formatRupiah } from '@/lib/format';
import type { BankAccount } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { endOfDay, format } from 'date-fns';
import { useEffect, useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

interface FundExpenseDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** FamilyGatheringFundService::summary().spendable — re-checked server-side under the ledger lock. */
    spendable: number;
    bankAccounts: Pick<BankAccount, 'id' | 'label'>[];
}

/**
 * "Catat Penggunaan Dana" (PRD §4.7) — mirrors RecordFundExpenseRequest.
 * Paid out of the chosen account as a PENGELUARAN (kategori Lainnya), and
 * capped at what paid penalties have collected (Sprint 9 decision #10).
 */
export function FundExpenseDialog({ open, onOpenChange, spendable, bankAccounts }: FundExpenseDialogProps) {
    const [processing, setProcessing] = useState(false);

    const schema = useMemo(
        () =>
            z.object({
                amount: z
                    .string()
                    .min(1, 'Nominal wajib diisi.')
                    .refine((v) => !isNaN(Number(v)) && Number(v) > 0, 'Nominal harus lebih dari 0.')
                    .refine((v) => Number(v) <= spendable, `Saldo dana yang bisa dipakai tidak cukup — tersedia ${formatRupiah(spendable)}.`),
                description: z
                    .string()
                    .trim()
                    .min(1, 'Keterangan penggunaan dana wajib diisi.')
                    .max(255, 'Keterangan maksimal 255 karakter.'),
                bank_account_id: z.string().min(1, 'Rekening sumber dana wajib dipilih.'),
                date: z
                    .date({ message: 'Tanggal wajib diisi.' })
                    .refine((d) => d <= endOfDay(new Date()), 'Tanggal penggunaan dana tidak boleh di masa depan.'),
            }),
        [spendable],
    );

    type FormValues = z.infer<typeof schema>;

    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { amount: '', description: '', bank_account_id: '', date: new Date() },
    });

    useEffect(() => {
        if (open) {
            form.reset({
                amount: '',
                description: '',
                bank_account_id: bankAccounts.length === 1 ? String(bankAccounts[0].id) : '',
                date: new Date(),
            });
        }
    }, [open]);

    function onSubmit(values: FormValues) {
        if (processing) return;

        router.post(
            route('family-fund.recordExpense'),
            {
                amount: Number(values.amount),
                description: values.description,
                bank_account_id: Number(values.bank_account_id),
                date: format(values.date, 'yyyy-MM-dd'),
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
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
                    <DialogTitle>Catat Penggunaan Dana</DialogTitle>
                    <DialogDescription>
                        Saldo yang bisa dipakai {formatRupiah(spendable)} — hanya dari penalti yang sudah dibayar. Dana keluar
                        dari rekening yang dipilih.
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
                                            <Input type="number" min="0" step="0.01" {...field} autoFocus />
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
                                        <FormLabel required>Tanggal</FormLabel>
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
                                    <FormLabel required>Rekening Sumber Dana</FormLabel>
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
                            name="description"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Keterangan</FormLabel>
                                    <FormControl>
                                        <Textarea {...field} rows={2} placeholder="mis. Acara gathering Q3 2026" />
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
                            <Button type="submit" disabled={processing || spendable <= 0}>
                                Simpan
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
