import { DatePicker } from '@/Components/shared/DatePicker';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import type { Asset, AssetCondition } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

export const CONDITION_LABELS: Record<AssetCondition, string> = {
    GOOD: 'Baik',
    FAIR: 'Cukup',
    DAMAGED: 'Rusak',
};

// Mirrors App\Http\Requests\Logistics\StoreAssetRequest.
const schema = z.object({
    name: z.string().min(1, 'Nama aset wajib diisi').max(150),
    category: z.string().min(1, 'Kategori wajib diisi').max(50),
    purchase_date: z.date({ message: 'Tanggal pembelian wajib diisi' }).max(new Date(), 'Tidak boleh di masa depan'),
    value: z
        .string()
        .min(1, 'Nilai aset wajib diisi')
        .refine((v) => !isNaN(Number(v)) && Number(v) >= 0, 'Nilai tidak valid'),
    condition: z.enum(['GOOD', 'FAIR', 'DAMAGED']),
    location: z.string().max(100).optional(),
    notes: z.string().max(2000).optional(),
});

type FormValues = z.infer<typeof schema>;

const EMPTY: FormValues = {
    name: '',
    category: '',
    purchase_date: new Date(),
    value: '',
    condition: 'GOOD',
    location: '',
    notes: '',
};

interface AssetFormDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    asset?: Asset | null;
    categories: string[];
}

/** PRD §4.8 "Aset Inventaris" create/edit — Logistics only. */
export function AssetFormDialog({ open, onOpenChange, asset, categories }: AssetFormDialogProps) {
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: EMPTY });

    useEffect(() => {
        if (!open) {
            return;
        }

        form.reset(
            asset
                ? {
                      name: asset.name,
                      category: asset.category ?? '',
                      purchase_date: asset.purchase_date ? new Date(asset.purchase_date) : new Date(),
                      value: asset.value ? String(Number(asset.value)) : '',
                      condition: asset.condition,
                      location: asset.location ?? '',
                      notes: asset.notes ?? '',
                  }
                : { ...EMPTY, purchase_date: new Date() },
        );
    }, [open, asset]);

    function onSubmit(values: FormValues) {
        const payload = {
            ...values,
            purchase_date: format(values.purchase_date, 'yyyy-MM-dd'),
            value: Number(values.value),
            location: values.location || null,
            notes: values.notes || null,
        };
        const options = {
            onError: (errors: Record<string, string>) =>
                Object.entries(errors).forEach(([field, message]) => form.setError(field as keyof FormValues, { message })),
            onSuccess: () => onOpenChange(false),
        };

        if (asset) {
            router.put(route('logistics.assets.update', { asset: asset.id }), payload, options);
        } else {
            router.post(route('logistics.assets.store'), payload, options);
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{asset ? 'Edit Aset' : 'Tambah Aset'}</DialogTitle>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="name"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Nama Aset</FormLabel>
                                    <FormControl>
                                        <Input {...field} placeholder="mis. Mobil Pickup L300" />
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
                                            <Input {...field} list="asset-categories" placeholder="Alat, Mesin, Kendaraan…" />
                                        </FormControl>
                                        <datalist id="asset-categories">
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
                                name="condition"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Kondisi</FormLabel>
                                        <Select value={field.value} onValueChange={field.onChange}>
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                {(Object.keys(CONDITION_LABELS) as AssetCondition[]).map((condition) => (
                                                    <SelectItem key={condition} value={condition}>
                                                        {CONDITION_LABELS[condition]}
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
                                name="purchase_date"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Tanggal Beli</FormLabel>
                                        <FormControl>
                                            <DatePicker value={field.value} onChange={field.onChange} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="value"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Nilai (Rp)</FormLabel>
                                        <FormControl>
                                            <Input type="number" min="0" step="any" inputMode="decimal" {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <FormField
                            control={form.control}
                            name="location"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Lokasi (opsional)</FormLabel>
                                    <FormControl>
                                        <Input {...field} placeholder="mis. Gudang Workshop" />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="notes"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Catatan (opsional)</FormLabel>
                                    <FormControl>
                                        <Textarea {...field} rows={3} />
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
