import { ConfirmDialog } from '@/Components/shared/ConfirmDialog';
import { SearchInput } from '@/Components/shared/SearchInput';
import { UnitSelect } from '@/Components/shared/UnitSelect';
import { Button } from '@/Components/ui/button';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { formatQty } from '@/lib/format';
import { projectVisit, RELOAD, useProjectCatalog } from '@/lib/projectReload';
import { parseQty, quantityField } from '@/lib/quantity';
import { cn } from '@/lib/utils';
import type { Material, PageProps, ProjectExecution, UnitOption } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router, usePage } from '@inertiajs/react';
import { Check, Loader2, PackagePlus, Plus, Send, Trash2, X } from 'lucide-react';
import { type KeyboardEvent, useEffect, useId, useMemo, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { MaterialStatusChip } from './MaterialStatusChip';

/** `stock` is never sent to the Pengawas / Admin Proyek (Sprint 29 Sub 11 E1). */
export type MaterialOption = Pick<Material, 'id' | 'code' | 'name' | 'unit_id' | 'unit'> & { stock?: Material['stock'] };
type PlannedLine = ProjectExecution['meetingMaterials'][number];

const SOURCE_LABEL: Record<string, string> = { GUDANG: 'Gudang', PEMBELIAN: 'Beli', CUSTOM: 'Custom' };

// Mirrors StoreProjectMaterialRequest (vendor stays optional — set it later on the Material tab).
const schema = z.object({
    material_id: z.string().min(1, 'Pilih barang dari katalog.'),
    qty_planned: quantityField('Jumlah'),
    source: z.enum(['GUDANG', 'PEMBELIAN']),
});

type Values = z.infer<typeof schema>;

// Mirrors StorePlanCustomMaterialRequest (Sprint 29 Sub 11 E2 — "+ Bahan lain (custom)").
const customSchema = z.object({
    name: z.string().trim().min(1, 'Nama bahan wajib diisi.').max(150, 'Maksimal 150 karakter.'),
    spec: z.string().max(255, 'Maksimal 255 karakter.'),
    qty: quantityField('Jumlah'),
    unit_id: z.string().min(1, 'Satuan wajib dipilih.'),
    photo_link: z.union([z.literal(''), z.string().url('Link foto harus berupa URL yang valid.').max(500, 'Maksimal 500 karakter.')]),
    note: z.string().max(1000, 'Maksimal 1000 karakter.'),
});

type CustomValues = z.infer<typeof customSchema>;

const CUSTOM_DEFAULTS: CustomValues = { name: '', spec: '', qty: '', unit_id: '', photo_link: '', note: '' };

// Quiet partial reload (Sprint 29 Sub 01 K2/K4): the changed row is the feedback.
const VISIT = projectVisit(RELOAD.materials, { quiet: true });

/** An added line not saved yet — shown at once (K3), sent one after another. */
interface QueuedLine {
    key: string;
    material_id: number;
    name: string;
    unit: string;
    source: Values['source'];
    qty: string;
}

/**
 * Sprint 24 — "Bahan yang digunakan" planned right in the TM card: search
 * the catalog, type the amount, Enter — no dialog, no trip to the Material
 * tab. Amounts are edited in place; the Pengawas may drop a line until
 * production starts. Same routes & policies as the Material tab
 * (projects.materials.store, project-materials.update / destroy).
 *
 * Sprint 29 Sub 01 (L2): Enter puts the line in the list straight away
 * ("menyimpan…") and the search is ready for the next item; lines are sent
 * one at a time (each answer carries the whole list), a refused one
 * disappears with the reason.
 *
 * Sprint 29 Sub 11 (E1–E5): for the Pengawas / Admin Proyek (`throughLogistics`)
 * this is the "Rencana Bahan" — no stock and no source to pick (the server
 * decides), "+ Bahan lain (custom)" next to the catalog, every line
 * "Direncanakan" until "Ajukan ke Logistik (n bahan)", then its status chip.
 */
export function TmMaterialPlanner({
    projectId,
    lines,
    canUpdate,
    canRemove,
    throughLogistics = false,
}: {
    projectId: number;
    lines: PlannedLine[];
    canUpdate: boolean;
    canRemove: boolean;
    throughLogistics?: boolean;
}) {
    const catalog = useProjectCatalog();
    const options = catalog.materialOptions;
    const [queue, setQueue] = useState<QueuedLine[]>([]);
    const [sendingKey, setSendingKey] = useState<string | null>(null);
    const [failure, setFailure] = useState<string | null>(null);
    const nextKey = useRef(0);
    const [term, setTerm] = useState('');
    const [listOpen, setListOpen] = useState(false);
    const [active, setActive] = useState(0);
    // Input doesn't forward refs (React 18) — focus by id.
    const searchId = useId();
    const qtyId = useId();
    // By a data attribute: FormControl gives the qty input its own id (for the label).
    const focus = (key: string) => requestAnimationFrame(() => document.querySelector<HTMLInputElement>(`[data-focus-key="${key}"] input, input[data-focus-key="${key}"]`)?.focus());
    const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { material_id: '', qty_planned: '', source: 'GUDANG' } });
    const [customOpen, setCustomOpen] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const drafts = lines.filter((line) => line.order_status === 'DIRENCANAKAN');

    /** E3 — every draft line to Logistik at once (a decision: the success pop-up shows). */
    function submitPlan() {
        router.post(
            route('projects.material-plans.submit', { project: projectId }),
            {},
            {
                ...projectVisit(RELOAD.materials),
                onStart: () => setSubmitting(true),
                onFinish: () => setSubmitting(false),
                onError: (errors) => setFailure(Object.values(errors)[0] ?? 'Gagal mengajukan ke Logistik.'),
            },
        );
    }

    const materialId = form.watch('material_id');
    const source = form.watch('source');
    const picked = options.find((option) => String(option.id) === materialId) ?? null;
    // Sprint 29 Sub 11 — in the Rencana Bahan the same item replaces its draft line (any source; submitted lines stay).
    const sameLine = (line: PlannedLine, id: number, lineSource: string) =>
        line.material_id === id && (throughLogistics ? line.channel === 'RENCANA' && line.order_status === 'DIRENCANAKAN' : line.source === lineSource);
    const existing = picked ? lines.find((line) => sameLine(line, picked.id, source)) : undefined;
    const plannedIds = useMemo(() => new Set(lines.map((line) => line.material_id)), [lines]);
    // A queued item already planned from the same source replaces that line's amount — mark it there.
    const isSame = (line: PlannedLine, queued: QueuedLine) => sameLine(line, queued.material_id, queued.source);
    const savingLineIds = new Set(lines.filter((line) => queue.some((queued) => isSame(line, queued))).map((line) => line.id));
    const incoming = queue.filter((queued) => !lines.some((line) => isSame(line, queued)));

    // Send the queue one line at a time.
    useEffect(() => {
        if (sendingKey !== null || queue.length === 0) return;

        const next = queue[0];
        setSendingKey(next.key);
        router.post(
            route('projects.materials.store', { project: projectId }),
            { material_id: next.material_id, ...(throughLogistics ? {} : { source: next.source }), qty_planned: parseQty(next.qty), vendor_id: null },
            {
                ...VISIT,
                // Not cancelled by an amount edited meanwhile.
                async: true,
                onError: (errors) => setFailure(`${next.name}: ${Object.values(errors)[0] ?? 'gagal disimpan.'}`),
                onFinish: () => {
                    setQueue((rows) => rows.filter((row) => row.key !== next.key));
                    setSendingKey(null);
                },
            },
        );
    }, [queue, sendingKey]);

    const matches = useMemo(() => {
        const needle = term.trim().toLowerCase();

        return (needle ? options.filter((option) => `${option.code} ${option.name}`.toLowerCase().includes(needle)) : options).slice(0, 8);
    }, [term, options]);

    function pick(option: MaterialOption) {
        form.setValue('material_id', String(option.id), { shouldValidate: true });
        // Something in the warehouse → take it from there; otherwise it has to be bought
        // (for the Rencana Bahan the server decides — E1).
        form.setValue('source', Number(option.stock ?? 0) > 0 ? 'GUDANG' : 'PEMBELIAN');
        setTerm('');
        setListOpen(false);
        focus(qtyId);
    }

    function clearPick() {
        form.setValue('material_id', '');
        focus(searchId);
    }

    function onSearchKey(event: KeyboardEvent<HTMLInputElement>) {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setListOpen(true);
            setActive((index) => Math.min(index + 1, matches.length - 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((index) => Math.max(index - 1, 0));
        } else if (event.key === 'Enter') {
            event.preventDefault();
            if (matches[active]) pick(matches[active]);
        } else if (event.key === 'Escape') {
            setListOpen(false);
        }
    }

    function onSubmit(values: Values) {
        const material = options.find((option) => String(option.id) === values.material_id);
        if (!material) return;

        setQueue((rows) => [
            ...rows,
            {
                key: `tm-${nextKey.current++}`,
                material_id: material.id,
                name: material.name,
                unit: material.unit?.code ?? '',
                source: values.source,
                qty: values.qty_planned.trim(),
            },
        ]);
        setFailure(null);
        form.reset({ material_id: '', qty_planned: '', source: 'GUDANG' });
        focus(searchId);
    }

    return (
        <div className="space-y-3">
            <Form {...form}>
                <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-2 rounded-lg bg-daiku-gray/60 p-3">
                    <FormField
                        control={form.control}
                        name="material_id"
                        render={() => (
                            <FormItem>
                                <FormLabel required>Barang (katalog)</FormLabel>
                                {picked ? (
                                    <div className="flex items-center gap-2 rounded-md bg-background px-3 py-2 text-sm ring-1 ring-daiku-yellow">
                                        <span className="min-w-0 flex-1 truncate">
                                            <span className="font-medium">{picked.code}</span> {picked.name}
                                        </span>
                                        {picked.stock !== undefined && (
                                            <span className="shrink-0 text-xs text-daiku-muted">
                                                stok {formatQty(picked.stock)} {picked.unit?.code}
                                            </span>
                                        )}
                                        <Button type="button" variant="ghost" size="icon-sm" className="max-md:size-11" aria-label="Ganti barang" onClick={clearPick}>
                                            <X className="size-4" />
                                        </Button>
                                    </div>
                                ) : (
                                    <div className="relative">
                                        <SearchInput
                                            data-focus-key={searchId}
                                            className="max-md:[&_input]:h-11"
                                            value={term}
                                            onChange={(event) => {
                                                setTerm(event.target.value);
                                                setActive(0);
                                                setListOpen(true);
                                            }}
                                            onFocus={() => {
                                                catalog.load();
                                                setListOpen(true);
                                            }}
                                            onBlur={() => setTimeout(() => setListOpen(false), 150)}
                                            onKeyDown={onSearchKey}
                                            placeholder="Ketik nama atau kode barang…"
                                            aria-label="Cari barang katalog"
                                            autoComplete="off"
                                        />
                                        {listOpen && (
                                            <ul className="absolute inset-x-0 top-full z-20 mt-1 max-h-64 overflow-y-auto rounded-lg bg-popover py-1 shadow-md ring-1 ring-border">
                                                {!catalog.loaded ? (
                                                    <li className="flex items-center gap-2 px-3 py-2 text-sm text-daiku-muted">
                                                        <Loader2 className="size-4 animate-spin" />
                                                        Memuat katalog…
                                                    </li>
                                                ) : matches.length === 0 ? (
                                                    <li className="px-3 py-2 text-sm text-daiku-muted">
                                                        {throughLogistics
                                                            ? 'Tidak ada di katalog — pakai "Bahan lain (custom)".'
                                                            : 'Tidak ada di katalog — ajukan lewat tab Material (Ajukan Barang).'}
                                                    </li>
                                                ) : (
                                                    matches.map((option, index) => (
                                                        <li key={option.id}>
                                                            <button
                                                                type="button"
                                                                onMouseDown={(event) => event.preventDefault()}
                                                                onClick={() => pick(option)}
                                                                onMouseEnter={() => setActive(index)}
                                                                className={cn(
                                                                    'flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm max-md:min-h-11',
                                                                    index === active && 'bg-daiku-yellow-light',
                                                                )}
                                                            >
                                                                <span className="min-w-0 truncate">
                                                                    <span className="font-medium">{option.code}</span> {option.name}
                                                                </span>
                                                                <span className="flex shrink-0 items-center gap-1 text-xs text-daiku-muted">
                                                                    {plannedIds.has(option.id) && <Check className="size-3.5 text-success-ink" aria-label="Sudah direncanakan" />}
                                                                    {option.stock !== undefined ? `stok ${formatQty(option.stock)} ${option.unit?.code ?? ''}` : option.unit?.code}
                                                                </span>
                                                            </button>
                                                        </li>
                                                    ))
                                                )}
                                            </ul>
                                        )}
                                    </div>
                                )}
                                <FormMessage />
                            </FormItem>
                        )}
                    />
                    <div className={cn('grid items-start gap-2', throughLogistics ? 'grid-cols-[1fr_auto]' : 'grid-cols-[1fr_1fr_auto]')}>
                        <FormField
                            control={form.control}
                            name="qty_planned"
                            render={({ field }) => (
                                <FormItem data-focus-key={qtyId}>
                                    <FormLabel required>Jumlah{picked?.unit?.code ? ` (${picked.unit.code})` : ''}</FormLabel>
                                    <FormControl>
                                        <Input {...field} inputMode="decimal" placeholder="0" autoComplete="off" className="max-md:h-11" />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        {!throughLogistics && (
                        <FormField
                            control={form.control}
                            name="source"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Sumber</FormLabel>
                                    <Select value={field.value} onValueChange={field.onChange}>
                                        <FormControl>
                                            <SelectTrigger className="w-full max-md:h-11">
                                                <SelectValue />
                                            </SelectTrigger>
                                        </FormControl>
                                        <SelectContent>
                                            <SelectItem value="GUDANG">Gudang</SelectItem>
                                            <SelectItem value="PEMBELIAN">Beli</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </FormItem>
                            )}
                        />
                        )}
                        <Button type="submit" className="mt-[1.375rem] max-md:h-11">
                            <Plus className="size-4" />
                            Tambah
                        </Button>
                    </div>
                    {existing && (
                        <p className="text-xs text-warning-ink">
                            Sudah direncanakan {existing.qty}
                            {throughLogistics ? '' : ` (${SOURCE_LABEL[existing.source]})`} — jumlahnya akan diganti.
                        </p>
                    )}
                </form>
            </Form>

            {throughLogistics &&
                (customOpen ? (
                    <CustomMaterialForm projectId={projectId} onClose={() => setCustomOpen(false)} />
                ) : (
                    <Button type="button" variant="outline" size="sm" className="max-md:h-11" onClick={() => setCustomOpen(true)}>
                        <PackagePlus className="size-4" />
                        Bahan lain (custom)
                    </Button>
                ))}

            {failure && <p className="text-sm text-error-ink">{failure}</p>}

            {throughLogistics && drafts.length > 0 && (
                <div className="flex flex-col gap-2 rounded-lg bg-daiku-yellow-light px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-sm text-foreground">
                        {drafts.length} bahan direncanakan — belum sampai ke Logistik. Masih bisa diubah sampai diajukan.
                    </p>
                    <Button
                        type="button"
                        size="sm"
                        className="shrink-0 max-md:h-11 max-md:w-full"
                        disabled={submitting || queue.length > 0}
                        onClick={submitPlan}
                    >
                        {submitting ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                        Ajukan ke Logistik ({drafts.length} bahan)
                    </Button>
                </div>
            )}

            {lines.length === 0 && incoming.length === 0 ? (
                <p className="rounded-lg border border-dashed border-border px-3 py-6 text-center text-sm text-muted-foreground">Belum ada bahan.</p>
            ) : (
                <ul className="max-h-80 divide-y divide-border overflow-y-auto rounded-lg ring-1 ring-border">
                    {lines.map((line) => (
                        <PlannedRow
                            // Re-seed the local amount whenever the server's changes.
                            key={`${line.id}-${line.qty_planned}`}
                            line={line}
                            saving={savingLineIds.has(line.id)}
                            canUpdate={canUpdate && line.editable}
                            canRemove={canRemove && line.editable}
                        />
                    ))}
                    {incoming.map((queued) => (
                        <li key={queued.key} className="flex items-center gap-2 px-3 py-2 text-sm">
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-foreground">{queued.name}</p>
                                <SavingMark source={throughLogistics ? null : queued.source} />
                            </div>
                            <span className="shrink-0 text-muted-foreground tabular-nums">
                                {formatQty(parseQty(queued.qty))} {queued.unit}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function SavingMark({ source }: { source: string | null }) {
    return (
        <p className="flex items-center gap-1 text-xs text-muted-foreground">
            {source && `${SOURCE_LABEL[source]} ·`}
            <Loader2 className="size-3 animate-spin" />
            menyimpan…
        </p>
    );
}

function PlannedRow({ line, saving, canUpdate, canRemove }: { line: PlannedLine; saving: boolean; canUpdate: boolean; canRemove: boolean }) {
    const [value, setValue] = useState(String(line.qty_planned).replace('.', ','));
    const [error, setError] = useState<string | null>(null);
    const [updating, setUpdating] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [removing, setRemoving] = useState(false);

    function save() {
        const qty = parseQty(value);

        if (!Number.isFinite(qty) || qty < 0.01) {
            setError('Minimal 0,01');
            return;
        }

        setError(null);

        if (qty === line.qty_planned) return;

        router.put(route('project-materials.update', { project_material: line.id }), { qty_planned: qty }, {
            ...VISIT,
            onStart: () => setUpdating(true),
            onFinish: () => setUpdating(false),
            onError: (errors) => setError(Object.values(errors)[0] ?? 'Gagal menyimpan'),
        });
    }

    function remove() {
        router.delete(route('project-materials.destroy', { project_material: line.id }), {
            ...VISIT,
            onStart: () => setRemoving(true),
            onFinish: () => {
                setRemoving(false);
                setConfirming(false);
            },
        });
    }

    return (
        <li className="flex items-center gap-2 px-3 py-2 text-sm">
            <div className="min-w-0 flex-1">
                <p className="truncate text-foreground">{line.name}</p>
                {saving || updating ? (
                    <SavingMark source={line.channel === 'RENCANA' ? null : line.source} />
                ) : line.channel === 'RENCANA' ? (
                    <MaterialStatusChip line={line} unit={line.unit} inline className="mt-0.5" />
                ) : (
                    <p className="text-xs text-muted-foreground">{SOURCE_LABEL[line.source]}</p>
                )}
                {error && <p className="text-xs text-error-ink">{error}</p>}
            </div>
            {canUpdate ? (
                <div className="flex shrink-0 items-center gap-1">
                    <Input
                        value={value}
                        onChange={(event) => setValue(event.target.value)}
                        onBlur={save}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                event.preventDefault();
                                event.currentTarget.blur();
                            }
                        }}
                        inputMode="decimal"
                        aria-label={`Jumlah ${line.name}`}
                        className="h-8 w-20 text-right tabular-nums max-md:h-11"
                    />
                    <span className="w-10 text-xs text-muted-foreground">{line.unit}</span>
                </div>
            ) : (
                <span className="shrink-0 text-muted-foreground tabular-nums">{line.qty}</span>
            )}
            {canRemove && (
                <>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        className="max-md:size-11"
                        aria-label={`Hapus ${line.name}`}
                        onClick={() => setConfirming(true)}
                    >
                        <Trash2 className="size-4 text-error-ink" />
                    </Button>
                    <ConfirmDialog
                        open={confirming}
                        onOpenChange={setConfirming}
                        tone="danger"
                        title="Hapus dari rencana bahan?"
                        description={`${line.name} (${line.qty}) dihapus dari bahan yang digunakan.`}
                        confirmLabel="Ya, hapus"
                        busy={removing}
                        onConfirm={remove}
                    />
                </>
            )}
        </li>
    );
}

/**
 * Sprint 29 Sub 11 (E2) — "+ Bahan lain (custom)": an item outside the
 * catalog in the Rencana Bahan. "Simpan & tambah lagi" keeps the form open
 * (Sub 01 K5); Logistik matches it to the catalog once the plan is submitted.
 */
function CustomMaterialForm({ projectId, onClose }: { projectId: number; onClose: () => void }) {
    const { units } = usePage<PageProps<{ units?: UnitOption[] }>>().props;
    const [busy, setBusy] = useState(false);
    const [saved, setSaved] = useState(0);
    const nameId = useId();
    const form = useForm<CustomValues>({ resolver: zodResolver(customSchema), defaultValues: CUSTOM_DEFAULTS });

    function onSubmit(values: CustomValues) {
        router.post(
            route('projects.material-plans.custom', { project: projectId }),
            {
                name: values.name.trim(),
                spec: values.spec.trim() || null,
                qty: parseQty(values.qty),
                unit_id: Number(values.unit_id),
                photo_link: values.photo_link.trim() || null,
                note: values.note.trim() || null,
            },
            {
                ...VISIT,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onError: (errors) => Object.entries(errors).forEach(([field, message]) => form.setError(field as keyof CustomValues, { message })),
                onSuccess: () => {
                    setSaved((count) => count + 1);
                    // Same unit is the likely next one; focus back on the name (K5).
                    form.reset({ ...CUSTOM_DEFAULTS, unit_id: values.unit_id });
                    requestAnimationFrame(() => document.getElementById(nameId)?.focus());
                },
            },
        );
    }

    return (
        <Form {...form}>
            <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-2 rounded-lg p-3 ring-1 ring-border">
                <div className="flex items-center justify-between gap-2">
                    <p className="text-sm font-medium text-foreground">Bahan lain (di luar katalog)</p>
                    <Button type="button" variant="ghost" size="icon-sm" className="max-md:size-11" aria-label="Tutup form bahan custom" onClick={onClose}>
                        <X className="size-4" />
                    </Button>
                </div>
                <div className="grid gap-2 sm:grid-cols-2">
                    <FormField
                        control={form.control}
                        name="name"
                        render={({ field }) => (
                            <FormItem>
                                <FormLabel required>Nama bahan</FormLabel>
                                <FormControl>
                                    <Input {...field} id={nameId} autoComplete="off" placeholder="mis. Engsel sendok slow motion" className="max-md:h-11" />
                                </FormControl>
                                <FormMessage />
                            </FormItem>
                        )}
                    />
                    <FormField
                        control={form.control}
                        name="spec"
                        render={({ field }) => (
                            <FormItem>
                                <FormLabel>Spesifikasi</FormLabel>
                                <FormControl>
                                    <Input {...field} autoComplete="off" placeholder="Ukuran, merek, warna…" className="max-md:h-11" />
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
                                    <Input {...field} inputMode="decimal" placeholder="0" autoComplete="off" className="max-md:h-11" />
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
                                <FormLabel required>Satuan</FormLabel>
                                <FormControl>
                                    <UnitSelect value={field.value} onChange={field.onChange} units={units ?? []} className="w-full max-md:h-11" aria-label="Satuan" />
                                </FormControl>
                                <FormMessage />
                            </FormItem>
                        )}
                    />
                    <FormField
                        control={form.control}
                        name="photo_link"
                        render={({ field }) => (
                            <FormItem>
                                <FormLabel>Link foto / contoh</FormLabel>
                                <FormControl>
                                    <Input {...field} type="url" autoComplete="off" placeholder="https://…" className="max-md:h-11" />
                                </FormControl>
                                <FormMessage />
                            </FormItem>
                        )}
                    />
                    <FormField
                        control={form.control}
                        name="note"
                        render={({ field }) => (
                            <FormItem>
                                <FormLabel>Catatan untuk Logistik</FormLabel>
                                <FormControl>
                                    <Input {...field} autoComplete="off" placeholder="Untuk pekerjaan apa, kapan dibutuhkan…" className="max-md:h-11" />
                                </FormControl>
                                <FormMessage />
                            </FormItem>
                        )}
                    />
                </div>
                <div className="flex items-center justify-end gap-3">
                    {saved > 0 && <span className="text-xs text-success-ink">{saved} bahan tersimpan ✓</span>}
                    <Button type="submit" size="sm" className="max-md:h-11" disabled={busy}>
                        {busy ? <Loader2 className="size-4 animate-spin" /> : <Plus className="size-4" />}
                        Simpan &amp; tambah lagi
                    </Button>
                </div>
            </form>
        </Form>
    );
}
