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
import { formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Material } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const money = (label: string) =>
    z
        .string()
        .min(1, `${label} wajib diisi`)
        .refine((v) => !isNaN(Number(v)) && Number(v) >= 0, `${label} tidak valid`);

// Mirrors App\Http\Requests\Logistics\StoreMaterialRequest.
const schema = z.object({
    name: z.string().min(1, 'Nama material wajib diisi').max(150),
    unit: z.string().min(1, 'Satuan wajib diisi').max(20),
    category: z.string().max(50).optional(),
    cost_price: money('Harga modal'),
    sell_price: money('Harga jual'),
    min_stock: z
        .string()
        .min(1, 'Stok minimum wajib diisi')
        .refine((v) => Number.isInteger(Number(v)) && Number(v) >= 0, 'Stok minimum harus bilangan bulat'),
});

type FormValues = z.infer<typeof schema>;

const EMPTY: FormValues = { name: '', unit: '', category: '', cost_price: '', sell_price: '', min_stock: '0' };

interface MaterialFormDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Edit this material; omit to create a new one. */
    material?: Material | null;
    categories: string[];
}

/** PRD §4.8 "Material Master" create/edit — Logistics only. Stock is changed via stock in/out, never here. */
export function MaterialFormDialog({ open, onOpenChange, material, categories }: MaterialFormDialogProps) {
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: EMPTY });

    useEffect(() => {
        if (!open) {
            return;
        }

        form.reset(
            material
                ? {
                      name: material.name,
                      unit: material.unit,
                      category: material.category ?? '',
                      cost_price: String(Number(material.cost_price)),
                      sell_price: String(Number(material.sell_price)),
                      min_stock: String(material.min_stock),
                  }
                : EMPTY,
        );
    }, [open, material]);

    const [cost, sell] = form.watch(['cost_price', 'sell_price']);
    const margin = Number(sell || 0) - Number(cost || 0);

    function onSubmit(values: FormValues) {
        const payload = {
            ...values,
            category: values.category || null,
            cost_price: Number(values.cost_price),
            sell_price: Number(values.sell_price),
            min_stock: Number(values.min_stock),
        };
        const options = {
            onError: (errors: Record<string, string>) =>
                Object.entries(errors).forEach(([field, message]) => form.setError(field as keyof FormValues, { message })),
            onSuccess: () => onOpenChange(false),
        };

        if (material) {
            router.put(route('logistics.materials.update', { material: material.id }), payload, options);
        } else {
            router.post(route('logistics.materials.store'), payload, options);
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{material ? 'Edit Material' : 'Tambah Material'}</DialogTitle>
                    {!material && (
                        <DialogDescription>Stok awal diisi lewat "Terima Barang" setelah material dibuat.</DialogDescription>
                    )}
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="name"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Nama Material</FormLabel>
                                    <FormControl>
                                        <Input {...field} placeholder="mis. Plywood 18mm" />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                control={form.control}
                                name="category"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Kategori</FormLabel>
                                        <FormControl>
                                            <Input {...field} list="material-categories" placeholder="mis. Kayu" />
                                        </FormControl>
                                        <datalist id="material-categories">
                                            {categories.map((category) => (
                                                <option key={category} value={category} />
                                            ))}
                                        </datalist>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="unit"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Satuan</FormLabel>
                                        <FormControl>
                                            <Input {...field} placeholder="lembar, m, kg…" />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                control={form.control}
                                name="cost_price"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Harga Modal (Rp)</FormLabel>
                                        <FormControl>
                                            <Input type="number" min="0" step="any" inputMode="decimal" {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="sell_price"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Harga Jual (Rp)</FormLabel>
                                        <FormControl>
                                            <Input type="number" min="0" step="any" inputMode="decimal" {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <p className="rounded-md bg-daiku-gray px-3 py-2 text-sm text-daiku-muted">
                            Margin per satuan:{' '}
                            <span className={cn('font-semibold', margin < 0 ? 'text-error' : 'text-daiku-dark')}>
                                {formatRupiah(margin)}
                            </span>
                            {margin < 0 && ' — harga jual di bawah modal'}
                        </p>
                        <FormField
                            control={form.control}
                            name="min_stock"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Stok Minimum (batas peringatan)</FormLabel>
                                    <FormControl>
                                        <Input type="number" min="0" step="1" inputMode="numeric" {...field} />
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
