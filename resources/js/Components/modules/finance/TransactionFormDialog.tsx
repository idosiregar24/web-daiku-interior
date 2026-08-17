import { Button } from '@/Components/ui/button';
import { DatePicker } from '@/Components/shared/DatePicker';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import type { BankAccount, FinanceCategory, FinanceTransactionType, Project } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const TYPE_OPTIONS: { value: FinanceTransactionType; label: string }[] = [
    { value: 'PEMASUKAN', label: 'Pemasukan' },
    { value: 'PENGELUARAN', label: 'Pengeluaran' },
];

// PRD §4.7 "Kategori Transaksi Lengkap" — split by type so the dropdown
// doesn't mix income-only and expense-only categories together.
const CATEGORY_OPTIONS: Record<FinanceTransactionType, { value: FinanceCategory; label: string }[]> = {
    PEMASUKAN: [
        { value: 'DOWN_PAYMENT', label: 'Down Payment' },
        { value: 'TERMIN', label: 'Termin' },
        { value: 'PINDAH_DANA', label: 'Pindah Dana' },
        { value: 'OWNER', label: 'Owner' },
        { value: 'PENALTY_COLLECT', label: 'Penalty Collect' },
        { value: 'LAINNYA', label: 'Lainnya' },
    ],
    PENGELUARAN: [
        { value: 'OPERASIONAL', label: 'Operasional' },
        { value: 'BELI_BAHAN', label: 'Beli Bahan' },
        { value: 'ANGSURAN', label: 'Angsuran' },
        { value: 'GAJI_KARYAWAN', label: 'Gaji Karyawan' },
        { value: 'LEMBUR_BONUS', label: 'Lembur & Bonus' },
        { value: 'LOGISTIK', label: 'Logistik' },
        { value: 'HUTANG_IDEAL', label: 'Hutang Ideal' },
        { value: 'PEGANGAN', label: 'Pegangan' },
        { value: 'JASA_DESAIN', label: 'Jasa Desain' },
        { value: 'VENDOR', label: 'Vendor' },
        { value: 'KONSUMSI', label: 'Konsumsi' },
        { value: 'CONSUMABLE', label: 'Consumable' },
        { value: 'PERALATAN_ASET', label: 'Peralatan/Aset' },
        { value: 'BBM', label: 'BBM' },
        { value: 'PINJAMAN', label: 'Pinjaman' },
        { value: 'LAINNYA', label: 'Lainnya' },
    ],
};

const schema = z.object({
    project_id: z.string().optional(),
    bank_account_id: z.string().min(1, 'Rekening wajib dipilih'),
    type: z.enum(['PEMASUKAN', 'PENGELUARAN']),
    kategori: z.string().min(1, 'Kategori wajib dipilih'),
    amount: z
        .string()
        .min(1, 'Nominal wajib diisi')
        .refine((v) => !isNaN(Number(v)) && Number(v) > 0, 'Nominal tidak valid'),
    description: z.string().min(1, 'Deskripsi wajib diisi'),
    date: z.date({ message: 'Tanggal wajib diisi' }),
});

type FormValues = z.infer<typeof schema>;

const EMPTY_VALUES: FormValues = {
    project_id: '',
    bank_account_id: '',
    type: 'PENGELUARAN',
    kategori: '',
    amount: '',
    description: '',
    date: new Date(),
};

interface TransactionFormDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    projects: Pick<Project, 'id' | 'name'>[];
    bankAccounts: Pick<BankAccount, 'id' | 'label'>[];
}

/** "FinanceTransactionController + model" create form (.claude/plan/sprint-04.md Jonathan Week 8). */
export function TransactionFormDialog({ open, onOpenChange, projects, bankAccounts }: TransactionFormDialogProps) {
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: EMPTY_VALUES,
    });

    useEffect(() => {
        if (open) {
            form.reset(EMPTY_VALUES);
        }
    }, [open]);

    const type = form.watch('type');

    function onSubmit(values: FormValues) {
        const onError = (errors: Record<string, string>) => {
            Object.entries(errors).forEach(([field, message]) => {
                form.setError(field as keyof FormValues, { message });
            });
        };

        router.post(
            route('finance.transactions.store'),
            {
                project_id: values.project_id ? Number(values.project_id) : null,
                bank_account_id: Number(values.bank_account_id),
                type: values.type,
                kategori: values.kategori,
                amount: Number(values.amount),
                description: values.description,
                date: format(values.date, 'yyyy-MM-dd'),
            },
            { onError, onSuccess: () => onOpenChange(false) },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Catat Transaksi</DialogTitle>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                control={form.control}
                                name="type"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Jenis</FormLabel>
                                        <Select
                                            value={field.value}
                                            onValueChange={(value) => {
                                                field.onChange(value);
                                                form.setValue('kategori', '');
                                            }}
                                        >
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                {TYPE_OPTIONS.map((option) => (
                                                    <SelectItem key={option.value} value={option.value}>
                                                        {option.label}
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
                                name="kategori"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Kategori</FormLabel>
                                        <Select value={field.value} onValueChange={field.onChange}>
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue placeholder="Pilih kategori" />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                {CATEGORY_OPTIONS[type].map((option) => (
                                                    <SelectItem key={option.value} value={option.value}>
                                                        {option.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                control={form.control}
                                name="bank_account_id"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Rekening</FormLabel>
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
                                name="project_id"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Proyek (opsional)</FormLabel>
                                        <Select
                                            value={field.value || 'none'}
                                            onValueChange={(value) => field.onChange(value === 'none' ? '' : value)}
                                        >
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue placeholder="Pilih proyek" />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                <SelectItem value="none">—</SelectItem>
                                                {projects.map((project) => (
                                                    <SelectItem key={project.id} value={String(project.id)}>
                                                        {project.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <div className="grid grid-cols-2 gap-4">
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
                        <FormField
                            control={form.control}
                            name="description"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Deskripsi</FormLabel>
                                    <FormControl>
                                        <Input {...field} placeholder="mis. Pembelian material kayu jati" />
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
