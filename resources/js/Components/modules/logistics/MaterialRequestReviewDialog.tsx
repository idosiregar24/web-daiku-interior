import { StatusChip } from '@/Components/shared/StatusChip';
import { UnitSelect } from '@/Components/shared/UnitSelect';
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
import { parseQty } from '@/lib/quantity';
import { cn } from '@/lib/utils';
import { MaterialCategorySelect } from '@/Components/modules/logistics/MaterialCategorySelect';
import { SimilarMaterialsPanel } from '@/Components/modules/logistics/SimilarMaterialsPanel';
import { useSimilarMaterials } from '@/hooks/useSimilarMaterials';
import type { Material, MaterialCategory, MaterialRequestDecision, ProjectMaterial, UnitOption, VendorOption } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

export type CatalogOption = Pick<Material, 'id' | 'name' | 'unit_id' | 'unit' | 'stock' | 'cost_price'>;

/** A queue row as MaterialRequestController::index() sends it. */
export type MaterialRequestRow = ProjectMaterial & {
    project: { id: number; name: string; pm_id: number | null; status: string };
    pm_reviewer?: { id: number; name: string } | null;
    reviewer?: { id: number; name: string } | null;
    similar_catalog: CatalogOption[];
    similar_requests: (ProjectMaterial & { project: { id: number; name: string } })[];
};

const DECISIONS: { value: MaterialRequestDecision; label: string; hint: string }[] = [
    { value: 'PAKAI_KATALOG', label: 'Pakai barang yang ada', hint: 'Barang katalog — ambil dari gudang atau dibeli untuk proyek.' },
    { value: 'DAFTAR_KATALOG', label: 'Daftarkan ke katalog', hint: 'Barang umum yang belum ada — masuk katalog, dibeli untuk proyek.' },
    { value: 'CUSTOM', label: 'Setujui sebagai custom', hint: 'Barang khusus proyek ini saja — tidak masuk katalog.' },
    { value: 'TOLAK', label: 'Tolak', hint: 'Alasan wajib — pengaju mendapat notifikasi.' },
];

export const DECISION_LABEL: Record<MaterialRequestDecision, string> = {
    PAKAI_KATALOG: 'Pakai katalog',
    DAFTAR_KATALOG: 'Didaftarkan ke katalog',
    CUSTOM: 'Custom',
    TOLAK: 'Ditolak',
};

// Mirrors App\Http\Requests\Logistics\ReviewMaterialRequestRequest; the
// per-decision requirements are checked in onSubmit.
const schema = z.object({
    decision: z.enum(['PAKAI_KATALOG', 'DAFTAR_KATALOG', 'CUSTOM', 'TOLAK']),
    qty: z.string(),
    material_id: z.string(),
    source: z.enum(['GUDANG', 'PEMBELIAN']),
    name: z.string().max(150, 'Maksimal 150 karakter'),
    spec: z.string().max(150, 'Maksimal 150 karakter'),
    unit_id: z.string(),
    material_category_id: z.string(),
    brand: z.string().max(100, 'Maksimal 100 karakter'),
    similar_reason: z.string().max(500, 'Maksimal 500 karakter'),
    warehouse_price: z.string(),
    sell_price: z.string(),
    unit_price: z.string(),
    vendor_id: z.string(),
    reject_reason: z.string().max(1000, 'Maksimal 1000 karakter'),
});

type FormValues = z.infer<typeof schema>;

interface MaterialRequestReviewDialogProps {
    line: MaterialRequestRow | null;
    onOpenChange: (open: boolean) => void;
    catalog: CatalogOption[];
    units: UnitOption[];
    vendors: VendorOption[];
    categories: Pick<MaterialCategory, 'id' | 'name' | 'code_prefix'>[];
}

/**
 * Sprint 11 decision #13 — Logistics' review screen: what was asked,
 * similar catalog items and similar requests on other projects, then one
 * of four decisions. Logistics may change qty, price and spec; the
 * original request stays in `requested_snapshot`.
 */
export function MaterialRequestReviewDialog({ line, onOpenChange, catalog, units, vendors, categories }: MaterialRequestReviewDialogProps) {
    const form = useForm<FormValues>({ resolver: zodResolver(schema) });

    useEffect(() => {
        if (!line) return;
        form.reset({
            decision: 'PAKAI_KATALOG',
            qty: String(line.qty_planned),
            material_id: line.similar_catalog[0] ? String(line.similar_catalog[0].id) : '',
            source: 'GUDANG',
            name: line.custom_name ?? '',
            spec: line.custom_spec ?? '',
            unit_id: line.unit_id ? String(line.unit_id) : '',
            material_category_id: '',
            brand: '',
            similar_reason: '',
            warehouse_price: line.unit_price ? String(Number(line.unit_price)) : '',
            sell_price: '',
            unit_price: line.unit_price ? String(Number(line.unit_price)) : '',
            vendor_id: line.vendor_id ? String(line.vendor_id) : '',
            reject_reason: '',
        });
    }, [line?.id]);

    // §5.5 Lapis 3 — live "barang serupa" check while registering a new catalog item.
    const [watchedDecision, watchedCategory, watchedName, watchedSpec, watchedBrand, watchedUnit] = form.watch([
        'decision',
        'material_category_id',
        'name',
        'spec',
        'brand',
        'unit_id',
    ]);
    const { items: similar, exactId } = useSimilarMaterials(
        { material_category_id: watchedCategory, base_name: watchedName, spec: watchedSpec, brand: watchedBrand, unit_id: watchedUnit },
        line !== null && watchedDecision === 'DAFTAR_KATALOG',
    );

    if (!line) {
        return null;
    }

    const decision = form.watch('decision');
    const source = form.watch('source');
    const snapshot = line.requested_snapshot;
    const askedUnit = units.find((unit) => unit.id === snapshot?.unit_id)?.code;
    const purchased = decision === 'DAFTAR_KATALOG' || decision === 'CUSTOM' || (decision === 'PAKAI_KATALOG' && source === 'PEMBELIAN');

    function onSubmit(values: FormValues) {
        if (!line) return;
        const errors: [keyof FormValues, string][] = [];
        const need = (field: keyof FormValues, message: string) => !values[field].trim() && errors.push([field, message]);

        if (values.decision === 'TOLAK') {
            need('reject_reason', 'Alasan penolakan wajib diisi.');
        } else {
            if (!(parseQty(values.qty) > 0)) errors.push(['qty', 'Jumlah yang disetujui wajib diisi.']);
            if (values.decision === 'PAKAI_KATALOG') need('material_id', 'Pilih barang katalog yang dipakai.');
            if (values.decision !== 'PAKAI_KATALOG') {
                need('name', 'Nama barang wajib diisi.');
                need('unit_id', 'Satuan wajib dipilih.');
            }
            if (values.decision === 'DAFTAR_KATALOG') {
                need('material_category_id', 'Kategori barang wajib dipilih.');
                need('warehouse_price', 'Harga gudang barang baru wajib diisi.');
            }
            if (values.decision === 'CUSTOM') need('unit_price', 'Harga per satuan wajib diisi.');
        }

        if (errors.length) {
            errors.forEach(([field, message]) => form.setError(field, { message }));
            return;
        }

        const number = (value: string) => (value.trim() === '' ? null : Number(value));
        const payload =
            values.decision === 'TOLAK'
                ? { decision: 'TOLAK', reject_reason: values.reject_reason.trim() }
                : {
                      decision: values.decision,
                      qty: parseQty(values.qty),
                      ...(values.decision === 'PAKAI_KATALOG'
                          ? { material_id: Number(values.material_id), source: values.source }
                          : { name: values.name.trim(), unit_id: Number(values.unit_id), spec: values.spec || null }),
                      ...(values.decision === 'DAFTAR_KATALOG'
                          ? {
                                material_category_id: Number(values.material_category_id),
                                brand: values.brand || null,
                                warehouse_price: number(values.warehouse_price),
                                sell_price: number(values.sell_price),
                                similar_reason: values.similar_reason || null,
                            }
                          : {}),
                      ...(purchased ? { unit_price: number(values.unit_price), vendor_id: values.vendor_id ? Number(values.vendor_id) : null } : {}),
                  };

        router.post(route('logistics.material-requests.review', { project_material: line.id }), payload, {
            preserveScroll: true,
            onError: (serverErrors: Record<string, string>) =>
                Object.entries(serverErrors).forEach(([field, message]) => form.setError(field as keyof FormValues, { message })),
            onSuccess: () => onOpenChange(false),
        });
    }

    const field = (name: keyof FormValues, label: string, props: React.ComponentProps<typeof Input> = {}) => (
        <FormField
            control={form.control}
            name={name}
            render={({ field: input }) => (
                <FormItem>
                    <FormLabel>{label}</FormLabel>
                    <FormControl>
                        <Input {...input} {...props} />
                    </FormControl>
                    <FormMessage />
                </FormItem>
            )}
        />
    );
    const money = { type: 'number', min: '0', step: 'any', inputMode: 'decimal' } as const;

    return (
        <Dialog open onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Tinjau Pengajuan</DialogTitle>
                    <DialogDescription>
                        {line.project.name} · diajukan {line.requester?.name ?? '—'}
                        {line.request_channel === 'TUKANG' && line.pm_reviewer && ` (Tukang, disetujui PM ${line.pm_reviewer.name})`}
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-4 md:grid-cols-2">
                    <div className="rounded-lg bg-daiku-gray/70 p-3 text-sm ring-1 ring-border ring-inset">
                        <p className="mb-1 text-xs font-semibold tracking-wide text-daiku-muted uppercase">Yang diajukan</p>
                        <p className="font-medium text-daiku-dark">{snapshot?.name ?? line.custom_name}</p>
                        {snapshot?.spec && <p className="text-daiku-muted">{snapshot.spec}</p>}
                        <p className="mt-1">
                            {formatQty(snapshot?.qty ?? line.qty_planned)} {askedUnit ?? '(satuan belum ditentukan)'}
                            {snapshot?.estimated_price !== undefined && ` · estimasi ${formatRupiah(snapshot.estimated_price)}/satuan`}
                        </p>
                        {snapshot?.reason && <p className="mt-1 text-daiku-muted">“{snapshot.reason}”</p>}
                        {snapshot?.photo_link && (
                            <a
                                href={snapshot.photo_link}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="mt-1 inline-block text-sm underline decoration-daiku-yellow underline-offset-2"
                            >
                                Lihat foto
                            </a>
                        )}
                    </div>
                    <div className="space-y-3 text-sm">
                        <div>
                            <p className="mb-1 text-xs font-semibold tracking-wide text-daiku-muted uppercase">Barang katalog mirip</p>
                            {line.similar_catalog.length === 0 ? (
                                <p className="text-daiku-muted">Tidak ada yang mirip.</p>
                            ) : (
                                <ul className="space-y-1">
                                    {line.similar_catalog.map((material) => (
                                        <li key={material.id} className="flex items-center justify-between gap-2">
                                            <span>
                                                {material.name}{' '}
                                                <span className="text-daiku-muted">
                                                    · stok {formatQty(material.stock)} {material.unit?.code}
                                                </span>
                                            </span>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => {
                                                    form.setValue('decision', 'PAKAI_KATALOG');
                                                    form.setValue('material_id', String(material.id));
                                                }}
                                            >
                                                Pakai ini
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                        <div>
                            <p className="mb-1 text-xs font-semibold tracking-wide text-daiku-muted uppercase">Pengajuan serupa di proyek lain</p>
                            {line.similar_requests.length === 0 ? (
                                <p className="text-daiku-muted">Belum ada.</p>
                            ) : (
                                <ul className="space-y-1">
                                    {line.similar_requests.map((other) => (
                                        <li key={other.id} className="flex flex-wrap items-center gap-1.5">
                                            <span>{other.display_name}</span>
                                            <span className="text-daiku-muted">· {other.project.name}</span>
                                            <StatusChip
                                                status={other.request_status}
                                                label={other.review_decision ? DECISION_LABEL[other.review_decision] : other.request_status}
                                            />
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </div>
                </div>

                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="decision"
                            render={({ field: input }) => (
                                <FormItem>
                                    <FormLabel>Keputusan</FormLabel>
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {DECISIONS.map((option) => (
                                            <button
                                                key={option.value}
                                                type="button"
                                                onClick={() => input.onChange(option.value)}
                                                className={cn(
                                                    'rounded-lg border p-3 text-left text-sm transition-colors',
                                                    input.value === option.value
                                                        ? 'border-daiku-yellow bg-daiku-yellow-light'
                                                        : 'border-border hover:bg-daiku-gray/60',
                                                )}
                                            >
                                                <p className="font-medium text-daiku-dark">{option.label}</p>
                                                <p className="text-xs text-daiku-muted">{option.hint}</p>
                                            </button>
                                        ))}
                                    </div>
                                </FormItem>
                            )}
                        />

                        {decision === 'TOLAK' ? (
                            <FormField
                                control={form.control}
                                name="reject_reason"
                                render={({ field: input }) => (
                                    <FormItem>
                                        <FormLabel>Alasan penolakan</FormLabel>
                                        <FormControl>
                                            <Textarea rows={3} {...input} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        ) : (
                            <>
                                {decision === 'PAKAI_KATALOG' && (
                                    <div className="grid gap-4 sm:grid-cols-3">
                                        <FormField
                                            control={form.control}
                                            name="material_id"
                                            render={({ field: input }) => (
                                                <FormItem className="sm:col-span-2">
                                                    <FormLabel>Barang katalog</FormLabel>
                                                    <Select value={input.value} onValueChange={input.onChange}>
                                                        <FormControl>
                                                            <SelectTrigger className="w-full">
                                                                <SelectValue placeholder="Pilih barang" />
                                                            </SelectTrigger>
                                                        </FormControl>
                                                        <SelectContent>
                                                            {catalog.map((material) => (
                                                                <SelectItem key={material.id} value={String(material.id)}>
                                                                    {material.name} — stok {formatQty(material.stock)} {material.unit?.code}
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
                                            name="source"
                                            render={({ field: input }) => (
                                                <FormItem>
                                                    <FormLabel>Sumber</FormLabel>
                                                    <Select value={input.value} onValueChange={input.onChange}>
                                                        <FormControl>
                                                            <SelectTrigger className="w-full">
                                                                <SelectValue />
                                                            </SelectTrigger>
                                                        </FormControl>
                                                        <SelectContent>
                                                            <SelectItem value="GUDANG">Gudang</SelectItem>
                                                            <SelectItem value="PEMBELIAN">Pembelian</SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                    <FormMessage />
                                                </FormItem>
                                            )}
                                        />
                                    </div>
                                )}

                                {decision !== 'PAKAI_KATALOG' && (
                                    <>
                                        <div className="grid gap-4 sm:grid-cols-3">
                                            <div className="sm:col-span-2">{field('name', 'Nama barang')}</div>
                                            <FormField
                                                control={form.control}
                                                name="unit_id"
                                                render={({ field: input }) => (
                                                    <FormItem>
                                                        <FormLabel>Satuan</FormLabel>
                                                        <FormControl>
                                                            <UnitSelect value={input.value} onChange={input.onChange} units={units} />
                                                        </FormControl>
                                                        <FormMessage />
                                                    </FormItem>
                                                )}
                                            />
                                        </div>
                                        <div className="grid gap-4 sm:grid-cols-2">
                                            {field('spec', 'Spesifikasi (opsional)')}
                                            {decision === 'DAFTAR_KATALOG' && field('brand', 'Merek (opsional)')}
                                        </div>
                                        {decision === 'DAFTAR_KATALOG' && (
                                            <div className="grid gap-4 sm:grid-cols-3">
                                                <FormField
                                                    control={form.control}
                                                    name="material_category_id"
                                                    render={({ field: input }) => (
                                                        <FormItem>
                                                            <FormLabel>Kategori</FormLabel>
                                                            <FormControl>
                                                                <MaterialCategorySelect value={input.value} onChange={input.onChange} categories={categories} />
                                                            </FormControl>
                                                            <FormMessage />
                                                        </FormItem>
                                                    )}
                                                />
                                                {field('warehouse_price', 'Harga gudang (Rp)', money)}
                                                {field('sell_price', 'Harga jual (opsional)', money)}
                                            </div>
                                        )}
                                        {decision === 'DAFTAR_KATALOG' && (
                                            <SimilarMaterialsPanel
                                                items={similar}
                                                exactId={exactId}
                                                onUse={(material) => {
                                                    form.setValue('decision', 'PAKAI_KATALOG');
                                                    form.setValue('material_id', String(material.id));
                                                }}
                                                reasonField={
                                                    <FormField
                                                        control={form.control}
                                                        name="similar_reason"
                                                        render={({ field: input }) => (
                                                            <FormItem>
                                                                <FormLabel>Tetap daftarkan barang baru — alasan</FormLabel>
                                                                <FormControl>
                                                                    <Textarea rows={2} {...input} />
                                                                </FormControl>
                                                                <FormMessage />
                                                            </FormItem>
                                                        )}
                                                    />
                                                }
                                            />
                                        )}
                                    </>
                                )}

                                <div className="grid gap-4 sm:grid-cols-2">
                                    {field('qty', 'Jumlah disetujui', { type: 'number', min: '0.01', step: '0.01', inputMode: 'decimal' })}
                                    {purchased && field('unit_price', 'Harga beli / satuan (Rp)', money)}
                                </div>
                                {purchased && (
                                    <FormField
                                        control={form.control}
                                        name="vendor_id"
                                        render={({ field: input }) => (
                                            <FormItem>
                                                <FormLabel>Vendor (opsional)</FormLabel>
                                                <FormControl>
                                                    <VendorSelect value={input.value} onChange={input.onChange} vendors={vendors} allowEmpty />
                                                </FormControl>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                )}
                            </>
                        )}

                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    Batal
                                </Button>
                            </DialogClose>
                            <Button type="submit" variant={decision === 'TOLAK' ? 'destructive' : 'default'} disabled={form.formState.isSubmitting}>
                                {decision === 'TOLAK' ? 'Tolak Pengajuan' : 'Setujui'}
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
