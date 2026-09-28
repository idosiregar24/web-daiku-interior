import { CATEGORY_OPTIONS } from '@/Components/modules/finance/TransactionFormDialog';
import { Button } from '@/Components/ui/button';
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
import { Switch } from '@/Components/ui/switch';
import type { FinanceAllocationConfig } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

// Mirrors StoreFinanceAllocationConfigRequest.
const schema = z.object({
    label: z.string().min(1, 'Label alokasi wajib diisi').max(50, 'Label maksimal 50 karakter'),
    percentage: z
        .string()
        .min(1, 'Persentase wajib diisi')
        .refine((v) => !isNaN(Number(v)) && Number(v) > 0, 'Persentase harus lebih dari 0')
        .refine((v) => Number(v) <= 100, 'Persentase maksimal 100'),
    kategori: z.string().min(1, 'Kategori wajib dipilih'),
    is_active: z.boolean(),
});

type FormValues = z.infer<typeof schema>;

const EMPTY_VALUES: FormValues = { label: '', percentage: '', kategori: '', is_active: true };

interface AllocationFormDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** null = create a new allocation row. */
    allocation: FinanceAllocationConfig | null;
}

export function AllocationFormDialog({ open, onOpenChange, allocation }: AllocationFormDialogProps) {
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: EMPTY_VALUES });

    useEffect(() => {
        if (!open) return;

        form.reset(
            allocation
                ? {
                      label: allocation.label,
                      percentage: String(Number(allocation.percentage)),
                      kategori: allocation.kategori,
                      is_active: allocation.is_active,
                  }
                : EMPTY_VALUES,
        );
    }, [open, allocation, form]);

    function onSubmit(values: FormValues) {
        const options = {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onError: (errors: Record<string, string>) => {
                Object.entries(errors).forEach(([field, message]) => {
                    form.setError(field as keyof FormValues, { message });
                });
            },
        };
        const payload = { ...values, percentage: Number(values.percentage) };

        if (allocation) {
            router.put(route('finance.allocations.update', { allocation: allocation.id }), payload, options);
        } else {
            router.post(route('finance.allocations.store'), payload, options);
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{allocation ? 'Edit Alokasi' : 'Tambah Alokasi'}</DialogTitle>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="label"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Label</FormLabel>
                                    <FormControl>
                                        <Input {...field} placeholder="mis. Gaji" autoFocus />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="percentage"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Persentase dari Nilai Proyek (%)</FormLabel>
                                    <FormControl>
                                        <Input type="number" step="0.01" min="0" max="100" {...field} />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="kategori"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Kategori Pengeluaran</FormLabel>
                                    <Select value={field.value} onValueChange={field.onChange}>
                                        <FormControl>
                                            <SelectTrigger className="w-full">
                                                <SelectValue placeholder="Pilih kategori" />
                                            </SelectTrigger>
                                        </FormControl>
                                        <SelectContent>
                                            {CATEGORY_OPTIONS.PENGELUARAN.map((option) => (
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
                            name="is_active"
                            render={({ field }) => (
                                <FormItem className="flex flex-row items-center justify-between rounded-lg border border-daiku-border p-3">
                                    <FormLabel className="cursor-pointer">Alokasi aktif</FormLabel>
                                    <FormControl>
                                        <Switch checked={field.value} onCheckedChange={field.onChange} />
                                    </FormControl>
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
