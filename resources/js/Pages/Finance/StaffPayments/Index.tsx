import { formatRupiah } from '@/lib/format';
import { Pagination } from '@/Components/shared/Pagination';
import { PageHeader } from '@/Components/shared/PageHeader';
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
        <AppLayout breadcrumbs={[{ label: 'Finance', routeName: 'finance.dashboard' }, { label: 'Upah Tukang' }]}>
            <Head title="Upah Tukang" />

            <PageHeader
                title="Upah Tukang"
                description="Task DONE dengan rate per task yang belum dibayarkan. Cicilan pinjaman tukang dipotong otomatis."
            />

            <div className="overflow-x-auto rounded-lg border border-daiku-border">
                <table className="w-full text-sm">
                    <thead className="bg-daiku-yellow-light">
                        <tr>
                            <th className="p-2 text-left font-medium">Task</th>
                            <th className="p-2 text-left font-medium">Tukang</th>
                            <th className="p-2 text-left font-medium">Proyek</th>
                            <th className="p-2 text-left font-medium">Selesai</th>
                            <th className="p-2 text-right font-medium">Upah</th>
                            <th className="p-2 text-right font-medium">Potongan Pinjaman</th>
                            <th className="p-2 text-right font-medium">Dibayar Bersih</th>
                            <th className="w-24 p-2" />
                        </tr>
                    </thead>
                    <tbody>
                        {tasks.data.length === 0 ? (
                            <tr>
                                <td colSpan={8} className="p-6 text-center text-daiku-muted">
                                    Tidak ada upah tukang yang perlu dibayar.
                                </td>
                            </tr>
                        ) : (
                            tasks.data.map((task) => (
                                <tr key={task.id} className="border-t border-daiku-border">
                                    <td className="p-2 font-medium">{task.title}</td>
                                    <td className="p-2 text-daiku-muted">{task.assignee?.name ?? '—'}</td>
                                    <td className="p-2 text-daiku-muted">{task.project?.name ?? '—'}</td>
                                    <td className="p-2 text-daiku-muted">
                                        {task.completed_at ? new Date(task.completed_at).toLocaleDateString('id-ID') : '—'}
                                    </td>
                                    <td className="p-2 text-right">{formatRupiah(task.payment_preview.wage)}</td>
                                    <td className="p-2 text-right text-daiku-muted">
                                        {task.payment_preview.deduction > 0
                                            ? `− ${formatRupiah(task.payment_preview.deduction)}`
                                            : '—'}
                                    </td>
                                    <td className="p-2 text-right font-medium text-daiku-dark">
                                        {formatRupiah(task.payment_preview.net)}
                                    </td>
                                    <td className="p-2 text-right">
                                        {canPay && (
                                            <Button variant="outline" size="sm" onClick={() => setPaying(task)}>
                                                Bayar
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
            <Pagination paginator={tasks} />

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
                    <dl className="space-y-1 rounded-lg border border-daiku-border p-3 text-sm">
                        <div className="flex justify-between">
                            <dt className="text-daiku-muted">Upah</dt>
                            <dd>{formatRupiah(preview.wage)}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-daiku-muted">Potongan cicilan pinjaman</dt>
                            <dd>{preview.deduction > 0 ? `− ${formatRupiah(preview.deduction)}` : '—'}</dd>
                        </div>
                        <div className="flex justify-between border-t border-daiku-border pt-1 font-medium">
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
