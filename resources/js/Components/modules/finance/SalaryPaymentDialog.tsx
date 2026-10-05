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
import { cn } from '@/lib/utils';
import type { BankAccount, PayrollRow } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { endOfDay, format } from 'date-fns';
import { useEffect, useMemo } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

interface SalaryPaymentDialogProps {
    /** null = closed. */
    row: PayrollRow | null;
    /** `YYYY-MM` */
    period: string;
    periodLabel: string;
    bankAccounts: Pick<BankAccount, 'id' | 'label'>[];
    onOpenChange: (open: boolean) => void;
}

const nonNegativeAmount = (label: string) =>
    z
        .string()
        .refine((v) => v === '' || (!isNaN(Number(v)) && Number(v) >= 0), `${label} tidak boleh negatif.`);

/**
 * "Bayar Gaji" — mirrors PaySalaryRequest. The net amount shown here is a
 * preview only: PayrollService recomputes base + tunjangan − potongan
 * server-side and refuses a second salary for the same month.
 */
export function SalaryPaymentDialog({ row, period, periodLabel, bankAccounts, onOpenChange }: SalaryPaymentDialogProps) {
    const base = Number(row?.employee.base_salary ?? 0);

    const schema = useMemo(
        () =>
            z
                .object({
                    allowance: nonNegativeAmount('Tunjangan'),
                    deduction: nonNegativeAmount('Potongan'),
                    paid_at: z
                        .date({ message: 'Tanggal bayar wajib diisi.' })
                        .refine((d) => d <= endOfDay(new Date()), 'Tanggal bayar tidak boleh di masa depan.'),
                    bank_account_id: z.string().min(1, 'Rekening sumber wajib dipilih.'),
                    note: z.string().max(255, 'Catatan maksimal 255 karakter.').optional(),
                })
                .refine((values) => base + Number(values.allowance || 0) - Number(values.deduction || 0) >= 0, {
                    path: ['deduction'],
                    message: 'Potongan tidak boleh melebihi gaji pokok + tunjangan.',
                }),
        [base],
    );

    type FormValues = z.infer<typeof schema>;

    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { allowance: '', deduction: '', paid_at: new Date(), bank_account_id: '', note: '' },
    });

    useEffect(() => {
        if (row) {
            form.reset({
                allowance: '',
                deduction: '',
                paid_at: new Date(),
                bank_account_id: bankAccounts.length === 1 ? String(bankAccounts[0].id) : '',
                note: '',
            });
        }
    }, [row]);

    const allowance = Number(form.watch('allowance') || 0);
    const deduction = Number(form.watch('deduction') || 0);
    const net = base + (isNaN(allowance) ? 0 : allowance) - (isNaN(deduction) ? 0 : deduction);

    function onSubmit(values: FormValues) {
        if (!row) return;

        router.post(
            route('finance.payroll.pay'),
            {
                employee_id: row.employee.id,
                period,
                allowance: Number(values.allowance || 0),
                deduction: Number(values.deduction || 0),
                paid_at: format(values.paid_at, 'yyyy-MM-dd'),
                bank_account_id: Number(values.bank_account_id),
                note: values.note || null,
            },
            {
                preserveScroll: true,
                onError: (errors: Record<string, string>) => {
                    Object.entries(errors).forEach(([field, message]) => {
                        // employee_id / period errors have no field of their own.
                        form.setError(field in form.getValues() ? (field as keyof FormValues) : 'bank_account_id', { message });
                    });
                },
                onSuccess: () => onOpenChange(false),
            },
        );
    }

    return (
        <Dialog open={row !== null} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Bayar Gaji</DialogTitle>
                    <DialogDescription>
                        {row?.employee.name} ({row?.employee.position_name ?? '—'}) — periode {periodLabel}. Tercatat sebagai
                        pengeluaran Gaji Karyawan.
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                control={form.control}
                                name="allowance"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Tunjangan / Bonus (Rp)</FormLabel>
                                        <FormControl>
                                            <Input type="number" min="0" step="0.01" inputMode="decimal" placeholder="0" {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="deduction"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Potongan (Rp)</FormLabel>
                                        <FormControl>
                                            <Input type="number" min="0" step="0.01" inputMode="decimal" placeholder="0" {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>

                        <dl className="space-y-1.5 rounded-lg bg-daiku-gray/70 p-3 text-sm ring-1 ring-border ring-inset">
                            <div className="flex justify-between">
                                <dt className="text-daiku-muted">Gaji pokok</dt>
                                <dd className="tabular-nums">{formatRupiah(base)}</dd>
                            </div>
                            <div className="flex justify-between">
                                <dt className="text-daiku-muted">Tunjangan / bonus</dt>
                                <dd className="tabular-nums">{allowance > 0 ? `+ ${formatRupiah(allowance)}` : '—'}</dd>
                            </div>
                            <div className="flex justify-between">
                                <dt className="text-daiku-muted">Potongan</dt>
                                <dd className="tabular-nums">{deduction > 0 ? `− ${formatRupiah(deduction)}` : '—'}</dd>
                            </div>
                            <div className="flex justify-between border-t border-border pt-1.5 font-semibold">
                                <dt>Gaji bersih ditransfer</dt>
                                <dd className={cn('tabular-nums', net < 0 && 'text-error-ink')}>{formatRupiah(net)}</dd>
                            </div>
                        </dl>

                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                control={form.control}
                                name="bank_account_id"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Rekening Sumber</FormLabel>
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
                                name="paid_at"
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
                        {row?.employee.account_no && (
                            <p className="text-xs text-daiku-muted">
                                Transfer ke {row.employee.bank_name ?? 'rekening'} {row.employee.account_no}.
                            </p>
                        )}
                        <FormField
                            control={form.control}
                            name="note"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Catatan (opsional)</FormLabel>
                                    <FormControl>
                                        <Input {...field} placeholder="mis. Potongan kasbon, bonus target" />
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
                                Bayar Gaji {formatRupiah(Math.max(net, 0))}
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
