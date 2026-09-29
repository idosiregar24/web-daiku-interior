import { PageHeader } from '@/Components/shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import {
    Form,
    FormControl,
    FormDescription,
    FormField,
    FormItem,
    FormLabel,
    FormMessage,
} from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import type { BankAccount, User } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, Link, router } from '@inertiajs/react';
import { HandCoins } from 'lucide-react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const positiveAmount = (label: string) =>
    z
        .string()
        .min(1, `${label} wajib diisi`)
        .refine((v) => !isNaN(Number(v)) && Number(v) > 0, `${label} harus lebih dari 0`);

// Mirrors StoreStaffLoanRequest.
const schema = z
    .object({
        staff_id: z.string().min(1, 'Tukang wajib dipilih'),
        amount: positiveAmount('Nominal pinjaman'),
        installment_amount: positiveAmount('Nominal cicilan'),
        bank_account_id: z.string().min(1, 'Rekening bank wajib dipilih'),
        description: z.string().max(1000, 'Keterangan maksimal 1000 karakter').optional(),
    })
    .refine((values) => Number(values.installment_amount) <= Number(values.amount), {
        path: ['installment_amount'],
        message: 'Nominal cicilan tidak boleh melebihi nominal pinjaman',
    });

type FormValues = z.infer<typeof schema>;

interface StaffLoanCreateProps {
    staff: Pick<User, 'id' | 'name'>[];
    bankAccounts: Pick<BankAccount, 'id' | 'label'>[];
}

/** PRD §4.7 "Pinjaman Tukang" — FINANCE only (route `role:FINANCE`). */
export default function StaffLoanCreate({ staff, bankAccounts }: StaffLoanCreateProps) {
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { staff_id: '', amount: '', installment_amount: '', bank_account_id: '', description: '' },
    });

    function onSubmit(values: FormValues) {
        router.post(
            route('finance.staffLoans.store'),
            {
                staff_id: Number(values.staff_id),
                amount: Number(values.amount),
                installment_amount: Number(values.installment_amount),
                bank_account_id: Number(values.bank_account_id),
                description: values.description || null,
            },
            {
                onError: (errors) => {
                    Object.entries(errors).forEach(([field, message]) => {
                        form.setError(field as keyof FormValues, { message: message as string });
                    });
                },
            },
        );
    }

    return (
        <AppLayout
            breadcrumbs={[{ label: 'Catat Pinjaman' }]}
        >
            <Head title="Catat Pinjaman Tukang" />

            <PageHeader
                title="Catat Pinjaman Tukang"
                icon={HandCoins}
                description="Dana pinjaman dicatat sebagai pengeluaran (PINJAMAN) dari rekening yang dipilih."
            />

            <Card className="max-w-lg">
                <CardContent className="px-5 py-2 sm:px-6">
                    <Form {...form}>
                        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                            <FormField
                                control={form.control}
                                name="staff_id"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Tukang</FormLabel>
                                        <Select value={field.value} onValueChange={field.onChange}>
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue placeholder="Pilih tukang" />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                {staff.map((member) => (
                                                    <SelectItem key={member.id} value={String(member.id)}>
                                                        {member.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <div className="grid grid-cols-2 gap-4">
                                <FormField
                                    control={form.control}
                                    name="amount"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Nominal Pinjaman (Rp)</FormLabel>
                                            <FormControl>
                                                <Input type="number" min="0" step="0.01" {...field} />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="installment_amount"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Cicilan per Upah (Rp)</FormLabel>
                                            <FormControl>
                                                <Input type="number" min="0" step="0.01" {...field} />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                            </div>
                            <p className="-mt-2 text-xs text-daiku-muted">
                                Cicilan otomatis dipotong dari setiap pembayaran upah task tukang ini sampai lunas.
                            </p>
                            <FormField
                                control={form.control}
                                name="bank_account_id"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Rekening Sumber Dana</FormLabel>
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
                                        <FormLabel>Keterangan (opsional)</FormLabel>
                                        <FormControl>
                                            <Textarea {...field} rows={3} placeholder="mis. Kasbon biaya berobat" />
                                        </FormControl>
                                        <FormDescription>Ikut tercatat di deskripsi transaksi pengeluaran.</FormDescription>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <div className="flex justify-end gap-2">
                                <Button type="button" variant="outline" asChild>
                                    <Link href={route('finance.staffLoans.index')}>Batal</Link>
                                </Button>
                                <Button type="submit" disabled={form.formState.isSubmitting}>
                                    Simpan
                                </Button>
                            </div>
                        </form>
                    </Form>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
