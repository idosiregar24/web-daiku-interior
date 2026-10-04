import { MaterialCategorySelect } from '@/Components/modules/logistics/MaterialCategorySelect';
import { SimilarMaterialsPanel } from '@/Components/modules/logistics/SimilarMaterialsPanel';
import { UnitSelect } from '@/Components/shared/UnitSelect';
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
import { Textarea } from '@/Components/ui/textarea';
import { useSimilarMaterials } from '@/hooks/useSimilarMaterials';
import { formatRupiah } from '@/lib/format';
import { parseQty, quantityField } from '@/lib/quantity';
import { cn } from '@/lib/utils';
import type { Material, MaterialCategory, UnitOption } from '@/types';
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
    material_category_id: z.string().min(1, 'Kategori wajib dipilih'),
    base_name: z.string().trim().min(1, 'Nama dasar barang wajib diisi').max(150),
    spec: z.string().max(150).optional(),
    brand: z.string().max(100).optional(),
    unit_id: z.string().min(1, 'Satuan wajib dipilih'),
    cost_price: money('Harga modal'),
    sell_price: money('Harga jual'),
    min_stock: quantityField('Stok minimum', 0),
    similar_reason: z.string().max(500).optional(),
});

type FormValues = z.infer<typeof schema>;

const EMPTY: FormValues = {
    material_category_id: '',
    base_name: '',
    spec: '',
    brand: '',
    unit_id: '',
    cost_price: '',
    sell_price: '',
    min_stock: '0',
    similar_reason: '',
};

interface MaterialFormDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Edit this material; omit to create a new one. */
    material?: Material | null;
    categories: (Pick<MaterialCategory, 'id' | 'name' | 'code_prefix'> & { is_active?: boolean })[];
    /** Active Master Satuan units. */
    units: UnitOption[];
}

/**
 * PRD §4.8 "Material Master" create/edit — Logistics only. Stock is changed
 * via stock in/out, never here. Sprint 11 §5.5: the item is a structured
 * identity (category, base name, spec, brand, unit) — display name and
 * code are generated — and similar items are shown while typing; an exact
 * one is refused, a similar one needs a reason.
 */
export function MaterialFormDialog({ open, onOpenChange, material, categories, units }: MaterialFormDialogProps) {
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: EMPTY });

    useEffect(() => {
        if (!open) {
            return;
        }

        form.reset(
            material
                ? {
                      material_category_id: String(material.material_category_id),
                      base_name: material.base_name ?? material.name,
                      spec: material.spec ?? '',
                      brand: material.brand ?? '',
                      unit_id: String(material.unit_id),
                      cost_price: String(Number(material.cost_price)),
                      sell_price: String(Number(material.sell_price)),
                      min_stock: String(material.min_stock),
                      similar_reason: '',
                  }
                : EMPTY,
        );
    }, [open, material]);

    const [cost, sell, categoryId, baseName, spec, brand, unitId] = form.watch([
        'cost_price',
        'sell_price',
        'material_category_id',
        'base_name',
        'spec',
        'brand',
        'unit_id',
    ]);
    const margin = Number(sell || 0) - Number(cost || 0);
    const preview = [baseName?.trim(), spec?.trim()].filter(Boolean).join(' ') + (brand?.trim() ? ` — ${brand.trim()}` : '');
    const prefix = categories.find((category) => String(category.id) === categoryId)?.code_prefix;
    // Editing only refuses an exact clash; the similar warning is for new items.
    const { items: similar, exactId } = useSimilarMaterials(
        { material_category_id: categoryId, base_name: baseName, spec, brand, unit_id: unitId },
        open,
        material?.id,
    );

    function onSubmit(values: FormValues) {
        const payload = {
            ...values,
            material_category_id: Number(values.material_category_id),
            spec: values.spec || null,
            brand: values.brand || null,
            cost_price: Number(values.cost_price),
            sell_price: Number(values.sell_price),
            unit_id: Number(values.unit_id),
            min_stock: parseQty(values.min_stock),
            similar_reason: values.similar_reason || null,
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

    const text = (name: 'base_name' | 'spec' | 'brand', label: string, placeholder: string) => (
        <FormField
            control={form.control}
            name={name}
            render={({ field }) => (
                <FormItem>
                    <FormLabel>{label}</FormLabel>
                    <FormControl>
                        <Input {...field} placeholder={placeholder} />
                    </FormControl>
                    <FormMessage />
                </FormItem>
            )}
        />
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{material ? `Edit Material ${material.code}` : 'Tambah Material'}</DialogTitle>
                    <DialogDescription>
                        {material
                            ? 'Kode barang tidak berubah.'
                            : 'Nama tampil dan kode dibuat otomatis. Stok awal diisi lewat "Terima Barang" setelah material dibuat.'}
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                control={form.control}
                                name="material_category_id"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Kategori</FormLabel>
                                        <FormControl>
                                            <MaterialCategorySelect value={field.value} onChange={field.onChange} categories={categories} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="unit_id"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Satuan</FormLabel>
                                        <FormControl>
                                            <UnitSelect value={field.value} onChange={field.onChange} units={units} current={material?.unit} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        {text('base_name', 'Nama Dasar', 'mis. Triplek')}
                        <div className="grid grid-cols-2 gap-4">
                            {text('spec', 'Spesifikasi (opsional)', 'mis. 17 mm 122×244')}
                            {text('brand', 'Merek (opsional)', 'mis. Sengon Super')}
                        </div>
                        {preview && (
                            <p className="rounded-md bg-daiku-gray px-3 py-2 text-sm text-daiku-muted">
                                Tampil sebagai: <span className="font-medium text-daiku-dark">{preview}</span>
                                {!material && prefix && ` · kode ${prefix}-xxxx`}
                            </p>
                        )}

                        {!material || exactId ? (
                            <SimilarMaterialsPanel
                                items={material ? [] : similar}
                                exactId={exactId}
                                reasonField={
                                    <FormField
                                        control={form.control}
                                        name="similar_reason"
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormLabel>Tetap buat barang baru — alasan</FormLabel>
                                                <FormControl>
                                                    <Textarea rows={2} {...field} placeholder="mis. Ketebalan berbeda" />
                                                </FormControl>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                }
                            />
                        ) : null}

                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                control={form.control}
                                name="cost_price"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Harga Modal / Gudang (Rp)</FormLabel>
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
                            <span className={cn('font-semibold', margin < 0 ? 'text-error-ink' : 'text-daiku-dark')}>
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
                                        <Input type="number" min="0" step="0.01" inputMode="decimal" {...field} />
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
                            <Button type="submit" disabled={form.formState.isSubmitting || exactId !== null}>
                                Simpan
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
