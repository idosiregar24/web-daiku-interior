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
import { formatQty, formatRupiah } from '@/lib/format';
import { parseQty, quantityField } from '@/lib/quantity';
import type { Material, Project, StockMovementType } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

// Mirrors StockMovementRequest. The two rules that depend on context
// (project required for OUT, qty ≤ current stock) run in onSubmit.
const schema = z.object({
    qty: quantityField('Jumlah'),
    movement_date: z.date({ message: 'Tanggal wajib diisi' }),
    project_id: z.string().optional(),
    note: z.string().max(255).optional(),
});

type FormValues = z.infer<typeof schema>;

const EMPTY: FormValues = { qty: '', movement_date: new Date(), project_id: '', note: '' };

interface StockMovementDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    material: Material | null;
    type: StockMovementType;
    projects: Pick<Project, 'id' | 'name'>[];
}

/**
 * PRD §4.8 "Manajemen Stok" — penerimaan (IN) or pemakaian per proyek
 * (OUT). Mirrors StockMovementRequest; the stock ceiling is checked here
 * for fast feedback and again server-side under a row lock
 * (StockService), which is the check that actually counts.
 */
export function StockMovementDialog({ open, onOpenChange, material, type, projects }: StockMovementDialogProps) {
    const isOut = type === 'OUT';
    const available = material?.stock ?? 0;

    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: EMPTY });

    useEffect(() => {
        if (open) {
            form.reset({ ...EMPTY, movement_date: new Date() });
        }
    }, [open, type, material?.id]);

    if (!material) {
        return null;
    }

    const unitCode = material.unit?.code ?? '';

    function onSubmit(values: FormValues) {
        if (!material) {
            return;
        }

        if (isOut && !values.project_id) {
            form.setError('project_id', { message: 'Barang keluar wajib dikaitkan ke proyek' });

            return;
        }

        if (isOut && parseQty(values.qty) > available) {
            form.setError('qty', { message: `Melebihi stok tersedia (${formatQty(available)} ${unitCode})` });

            return;
        }

        router.post(
            route(isOut ? 'logistics.materials.stockOut' : 'logistics.materials.stockIn', { material: material.id }),
            {
                qty: parseQty(values.qty),
                movement_date: format(values.movement_date, 'yyyy-MM-dd'),
                note: values.note || null,
                ...(isOut ? { project_id: Number(values.project_id) } : {}),
            },
            {
                preserveScroll: true,
                onError: (errors) =>
                    Object.entries(errors).forEach(([field, message]) =>
                        form.setError(field as keyof FormValues, { message }),
                    ),
                onSuccess: () => onOpenChange(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{isOut ? 'Keluarkan ke Proyek' : 'Terima Barang'}</DialogTitle>
                    <DialogDescription>
                        {material.name} — stok saat ini {formatQty(available)} {unitCode}
                        {isOut && ` · dibebankan ke proyek ${formatRupiah(material.cost_price)}/${unitCode} (harga gudang)`}
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        {isOut && (
                            <FormField
                                control={form.control}
                                name="project_id"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Proyek</FormLabel>
                                        <Select value={field.value} onValueChange={field.onChange}>
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue placeholder="Pilih proyek" />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
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
                        )}
                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                control={form.control}
                                name="qty"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Jumlah ({unitCode})</FormLabel>
                                        <FormControl>
                                            <Input
                                                type="number"
                                                min="0.01"
                                                max={isOut ? available : undefined}
                                                step="0.01"
                                                inputMode="decimal"
                                                {...field}
                                            />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="movement_date"
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
                            name="note"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Catatan (opsional)</FormLabel>
                                    <FormControl>
                                        <Input
                                            {...field}
                                            placeholder={isOut ? 'mis. Kabinet dapur lantai 1' : 'mis. PO-0231, Toko Sumber Kayu'}
                                        />
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
                                {isOut ? 'Keluarkan Barang' : 'Catat Penerimaan'}
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
