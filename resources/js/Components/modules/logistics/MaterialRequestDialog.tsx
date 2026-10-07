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
import { parseQty, quantityField } from '@/lib/quantity';
import type { Project, UnitOption, VendorOption } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

// Mirrors App\Http\Requests\Logistics\StoreMaterialRequestRequest. The
// "full" fields are only required for PM/Estimator (checked in onSubmit).
const schema = z.object({
    project_id: z.string().min(1, 'Proyek wajib dipilih'),
    name: z.string().trim().min(1, 'Nama barang wajib diisi').max(150, 'Maksimal 150 karakter'),
    qty: quantityField('Jumlah'),
    spec: z.string().max(255, 'Maksimal 255 karakter').optional(),
    unit_id: z.string().optional(),
    estimated_price: z.string().optional(),
    reason: z.string().max(1000, 'Maksimal 1000 karakter').optional(),
    vendor_id: z.string().optional(),
    photo_link: z.string().max(500).optional(),
});

type FormValues = z.infer<typeof schema>;

type CatalogHint = { id: number; code: string; name: string };

interface MaterialRequestDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Projects the user may request on; with one fixed project the picker is hidden. */
    projects: Pick<Project, 'id' | 'name'>[];
    fixedProjectId?: number;
    /** Tukang: name + qty + note only — Logistics completes the rest (Sprint 12 #31). */
    minimal: boolean;
    units: UnitOption[];
    vendors: VendorOption[];
    /** Active catalog items for "Mungkin maksud Anda" (§5.5 Lapis 4). */
    catalogHints?: CatalogHint[];
    /** Prefill — e.g. what was typed in the catalog search before choosing to request. */
    initialName?: string;
    /** Picking a suggestion plans that catalog item instead (project tab). Without it the pick is noted in the request. */
    onPickCatalog?: (materialId: number) => void;
}

/** Catalog items sharing a word (3+ letters) with what's typed, best match first. */
function suggest(name: string, hints: CatalogHint[]): CatalogHint[] {
    const words = name
        .toLowerCase()
        .split(/[^\p{L}\p{N}]+/u)
        .filter((word) => word.length >= 3);

    if (words.length === 0) return [];

    return hints
        .map((hint) => ({ hint, score: words.filter((word) => hint.name.toLowerCase().includes(word)).length }))
        .filter(({ score }) => score > 0)
        .sort((a, b) => b.score - a.score)
        .slice(0, 3)
        .map(({ hint }) => hint);
}

/**
 * Sprint 11 Sub 4 — "Ajukan barang (tidak ada di katalog)". PM/Estimator
 * send the full request straight to Logistics; a Tukang's request goes to
 * the project's PM first. Sub 5 (§5.5 Lapis 4): while the name is typed,
 * matching catalog items are suggested — "Mungkin maksud Anda".
 */
export function MaterialRequestDialog({
    open,
    onOpenChange,
    projects,
    fixedProjectId,
    minimal,
    units,
    vendors,
    catalogHints = [],
    initialName = '',
    onPickCatalog,
}: MaterialRequestDialogProps) {
    const empty: FormValues = {
        project_id: fixedProjectId ? String(fixedProjectId) : projects.length === 1 ? String(projects[0].id) : '',
        name: initialName,
        qty: '',
        spec: '',
        unit_id: '',
        estimated_price: '',
        reason: '',
        vendor_id: '',
        photo_link: '',
    };
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: empty });

    useEffect(() => {
        if (open) form.reset(empty);
    }, [open, fixedProjectId, initialName]);

    const hints = suggest(form.watch('name') ?? '', catalogHints);

    function pickHint(hint: CatalogHint) {
        if (onPickCatalog) {
            onPickCatalog(hint.id);
            return;
        }

        // Tukang / queue page: say which catalog item is meant — Logistics then picks "pakai barang yang ada".
        form.setValue('name', hint.name);
        form.setValue('reason', `Barang katalog ${hint.code} ${hint.name}`);
    }

    function onSubmit(values: FormValues) {
        if (!minimal) {
            const missing: [keyof FormValues, string][] = [];
            if (!values.spec?.trim()) missing.push(['spec', 'Spesifikasi wajib diisi.']);
            if (!values.unit_id) missing.push(['unit_id', 'Satuan wajib dipilih.']);
            if (!values.estimated_price || Number(values.estimated_price) < 0) missing.push(['estimated_price', 'Estimasi harga wajib diisi.']);
            if (!values.reason?.trim()) missing.push(['reason', 'Alasan tidak memakai barang katalog wajib diisi.']);
            if (missing.length) {
                missing.forEach(([field, message]) => form.setError(field, { message }));
                return;
            }
        }

        const payload = minimal
            ? { name: values.name, qty: parseQty(values.qty), reason: values.reason || null }
            : {
                  name: values.name,
                  qty: parseQty(values.qty),
                  spec: values.spec,
                  unit_id: Number(values.unit_id),
                  estimated_price: Number(values.estimated_price),
                  reason: values.reason,
                  vendor_id: values.vendor_id ? Number(values.vendor_id) : null,
                  photo_link: values.photo_link || null,
              };

        router.post(route('projects.material-requests.store', { project: Number(values.project_id) }), payload, {
            preserveScroll: true,
            onError: (errors: Record<string, string>) =>
                Object.entries(errors).forEach(([field, message]) => form.setError(field as keyof FormValues, { message })),
            onSuccess: () => onOpenChange(false),
        });
    }

    const text = (name: 'spec' | 'photo_link', label: string, placeholder?: string, required = false) => (
        <FormField
            control={form.control}
            name={name}
            render={({ field }) => (
                <FormItem>
                    <FormLabel required={required}>{label}</FormLabel>
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
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Ajukan Barang</DialogTitle>
                    <DialogDescription>
                        {minimal
                            ? 'Pengajuan dikirim ke PM proyek, lalu ke Logistik. Barang baru boleh dibeli setelah disetujui.'
                            : 'Untuk barang yang tidak ada di katalog. Logistik meninjau dan bisa mengubah isinya; barang baru boleh dibeli setelah disetujui.'}
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        {!fixedProjectId && (
                            <FormField
                                control={form.control}
                                name="project_id"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Proyek</FormLabel>
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
                        <div className="grid grid-cols-3 gap-4">
                            <FormField
                                control={form.control}
                                name="name"
                                render={({ field }) => (
                                    <FormItem className="col-span-2">
                                        <FormLabel required>Nama Barang</FormLabel>
                                        <FormControl>
                                            <Input {...field} autoFocus placeholder="mis. Kaca tempered 8mm" />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="qty"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Jumlah</FormLabel>
                                        <FormControl>
                                            <Input type="number" min="0.01" step="0.01" inputMode="decimal" {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        {hints.length > 0 && (
                            <div className="rounded-lg bg-daiku-yellow-light px-3 py-2 text-sm">
                                <p className="font-medium text-daiku-dark">Mungkin maksud Anda — sudah ada di katalog:</p>
                                <ul className="mt-1 space-y-1">
                                    {hints.map((hint) => (
                                        <li key={hint.id} className="flex flex-wrap items-center justify-between gap-2">
                                            <span>
                                                <span className="font-medium">{hint.code}</span> {hint.name}
                                            </span>
                                            <Button type="button" size="sm" variant="outline" onClick={() => pickHint(hint)}>
                                                {onPickCatalog ? 'Pakai barang ini' : 'Ini yang saya maksud'}
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                        {!minimal && (
                            <>
                                {text('spec', 'Spesifikasi', 'Ukuran, warna, merek…', true)}
                                <div className="grid grid-cols-2 gap-4">
                                    <FormField
                                        control={form.control}
                                        name="unit_id"
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormLabel required>Satuan</FormLabel>
                                                <FormControl>
                                                    <UnitSelect value={field.value ?? ''} onChange={field.onChange} units={units} />
                                                </FormControl>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                    <FormField
                                        control={form.control}
                                        name="estimated_price"
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormLabel required>Estimasi Harga / Satuan (Rp)</FormLabel>
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
                                {text('photo_link', 'Link Foto', 'https://…')}
                            </>
                        )}
                        <FormField
                            control={form.control}
                            name="reason"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required={!minimal}>{minimal ? 'Catatan' : 'Alasan tidak memakai barang katalog'}</FormLabel>
                                    <FormControl>
                                        <Textarea rows={2} {...field} />
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
                                Kirim Pengajuan
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
