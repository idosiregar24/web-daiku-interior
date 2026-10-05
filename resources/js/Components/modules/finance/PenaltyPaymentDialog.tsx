import { DatePicker } from '@/Components/shared/DatePicker';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
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
import { formatDate, formatRupiah } from '@/lib/format';
import type { BankAccount, Penalty } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { endOfDay, format } from 'date-fns';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

export type UnpaidPenalty = Pick<Penalty, 'id' | 'staff_id' | 'type' | 'amount' | 'date_occurred'>;

interface PenaltyPaymentDialogProps {
    /** null = closed. */
    staff: { id: number; name: string } | null;
    /** That tukang's unpaid penalties. */
    penalties: UnpaidPenalty[];
    bankAccounts: Pick<BankAccount, 'id' | 'label'>[];
    onOpenChange: (open: boolean) => void;
}

// Mirrors RecordPenaltyPaymentRequest. Ownership/"masih belum dibayar" is
// re-checked server-side under a row lock (PenaltyCollectionService).
const schema = z.object({
    penalty_ids: z
        .array(z.number())
        .min(1, 'Pilih minimal satu penalti yang dibayar.')
        .max(500, 'Maksimal 500 penalti per pembayaran.'),
    bank_account_id: z.string().min(1, 'Rekening penerima wajib dipilih.'),
    date: z
        .date({ message: 'Tanggal bayar wajib diisi.' })
        .refine((d) => d <= endOfDay(new Date()), 'Tanggal bayar tidak boleh di masa depan.'),
    note: z.string().max(255, 'Catatan maksimal 255 karakter.').optional(),
});

type FormValues = z.infer<typeof schema>;

/**
 * "Catat Pembayaran" penalti (Sprint 9 decision #10) — the tukang paid
 * cash/transfer; recorded as one PEMASUKAN Penalty Collect on the chosen
 * account, and the selected penalties become Lunas (spendable in the
 * Dana Family Gathering). Wages are not deducted.
 */
export function PenaltyPaymentDialog({ staff, penalties, bankAccounts, onOpenChange }: PenaltyPaymentDialogProps) {
    const [processing, setProcessing] = useState(false);

    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { penalty_ids: [], bank_account_id: '', date: new Date(), note: '' },
    });

    useEffect(() => {
        if (staff) {
            form.reset({
                penalty_ids: penalties.map((penalty) => penalty.id),
                bank_account_id: bankAccounts.length === 1 ? String(bankAccounts[0].id) : '',
                date: new Date(),
                note: '',
            });
        }
    }, [staff]);

    const selected = form.watch('penalty_ids');
    const total = penalties
        .filter((penalty) => selected.includes(penalty.id))
        .reduce((sum, penalty) => sum + Number(penalty.amount), 0);
    const allSelected = penalties.length > 0 && selected.length === penalties.length;

    function onSubmit(values: FormValues) {
        if (!staff || processing) return;

        router.post(
            route('penalties.recordPayment'),
            {
                staff_id: staff.id,
                penalty_ids: values.penalty_ids,
                bank_account_id: Number(values.bank_account_id),
                date: format(values.date, 'yyyy-MM-dd'),
                note: values.note || null,
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (errors: Record<string, string>) => {
                    Object.entries(errors).forEach(([field, message]) => {
                        // `penalty_ids.3`, `staff_id` → shown under the penalty list.
                        const key = field.startsWith('penalty_ids') || field === 'staff_id' ? 'penalty_ids' : field;
                        form.setError(key as keyof FormValues, { message });
                    });
                },
                onSuccess: () => onOpenChange(false),
            },
        );
    }

    return (
        <Dialog open={staff !== null} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Catat Pembayaran Penalti</DialogTitle>
                    <DialogDescription>
                        {staff?.name} membayar tunai/transfer — tercatat sebagai pemasukan Penalty Collect dan masuk ke
                        saldo Dana Family Gathering. Upah tidak dipotong.
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="penalty_ids"
                            render={({ field }) => (
                                <FormItem>
                                    <div className="flex items-center justify-between">
                                        <FormLabel>Penalti yang dibayar</FormLabel>
                                        <Button
                                            type="button"
                                            variant="link"
                                            size="sm"
                                            className="h-auto p-0"
                                            onClick={() => field.onChange(allSelected ? [] : penalties.map((penalty) => penalty.id))}
                                        >
                                            {allSelected ? 'Kosongkan' : 'Pilih semua'}
                                        </Button>
                                    </div>
                                    <ul className="max-h-56 divide-y divide-border overflow-y-auto rounded-lg border border-border">
                                        {penalties.map((penalty) => {
                                            const checked = field.value.includes(penalty.id);

                                            return (
                                                <li key={penalty.id}>
                                                    <label className="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm hover:bg-daiku-gray/60">
                                                        <Checkbox
                                                            checked={checked}
                                                            onCheckedChange={(value) =>
                                                                field.onChange(
                                                                    value === true
                                                                        ? [...field.value, penalty.id]
                                                                        : field.value.filter((id) => id !== penalty.id),
                                                                )
                                                            }
                                                        />
                                                        <span className="flex-1">
                                                            <span className="block text-daiku-dark">{formatDate(penalty.date_occurred)}</span>
                                                            <span className="block text-xs text-daiku-muted">
                                                                {penalty.type.replace(/_/g, ' ')}
                                                            </span>
                                                        </span>
                                                        <span className="font-medium tabular-nums">{formatRupiah(penalty.amount)}</span>
                                                    </label>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                    <div className="flex items-center justify-between rounded-lg bg-daiku-yellow-light px-3 py-2 text-sm">
                                        <span className="text-daiku-muted">{selected.length} penalti dipilih</span>
                                        <span className="font-semibold tabular-nums">{formatRupiah(total)}</span>
                                    </div>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <div className="grid grid-cols-2 gap-4">
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
                                name="date"
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
                            name="note"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Catatan (opsional)</FormLabel>
                                    <FormControl>
                                        <Input {...field} placeholder="mis. Dibayar tunai di kantor" />
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
                            <Button type="submit" disabled={processing || selected.length === 0}>
                                Catat Pembayaran Penalti {selected.length > 0 && `(${formatRupiah(total)})`}
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
