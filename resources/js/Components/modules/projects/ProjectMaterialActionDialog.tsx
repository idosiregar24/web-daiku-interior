import { DatePicker } from '@/Components/shared/DatePicker';
import { VendorSelect } from '@/Components/shared/VendorSelect';
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
import { Textarea } from '@/Components/ui/textarea';
import { formatQty, formatRupiah } from '@/lib/format';
import { parseQty, quantityField } from '@/lib/quantity';
import { MaterialCategorySelect } from '@/Components/modules/logistics/MaterialCategorySelect';
import { SimilarMaterialsPanel } from '@/Components/modules/logistics/SimilarMaterialsPanel';
import { useSimilarMaterials } from '@/hooks/useSimilarMaterials';
import type { Material, MaterialCategory, ProjectMaterial, VendorOption } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

/** One step of a line's lifecycle (Sprint 11 §5.2) — each maps to a `project-materials.*` route. */
export type MaterialAction = 'issue' | 'purchase' | 'usage' | 'return' | 'waste' | 'handOver';

const ACTION_COPY: Record<MaterialAction, { title: string; submit: string; description: string }> = {
    issue: {
        title: 'Keluarkan dari Gudang',
        submit: 'Keluarkan',
        description: 'Stok gudang berkurang dan proyek dibebankan harga gudang saat ini.',
    },
    purchase: {
        title: 'Catat Pembelian',
        submit: 'Catat Pembelian',
        description: 'Barang yang dibeli khusus untuk proyek ini — dibebankan harga beli aktual.',
    },
    usage: { title: 'Catat Pemakaian', submit: 'Catat Pemakaian', description: 'Jumlah yang benar-benar terpakai di proyek.' },
    return: {
        title: 'Retur ke Gudang',
        submit: 'Retur',
        description: 'Sisa masuk ke stok Logistik dan bisa dipakai proyek lain. Biaya proyek ini tidak berkurang.',
    },
    waste: { title: 'Catat Susut', submit: 'Catat Susut', description: 'Sisa yang rusak/terbuang — tidak masuk stok.' },
    handOver: {
        title: 'Serahkan ke Klien',
        submit: 'Catat Penyerahan',
        description: 'Sisa yang diserahkan ke klien (mis. potongan kaca ukuran klien) — tidak masuk stok.',
    },
};

// Mirrors App\Http\Requests\Logistics\ProjectMaterialActionRequest; which
// extra fields are required depends on the action (checked in onSubmit).
const schema = z.object({
    qty: quantityField('Jumlah'),
    date: z.date().optional(),
    unit_price: z.string().optional(),
    vendor_id: z.string().optional(),
    material_id: z.string().optional(),
    text: z.string().max(500, 'Maksimal 500 karakter').optional(),
    // Custom return (Sub 4): map onto an existing item, or register a new one.
    return_target: z.enum(['existing', 'new']).optional(),
    new_name: z.string().max(150, 'Maksimal 150 karakter').optional(),
    new_category_id: z.string().optional(),
    new_spec: z.string().max(150, 'Maksimal 150 karakter').optional(),
    new_brand: z.string().max(100, 'Maksimal 100 karakter').optional(),
    new_similar_reason: z.string().max(500, 'Maksimal 500 karakter').optional(),
    new_cost_price: z.string().optional(),
});

type FormValues = z.infer<typeof schema>;

interface ProjectMaterialActionDialogProps {
    line: ProjectMaterial | null;
    action: MaterialAction | null;
    onOpenChange: (open: boolean) => void;
    vendors: VendorOption[];
    /** Catalog items a CUSTOM line can be mapped onto when returned (Logistics). */
    catalogOptions: Pick<Material, 'id' | 'name' | 'unit_id'>[];
    /** Material categories for registering a custom leftover as a new catalog item (Logistics). */
    categories: Pick<MaterialCategory, 'id' | 'name' | 'code_prefix'>[];
}

const ROUTE: Record<MaterialAction, string> = {
    issue: 'project-materials.issue',
    purchase: 'project-materials.purchase',
    usage: 'project-materials.usage',
    return: 'project-materials.return',
    waste: 'project-materials.waste',
    handOver: 'project-materials.handOver',
};

export function ProjectMaterialActionDialog({ line, action, onOpenChange, vendors, catalogOptions, categories }: ProjectMaterialActionDialogProps) {
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { qty: '' } });
    // §5.5 Lapis 3 — a custom leftover registered as a new catalog item gets the same duplicate check.
    const [returnTarget, newName, newCategory, newSpec, newBrand] = form.watch(['return_target', 'new_name', 'new_category_id', 'new_spec', 'new_brand']);
    const { items: similar, exactId } = useSimilarMaterials(
        { material_category_id: newCategory, base_name: newName, spec: newSpec, brand: newBrand, unit_id: line?.unit_id },
        action === 'return' && line?.material_id === null && returnTarget === 'new',
    );

    useEffect(() => {
        if (line && action) {
            form.reset({
                qty: action === 'purchase' || action === 'issue' ? '' : String(line.leftover),
                date: new Date(),
                unit_price: line.unit_price ? String(Number(line.unit_price)) : '',
                vendor_id: line.vendor_id ? String(line.vendor_id) : '',
                material_id: '',
                text: '',
                return_target: 'existing',
                new_name: line.custom_name ?? '',
                new_category_id: '',
                new_spec: line.custom_spec ?? '',
                new_brand: '',
                new_similar_reason: '',
                new_cost_price: line.unit_price ? String(Number(line.unit_price)) : '',
            });
        }
    }, [line?.id, action]);

    if (!line || !action) {
        return null;
    }

    const unit = line.unit?.code ?? '';
    const copy = ACTION_COPY[action];
    const isCustomReturn = action === 'return' && line.material_id === null;
    const sameUnitCatalog = catalogOptions.filter((material) => material.unit_id === line.unit_id);
    // The ceiling shown and checked client-side; the server re-checks under a lock.
    const ceiling = action === 'issue' ? (line.material?.stock ?? 0) : action === 'purchase' ? null : line.leftover;

    function onSubmit(values: FormValues) {
        if (!line || !action) return;

        const qty = parseQty(values.qty);
        const fail = (field: keyof FormValues, message: string) => form.setError(field, { message });

        if (ceiling !== null && qty > ceiling) {
            return fail('qty', `Melebihi ${action === 'issue' ? 'stok gudang' : 'sisa'} (${formatQty(ceiling)} ${unit})`);
        }
        if (action === 'purchase' && (values.unit_price === undefined || values.unit_price === '' || Number(values.unit_price) < 0)) {
            return fail('unit_price', 'Harga beli per satuan wajib diisi.');
        }
        if (action === 'waste' && !values.text?.trim()) {
            return fail('text', 'Alasan susut wajib diisi.');
        }
        if (action === 'handOver' && !values.text?.trim()) {
            return fail('text', 'Catatan penyerahan ke klien wajib diisi.');
        }
        const registerNew = isCustomReturn && values.return_target === 'new';
        if (isCustomReturn && !registerNew && !values.material_id) {
            return fail('material_id', 'Pilih barang katalog tujuan retur.');
        }
        if (registerNew && !values.new_category_id) {
            return fail('new_category_id', 'Kategori barang baru wajib dipilih.');
        }
        if (registerNew && !values.new_name?.trim()) {
            return fail('new_name', 'Nama barang katalog baru wajib diisi.');
        }
        if (registerNew && (!values.new_cost_price || Number(values.new_cost_price) < 0)) {
            return fail('new_cost_price', 'Harga gudang barang baru wajib diisi.');
        }

        const date = values.date ? format(values.date, 'yyyy-MM-dd') : format(new Date(), 'yyyy-MM-dd');
        const note = values.text?.trim() || null;
        const payload: Record<string, string | number | null | Record<string, string | number | null>> = { qty };

        if (action === 'issue') Object.assign(payload, { movement_date: date, note });
        if (action === 'purchase')
            Object.assign(payload, {
                unit_price: Number(values.unit_price),
                vendor_id: values.vendor_id ? Number(values.vendor_id) : null,
                purchase_date: date,
                note,
            });
        if (action === 'usage') Object.assign(payload, { note });
        if (action === 'return')
            Object.assign(
                payload,
                registerNew
                    ? {
                          movement_date: date,
                          note,
                          new_material: {
                              name: values.new_name?.trim() ?? '',
                              material_category_id: Number(values.new_category_id),
                              spec: values.new_spec?.trim() || null,
                              brand: values.new_brand?.trim() || null,
                              similar_reason: values.new_similar_reason?.trim() || null,
                              cost_price: Number(values.new_cost_price),
                          },
                      }
                    : { movement_date: date, note, material_id: values.material_id ? Number(values.material_id) : null },
            );
        if (action === 'waste') Object.assign(payload, { reason: note });
        if (action === 'handOver') Object.assign(payload, { note });

        router.post(route(ROUTE[action], { project_material: line.id }), payload, {
            preserveScroll: true,
            onError: (errors: Record<string, string>) =>
                Object.entries(errors).forEach(([field, message]) => {
                    const key = (
                        {
                            movement_date: 'date',
                            purchase_date: 'date',
                            reason: 'text',
                            note: 'text',
                            'new_material.name': 'new_name',
                            'new_material.material_category_id': 'new_category_id',
                            'new_material.spec': 'new_spec',
                            'new_material.brand': 'new_brand',
                            'new_material.similar_reason': 'new_similar_reason',
                            'new_material.cost_price': 'new_cost_price',
                        } as Record<string, keyof FormValues>
                    )[field] ?? (field as keyof FormValues);
                    form.setError(key, { message });
                }),
            onSuccess: () => onOpenChange(false),
        });
    }

    const qtyValue = parseQty(form.watch('qty') || '0') || 0;
    const priceValue = Number(form.watch('unit_price') || 0);
    const showDate = action === 'issue' || action === 'purchase' || action === 'return';
    const textLabel =
        action === 'waste' ? 'Alasan susut' : action === 'handOver' ? 'Catatan penyerahan' : 'Catatan (opsional)';

    return (
        <Dialog open onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{copy.title}</DialogTitle>
                    <DialogDescription>
                        {line.display_name}
                        {ceiling !== null && ` — ${action === 'issue' ? 'stok gudang' : 'sisa'} ${formatQty(ceiling)} ${unit}`}
                        {action === 'issue' && line.material && ` · ${formatRupiah(line.material.cost_price)}/${unit}`}. {copy.description}
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                control={form.control}
                                name="qty"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Jumlah ({unit})</FormLabel>
                                        <FormControl>
                                            <Input type="number" min="0.01" step="0.01" inputMode="decimal" autoFocus {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            {showDate && (
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
                            )}
                        </div>

                        {action === 'purchase' && (
                            <>
                                <FormField
                                    control={form.control}
                                    name="unit_price"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Harga Beli per {unit || 'satuan'} (Rp)</FormLabel>
                                            <FormControl>
                                                <Input type="number" min="0" step="any" inputMode="decimal" {...field} />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <p className="rounded-md bg-daiku-gray px-3 py-2 text-sm text-daiku-muted">
                                    Dibebankan ke proyek:{' '}
                                    <span className="font-semibold text-daiku-dark">{formatRupiah(qtyValue * priceValue)}</span>
                                </p>
                                <FormField
                                    control={form.control}
                                    name="vendor_id"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Vendor</FormLabel>
                                            <FormControl>
                                                <VendorSelect value={field.value ?? ''} onChange={field.onChange} vendors={vendors} allowEmpty />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                            </>
                        )}

                        {isCustomReturn && (
                            <FormField
                                control={form.control}
                                name="return_target"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Barang custom masuk ke katalog sebagai</FormLabel>
                                        <Select value={field.value} onValueChange={field.onChange}>
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                <SelectItem value="existing">Barang katalog yang sudah ada</SelectItem>
                                                <SelectItem value="new">Daftarkan barang baru</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </FormItem>
                                )}
                            />
                        )}

                        {isCustomReturn && form.watch('return_target') !== 'new' && (
                            <FormField
                                control={form.control}
                                name="material_id"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Barang katalog (satuan {unit})</FormLabel>
                                        <Select value={field.value} onValueChange={field.onChange}>
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue placeholder="Pilih barang" />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                {sameUnitCatalog.map((material) => (
                                                    <SelectItem key={material.id} value={String(material.id)}>
                                                        {material.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        )}

                        {isCustomReturn && form.watch('return_target') === 'new' && (
                            <div className="grid grid-cols-2 gap-4">
                                <FormField
                                    control={form.control}
                                    name="new_name"
                                    render={({ field }) => (
                                        <FormItem className="col-span-2">
                                            <FormLabel>Nama barang katalog baru ({unit})</FormLabel>
                                            <FormControl>
                                                <Input {...field} />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="new_category_id"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Kategori</FormLabel>
                                            <FormControl>
                                                <MaterialCategorySelect value={field.value ?? ''} onChange={field.onChange} categories={categories} />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="new_cost_price"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Harga gudang (Rp)</FormLabel>
                                            <FormControl>
                                                <Input type="number" min="0" step="any" inputMode="decimal" {...field} />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="new_spec"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Spesifikasi (opsional)</FormLabel>
                                            <FormControl>
                                                <Input {...field} />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="new_brand"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Merek (opsional)</FormLabel>
                                            <FormControl>
                                                <Input {...field} />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <div className="col-span-2">
                                    <SimilarMaterialsPanel
                                        items={similar}
                                        exactId={exactId}
                                        reasonField={
                                            <FormField
                                                control={form.control}
                                                name="new_similar_reason"
                                                render={({ field }) => (
                                                    <FormItem>
                                                        <FormLabel>Tetap daftarkan barang baru — alasan</FormLabel>
                                                        <FormControl>
                                                            <Textarea rows={2} {...field} />
                                                        </FormControl>
                                                        <FormMessage />
                                                    </FormItem>
                                                )}
                                            />
                                        }
                                    />
                                </div>
                            </div>
                        )}

                        <FormField
                            control={form.control}
                            name="text"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>{textLabel}</FormLabel>
                                    <FormControl>
                                        {action === 'waste' || action === 'handOver' ? (
                                            <Textarea rows={2} {...field} />
                                        ) : (
                                            <Input {...field} />
                                        )}
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
                                {copy.submit}
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
