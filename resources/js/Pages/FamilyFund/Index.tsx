import { PageHeader } from '@/Components/shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import {
    Form,
    FormControl,
    FormField,
    FormItem,
    FormLabel,
    FormMessage,
} from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import type { FamilyGatheringFund, PaginatedData, PageProps } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

interface FamilyFundIndexProps {
    entries: PaginatedData<FamilyGatheringFund>;
    balance: number;
    totalIncome: number;
    totalExpense: number;
}

const schema = z.object({
    amount: z
        .string()
        .min(1, 'Nominal wajib diisi')
        .refine((v) => !isNaN(Number(v)) && Number(v) > 0, 'Nominal tidak valid'),
    description: z.string().min(1, 'Keterangan wajib diisi'),
});

type FormValues = z.infer<typeof schema>;

function formatRupiah(value: string | number) {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(Number(value));
}

function RecordExpenseDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { amount: '', description: '' },
    });

    function onSubmit(values: FormValues) {
        const onError = (errors: Record<string, string>) => {
            Object.entries(errors).forEach(([field, message]) => {
                form.setError(field as keyof FormValues, { message });
            });
        };

        router.post(
            route('family-fund.recordExpense'),
            { amount: Number(values.amount), description: values.description },
            { onError, onSuccess: () => { onOpenChange(false); form.reset(); } },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Catat Penggunaan Dana</DialogTitle>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="amount"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Nominal (Rp)</FormLabel>
                                    <FormControl>
                                        <Input type="number" min="0" step="0.01" {...field} autoFocus />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="description"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Keterangan</FormLabel>
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

/**
 * "FamilyGatheringFund page Finance: total dana + riwayat"
 * (.claude/plan/sprint-03.md Week 6). PRD §4.7: "Dana penalti tidak bisa
 * dicairkan tanpa record Penggunaan Dana" — the "Catat Penggunaan Dana"
 * action is that record.
 */
export default function FamilyFundIndex({ entries, balance, totalIncome, totalExpense }: FamilyFundIndexProps) {
    const { auth } = usePage<PageProps>().props;
    const canRecordExpense = auth.user?.role === 'FINANCE' || auth.user?.role === 'SUPERADMIN';

    const [dialogOpen, setDialogOpen] = useState(false);

    return (
        <AppLayout breadcrumbs={[{ label: 'Dana Family Gathering' }]}>
            <Head title="Dana Family Gathering" />

            <PageHeader
                title="Dana Family Gathering"
                description="Akumulasi penalti form harian (PRD §4.7) — Rp 50.000 per pelanggaran."
                actions={
                    canRecordExpense && (
                        <Button onClick={() => setDialogOpen(true)}>
                            <Plus className="size-4" />
                            Catat Penggunaan Dana
                        </Button>
                    )
                }
            />

            <div className="mb-4 grid grid-cols-3 gap-4">
                <Card>
                    <CardContent className="pt-6">
                        <p className="text-xs text-daiku-muted">Saldo Saat Ini</p>
                        <p className="mt-1 text-2xl font-semibold text-daiku-dark">{formatRupiah(balance)}</p>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="pt-6">
                        <p className="text-xs text-daiku-muted">Total Pemasukan</p>
                        <p className="mt-1 text-2xl font-semibold text-success">{formatRupiah(totalIncome)}</p>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="pt-6">
                        <p className="text-xs text-daiku-muted">Total Penggunaan</p>
                        <p className="mt-1 text-2xl font-semibold text-error">{formatRupiah(totalExpense)}</p>
                    </CardContent>
                </Card>
            </div>

            <div className="overflow-hidden rounded-lg border border-daiku-border">
                <table className="w-full text-sm">
                    <thead className="bg-daiku-yellow-light">
                        <tr>
                            <th className="p-2 text-left font-medium">Tanggal</th>
                            <th className="p-2 text-left font-medium">Jenis</th>
                            <th className="p-2 text-left font-medium">Keterangan</th>
                            <th className="p-2 text-left font-medium">Dicatat Oleh</th>
                            <th className="p-2 text-right font-medium">Nominal</th>
                        </tr>
                    </thead>
                    <tbody>
                        {entries.data.length === 0 ? (
                            <tr>
                                <td colSpan={5} className="p-6 text-center text-daiku-muted">
                                    Belum ada riwayat.
                                </td>
                            </tr>
                        ) : (
                            entries.data.map((entry) => (
                                <tr key={entry.id} className="border-t border-daiku-border">
                                    <td className="p-2 text-daiku-muted">
                                        {new Date(entry.created_at).toLocaleDateString('id-ID')}
                                    </td>
                                    <td className="p-2">
                                        <span className={entry.type === 'INCOME' ? 'text-success' : 'text-error'}>
                                            {entry.type === 'INCOME' ? 'Pemasukan' : 'Penggunaan'}
                                        </span>
                                    </td>
                                    <td className="p-2">{entry.description ?? '—'}</td>
                                    <td className="p-2 text-daiku-muted">{entry.recorder?.name ?? '—'}</td>
                                    <td className={`p-2 text-right font-medium ${entry.type === 'INCOME' ? 'text-success' : 'text-error'}`}>
                                        {entry.type === 'INCOME' ? '+' : '-'}
                                        {formatRupiah(entry.amount)}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            {canRecordExpense && <RecordExpenseDialog open={dialogOpen} onOpenChange={setDialogOpen} />}
        </AppLayout>
    );
}
