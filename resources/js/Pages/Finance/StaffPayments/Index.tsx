import { formatRupiah } from '@/lib/format';
import { ModuleTabs } from '@/Components/shared/ModuleTabs';
import { PageHeader } from '@/Components/shared/PageHeader';
import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { EmptyState } from '@/Components/shared/EmptyState';
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
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import type { BankAccount, PageProps, PaginatedData, Task } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router, usePage } from '@inertiajs/react';
import { Banknote } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

/** StaffPaymentService::preview() — gross wage, loan installment that will be deducted, net transfer. */
interface PaymentPreview {
    wage: number;
    deduction: number;
    net: number;
}

type PayableTask = Task & { payment_preview: PaymentPreview };
type BankOption = Pick<BankAccount, 'id' | 'label'>;

interface StaffPaymentsIndexProps {
    tasks: PaginatedData<PayableTask>;
    bankAccounts: BankOption[];
}

/**
 * "Pencatatan upah tukang per task selesai + staff payment list"
 * (.claude/plan/sprint-04.md Jonathan Week 8) — every DONE task with a
 * `rate_per_task` that hasn't been paid yet. PRD §4.7: outstanding staff
 * loan installments are deducted automatically on payment.
 */
export default function StaffPaymentsIndex({ tasks, bankAccounts }: StaffPaymentsIndexProps) {
    const { auth } = usePage<PageProps>().props;
    const canPay = auth.user.role === 'FINANCE' || auth.user.role === 'SUPERADMIN';
    const [paying, setPaying] = useState<PayableTask | null>(null);

    return (
        <AppLayout>
            <Head title="Upah Tukang" />

            <PageHeader
                title="Upah Tukang"
                icon={Banknote}
                description="Task DONE dengan rate per task yang belum dibayarkan. Cicilan pinjaman tukang dipotong otomatis."
            />

            <ModuleTabs />

            <TableCard
                pagination={tasks}
            >
                <table className="w-full text-sm">
                    <thead className={TABLE_HEAD_CLASS}>
                        <tr>
                            <th className="px-4 py-2.5 text-left font-semibold">Task</th>
                            <th className="px-4 py-2.5 text-left font-semibold">Tukang</th>
                            <th className="px-4 py-2.5 text-left font-semibold">Proyek</th>
                            <th className="px-4 py-2.5 text-left font-semibold">Selesai</th>
                            <th className="px-4 py-2.5 text-right font-semibold">Upah</th>
                            <th className="px-4 py-2.5 text-right font-semibold">Potongan Pinjaman</th>
                            <th className="px-4 py-2.5 text-right font-semibold">Dibayar Bersih</th>
                            <th className="w-24 px-4 py-2.5" />
                        </tr>
                    </thead>
                    <tbody>
                        {tasks.data.length === 0 ? (
                            <tr>
                                <td colSpan={8} className="p-0">
                                    <EmptyState title="Tidak ada upah tukang yang perlu dibayar." />
                                </td>
                            </tr>
                        ) : (
                            tasks.data.map((task) => (
                                <tr key={task.id} className="border-t border-border transition-colors hover:bg-daiku-gray/60">
                                    <td className="px-4 py-3 font-medium">{task.title}</td>
                                    <td className="px-4 py-3 text-daiku-muted">{task.assignee?.name ?? '—'}</td>
                                    <td className="px-4 py-3 text-daiku-muted">{task.project?.name ?? '—'}</td>
                                    <td className="px-4 py-3 text-daiku-muted">
                                        {task.completed_at ? new Date(task.completed_at).toLocaleDateString('id-ID') : '—'}
                                    </td>
                                    <td className="px-4 py-3 text-right">{formatRupiah(task.payment_preview.wage)}</td>
                                    <td className="px-4 py-3 text-right text-daiku-muted">
                                        {task.payment_preview.deduction > 0
                                            ? `− ${formatRupiah(task.payment_preview.deduction)}`
                                            : '—'}
                                    </td>
                                    <td className="px-4 py-3 text-right font-medium text-daiku-dark">
                                        {formatRupiah(task.payment_preview.net)}
                                    </td>
                                    <td className="px-4 py-3 text-right">
                                        {canPay && (
                                            <Button variant="outline" size="sm" onClick={() => setPaying(task)}>
                                                Bayar Upah
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </TableCard>

            {canPay && (
                <PayDialog task={paying} bankAccounts={bankAccounts} onOpenChange={(open) => !open && setPaying(null)} />
            )}
        </AppLayout>
    );
}

// Mirrors PayStaffRequest.
const schema = z.object({
    bank_account_id: z.string().min(1, 'Rekening sumber wajib dipilih'),
});

type FormValues = z.infer<typeof schema>;

interface PayDialogProps {
    task: PayableTask | null;
    bankAccounts: BankOption[];
    onOpenChange: (open: boolean) => void;
}

function PayDialog({ task, bankAccounts, onOpenChange }: PayDialogProps) {
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { bank_account_id: '' } });

    useEffect(() => {
        if (task) {
            form.reset({ bank_account_id: '' });
        }
    }, [task, form]);

    function onSubmit(values: FormValues) {
        if (!task) return;

        router.post(
            route('finance.staffPayments.pay', { task: task.id }),
            { bank_account_id: Number(values.bank_account_id) },
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onError: (errors) => {
                    Object.entries(errors).forEach(([field, message]) => {
                        form.setError(field === 'status' ? 'bank_account_id' : (field as keyof FormValues), {
                            message: message as string,
                        });
                    });
                },
            },
        );
    }

    const preview = task?.payment_preview;

    return (
        <Dialog open={task !== null} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Bayar Upah Tukang</DialogTitle>
                    <DialogDescription>
                        {task ? `"${task.title}" — ${task.assignee?.name ?? 'tukang'}` : ''}
                    </DialogDescription>
                </DialogHeader>

                {preview && (
                    <dl className="space-y-1.5 rounded-lg bg-daiku-gray/70 p-3 text-sm ring-1 ring-border ring-inset">
                        <div className="flex justify-between">
                            <dt className="text-daiku-muted">Upah</dt>
                            <dd>{formatRupiah(preview.wage)}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-daiku-muted">Potongan cicilan pinjaman</dt>
                            <dd>{preview.deduction > 0 ? `− ${formatRupiah(preview.deduction)}` : '—'}</dd>
                        </div>
                        <div className="flex justify-between border-t border-border pt-1.5 font-semibold">
                            <dt>Ditransfer ke tukang</dt>
                            <dd>{formatRupiah(preview.net)}</dd>
                        </div>
                    </dl>
                )}

                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
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
            </DialogContent>
        </Dialog>
    );
}
