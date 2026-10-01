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
import type { BankAccount, Termin } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

type BankAccountOption = Pick<BankAccount, 'id' | 'label'>;

function today() {
    return format(new Date(), 'yyyy-MM-dd');
}

/** "Dibayar Sebagian" is derived, not a DB status — mirrors Termin::isPartiallyPaid(). */
export function isPartiallyPaid(termin: Pick<Termin, 'dp_amount' | 'pelunasan' | 'sisa_piutang'>) {
    return Number(termin.dp_amount) + Number(termin.pelunasan) > 0 && Number(termin.sisa_piutang) > 0;
}

/**
 * Mirrors RecordTerminPaymentRequest, plus the row-level rules of
 * TerminService::recordPayment() (amount <= sisa piutang, no DP after
 * pelunasan) — the server re-checks all of them, including the milestone
 * gate on pelunasan.
 */
function buildPaymentSchema(termin: Termin) {
    const sisa = Number(termin.sisa_piutang);
    const hasPelunasan = Number(termin.pelunasan) > 0;

    return z
        .object({
            type: z.enum(['DP', 'PELUNASAN'], { message: 'Jenis pembayaran wajib dipilih' }),
            amount: z
                .string()
                .min(1, 'Nominal pembayaran wajib diisi')
                .refine((v) => !isNaN(Number(v)), 'Nominal pembayaran harus berupa angka')
                .refine((v) => Number(v) > 0, 'Nominal pembayaran harus lebih dari 0')
                .refine((v) => Number(v) <= sisa, `Nominal pembayaran melebihi sisa piutang (${formatRupiah(sisa)})`),
            bank_account_id: z.string().min(1, 'Rekening penerima wajib dipilih'),
            paid_date: z
                .date({ message: 'Tanggal pembayaran wajib diisi' })
                .refine((d) => format(d, 'yyyy-MM-dd') <= today(), 'Tanggal pembayaran tidak boleh di masa depan'),
        })
        .refine((values) => !(values.type === 'DP' && hasPelunasan), {
            path: ['type'],
            message: 'DP tidak bisa dicatat — termin ini sudah menerima pelunasan',
        });
}

type PaymentFormValues = z.infer<ReturnType<typeof buildPaymentSchema>>;

const PAYMENT_FIELDS: (keyof PaymentFormValues)[] = ['type', 'amount', 'bank_account_id', 'paid_date'];

interface TerminPaymentDialogProps {
    termin: Termin;
    /** Active accounts (TerminController::index() sends them to FINANCE/SUPERADMIN only). */
    bankAccounts: BankAccountOption[];
    onClose: () => void;
}

/**
 * "Catat Pembayaran" — one DP or pelunasan (PRD §4.7 "DP + pelunasan,
 * sisa piutang otomatis terhitung", `finance.termins.recordPayment`).
 * Shared by the termin list and the termin calendar so both follow the
 * same server rules. Mount it only while open (keyed by termin id) so
 * the defaults come from the termin being paid.
 */
export function TerminPaymentDialog({ termin, bankAccounts, onClose }: TerminPaymentDialogProps) {
    const [processing, setProcessing] = useState(false);
    const schema = useMemo(() => buildPaymentSchema(termin), [termin]);
    const form = useForm<PaymentFormValues>({
        resolver: zodResolver(schema),
        defaultValues: {
            type: 'PELUNASAN',
            amount: String(Number(termin.sisa_piutang)),
            bank_account_id: termin.bank_account_id ? String(termin.bank_account_id) : '',
            paid_date: new Date(),
        },
    });

    const dpAllowed = Number(termin.pelunasan) === 0;

    function onSubmit(values: PaymentFormValues) {
        router.post(
            route('finance.termins.recordPayment', { termin: termin.id }),
            {
                type: values.type,
                amount: Number(values.amount),
                bank_account_id: Number(values.bank_account_id),
                paid_date: format(values.paid_date, 'yyyy-MM-dd'),
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (errors: Record<string, string>) => {
                    Object.entries(errors).forEach(([field, message]) => {
                        // `status` (lunas/terkunci) has no input of its own — show it on the amount field.
                        const target = PAYMENT_FIELDS.includes(field as keyof PaymentFormValues)
                            ? (field as keyof PaymentFormValues)
                            : 'amount';
                        form.setError(target, { message });
                    });
                },
                onSuccess: onClose,
            },
        );
    }

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Catat Pembayaran Termin #{termin.termin_number}</DialogTitle>
                    <DialogDescription>
                        {termin.project?.name ?? '—'} · Nominal {formatRupiah(termin.amount)} · Sisa piutang{' '}
                        {formatRupiah(termin.sisa_piutang)}
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="type"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Jenis Pembayaran</FormLabel>
                                    <Select value={field.value} onValueChange={field.onChange}>
                                        <FormControl>
                                            <SelectTrigger className="w-full">
                                                <SelectValue placeholder="Pilih jenis" />
                                            </SelectTrigger>
                                        </FormControl>
                                        <SelectContent>
                                            <SelectItem value="DP" disabled={!dpAllowed}>
                                                DP (uang muka)
                                            </SelectItem>
                                            <SelectItem value="PELUNASAN">Pelunasan</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
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
                            name="paid_date"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Tanggal Pembayaran</FormLabel>
                                    <FormControl>
                                        <DatePicker value={field.value} onChange={field.onChange} />
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
                                Simpan Pembayaran
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
