import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import { SearchInput } from '@/Components/shared/SearchInput';
import { VendorSelect } from '@/Components/shared/VendorSelect';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogClose, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { formatQty } from '@/lib/format';
import { type CatalogMaterial, projectVisit, RELOAD, useProjectCatalog } from '@/lib/projectReload';
import { parseQty, quantityField } from '@/lib/quantity';
import { cn } from '@/lib/utils';
import type { ProjectMaterial, VendorOption } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { Check, Loader2, PackagePlus, Pencil, Plus, X } from 'lucide-react';
import { type KeyboardEvent, useEffect, useId, useMemo, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

type PlanSource = 'GUDANG' | 'PEMBELIAN';

/** Mirrors StoreProjectMaterialsRequest::MAX_LINES. */
const MAX_LINES = 50;

const SOURCE_LABEL: Record<PlanSource, string> = { GUDANG: 'Gudang', PEMBELIAN: 'Pembelian' };

// One line of StoreProjectMaterialsRequest (`lines.*`).
const lineSchema = z.object({
    material_id: z.string().min(1, 'Material wajib dipilih'),
    source: z.enum(['GUDANG', 'PEMBELIAN']),
    qty_planned: quantityField('Jumlah kebutuhan'),
    vendor_id: z.string().optional(),
});

type LineValues = z.infer<typeof lineSchema>;

const EMPTY: LineValues = { material_id: '', source: 'GUDANG', qty_planned: '', vendor_id: '' };

/** A line in the dialog's list — not sent yet. */
interface DraftLine {
    key: string;
    material: CatalogMaterial;
    source: PlanSource;
    qty: string;
    vendor_id: string;
}

/** A line on its way to the server — the panel shows it in its table meanwhile (K3). */
export interface PendingMaterialLine {
    key: string;
    material_id: number;
    name: string;
    unit: string;
    source: PlanSource;
    qty_planned: number;
}

interface ProjectMaterialBatchDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    projectId: number;
    /** The project's lines — "already planned, the amount is replaced" hint. */
    items: ProjectMaterial[];
    vendors: VendorOption[];
    /** May raise an out-of-catalog request (offered from the search). */
    canRequest: boolean;
    onRequest: (term: string) => void;
    /** "Mungkin maksud Anda" sent the user back with this catalog item; consumed once picked. */
    pickedMaterialId: string | null;
    onPickConsumed: () => void;
    onPendingChange: (lines: PendingMaterialLine[]) => void;
}

const focusKey = (key: string) =>
    requestAnimationFrame(() => document.querySelector<HTMLInputElement>(`[data-focus-key="${key}"] input, input[data-focus-key="${key}"]`)?.focus());

/**
 * Sprint 29 Sub 01 (L1/K4/K5) — "Tambah Kebutuhan" in several lines:
 * search the catalog → amount → Enter adds the line to the list here (not
 * sent yet); lines can be changed or dropped; "Simpan semua" sends them in
 * one request (projects.materials.storeMany — one refused line saves none).
 * The list survives closing the dialog until it is saved; the dialog stays
 * open after a save so the next items can follow.
 */
export function ProjectMaterialBatchDialog({
    open,
    onOpenChange,
    projectId,
    items,
    vendors,
    canRequest,
    onRequest,
    pickedMaterialId,
    onPickConsumed,
    onPendingChange,
}: ProjectMaterialBatchDialogProps) {
    const catalog = useProjectCatalog();
    const [term, setTerm] = useState('');
    const [active, setActive] = useState(0);
    const [lines, setLines] = useState<DraftLine[]>([]);
    const [editingKey, setEditingKey] = useState<string | null>(null);
    const [lineErrors, setLineErrors] = useState<Record<number, string[]>>({});
    const [listError, setListError] = useState<string | null>(null);
    const [savedCount, setSavedCount] = useState<number | null>(null);
    const [busy, setBusy] = useState(false);
    const nextKey = useRef(0);
    const searchKey = useId();
    const qtyKey = useId();

    const form = useForm<LineValues>({ resolver: zodResolver(lineSchema), defaultValues: EMPTY });
    const [materialId, source] = form.watch(['material_id', 'source']);
    const picked = catalog.materialOptions.find((material) => String(material.id) === materialId) ?? null;

    useEffect(() => {
        if (open) {
            catalog.load();
            setSavedCount(null);
        }
    }, [open]);

    // "Mungkin maksud Anda" in the request dialog → plan that catalog item here.
    useEffect(() => {
        if (!open || !pickedMaterialId || !catalog.loaded) return;

        const material = catalog.materialOptions.find((option) => String(option.id) === pickedMaterialId);
        if (material) pick(material);
        onPickConsumed();
    }, [open, pickedMaterialId, catalog.loaded]);

    const matches = useMemo(() => {
        const needle = term.trim().toLowerCase();
        const found = needle
            ? catalog.materialOptions.filter((material) => `${material.code} ${material.name}`.toLowerCase().includes(needle))
            : catalog.materialOptions;

        return found.slice(0, 30);
    }, [term, catalog.materialOptions]);

    // The server keeps one catalog line per material + source — saving it again replaces the amount.
    const alreadyPlanned = picked
        ? items.find((item) => item.material_id === picked.id && item.source === source && item.request_channel === null)
        : undefined;
    const alreadyListed = picked ? lines.find((line) => line.material.id === picked.id && line.source === source && line.key !== editingKey) : undefined;

    function pick(material: CatalogMaterial) {
        form.setValue('material_id', String(material.id), { shouldValidate: true });
        // Something in the warehouse → take it from there; otherwise it has to be bought.
        form.setValue('source', Number(material.stock) > 0 ? 'GUDANG' : 'PEMBELIAN');
        setTerm('');
        setActive(0);
        focusKey(qtyKey);
    }

    function clearEntry() {
        form.reset(EMPTY);
        setEditingKey(null);
        setTerm('');
        focusKey(searchKey);
    }

    function onSearchKey(event: KeyboardEvent<HTMLInputElement>) {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive((index) => Math.min(index + 1, matches.length - 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((index) => Math.max(index - 1, 0));
        } else if (event.key === 'Enter') {
            event.preventDefault();
            if (matches[active]) pick(matches[active]);
        }
    }

    /** Puts the entry into the list: replaces the line being edited, or the same material + source. */
    function withEntry(list: DraftLine[], values: LineValues): DraftLine[] | null {
        const material = catalog.materialOptions.find((option) => String(option.id) === values.material_id);
        if (!material) return null;

        const same = (line: DraftLine) => line.key === editingKey || (line.material.id === material.id && line.source === values.source);
        const index = list.findIndex(same);
        const line: DraftLine = {
            key: index >= 0 ? list[index].key : `draft-${nextKey.current++}`,
            material,
            source: values.source,
            qty: values.qty_planned.trim(),
            vendor_id: values.source === 'PEMBELIAN' ? (values.vendor_id ?? '') : '',
        };

        if (index === -1) return [...list, line];

        const rest = list.filter((row) => !same(row));
        rest.splice(Math.min(index, rest.length), 0, line);

        return rest;
    }

    function setList(next: DraftLine[]) {
        setLines(next);
        setLineErrors({});
        setListError(null);
        setSavedCount(null);
    }

    function addLine(values: LineValues) {
        const next = withEntry(lines, values);
        if (!next) return;

        if (next.length > MAX_LINES) {
            setListError(`Maksimal ${MAX_LINES} bahan sekali simpan — simpan dulu daftar ini.`);
            return;
        }

        setList(next);
        clearEntry();
    }

    function editLine(line: DraftLine) {
        setEditingKey(line.key);
        form.reset({ material_id: String(line.material.id), source: line.source, qty_planned: line.qty, vendor_id: line.vendor_id });
        focusKey(qtyKey);
    }

    function removeLine(line: DraftLine) {
        setList(lines.filter((row) => row.key !== line.key));
        if (editingKey === line.key) clearEntry();
    }

    function saveAll() {
        // A picked item not yet added to the list comes along.
        if (materialId) {
            form.handleSubmit((values) => {
                const next = withEntry(lines, values);
                if (!next) return;
                setList(next);
                clearEntry();
                send(next);
            })();
            return;
        }

        send(lines);
    }

    function send(list: DraftLine[]) {
        if (list.length === 0) {
            setListError('Tambahkan minimal satu bahan.');
            return;
        }
        if (list.length > MAX_LINES) {
            setListError(`Maksimal ${MAX_LINES} bahan sekali simpan.`);
            return;
        }

        router.post(
            route('projects.materials.storeMany', { project: projectId }),
            {
                lines: list.map((line) => ({
                    material_id: line.material.id,
                    source: line.source,
                    qty_planned: parseQty(line.qty),
                    vendor_id: line.source === 'PEMBELIAN' && line.vendor_id ? Number(line.vendor_id) : null,
                })),
            },
            {
                ...projectVisit(RELOAD.materials, { quiet: true }),
                onStart: () => {
                    setBusy(true);
                    onPendingChange(
                        list.map((line) => ({
                            key: line.key,
                            material_id: line.material.id,
                            name: line.material.name,
                            unit: line.material.unit?.code ?? '',
                            source: line.source,
                            qty_planned: parseQty(line.qty),
                        })),
                    );
                },
                onSuccess: (page) => {
                    // A flash error (no validation bag) means nothing was saved — keep the list.
                    if ((page.props as { flash?: { error?: string | null } }).flash?.error) return;
                    setLines([]);
                    setSavedCount(list.length);
                    focusKey(searchKey);
                },
                onError: (errors: Record<string, string>) => {
                    const byLine: Record<number, string[]> = {};
                    let general: string | null = null;

                    Object.entries(errors).forEach(([field, message]) => {
                        const match = field.match(/^lines\.(\d+)\./);
                        if (match) {
                            (byLine[Number(match[1])] ??= []).push(message);
                        } else {
                            general = message;
                        }
                    });
                    setLineErrors(byLine);
                    setListError(general);
                },
                onFinish: () => {
                    setBusy(false);
                    onPendingChange([]);
                },
            },
        );
    }

    const entryCount = lines.length + (materialId && !editingKey && !alreadyListed ? 1 : 0);
    const touchInput = 'max-md:h-11';

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <ResponsiveDialogContent
                className="max-h-[90vh] overflow-y-auto sm:max-w-xl"
                onOpenAutoFocus={(event) => {
                    event.preventDefault();
                    focusKey(searchKey);
                }}
            >
                <DialogHeader>
                    <DialogTitle>Tambah Kebutuhan Material</DialogTitle>
                    <DialogDescription>Cari barang, isi jumlah, tekan Enter untuk menambah ke daftar. Simpan semua sekaligus.</DialogDescription>
                </DialogHeader>

                <Form {...form}>
                    <form onSubmit={form.handleSubmit(addLine)} className="space-y-3 rounded-lg bg-daiku-gray/60 p-3">
                        <FormField
                            control={form.control}
                            name="material_id"
                            render={() => (
                                <FormItem>
                                    <FormLabel required>Material (katalog)</FormLabel>
                                    {picked ? (
                                        <div className="flex items-center gap-2 rounded-md bg-background px-3 py-2 text-sm ring-1 ring-daiku-yellow">
                                            <span className="min-w-0 flex-1 truncate">
                                                <span className="font-medium">{picked.code}</span> {picked.name}
                                            </span>
                                            <span className="shrink-0 text-xs text-daiku-muted">
                                                stok {formatQty(picked.stock)} {picked.unit?.code}
                                            </span>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon-sm"
                                                className="max-md:size-11"
                                                aria-label="Ganti barang"
                                                onClick={() => {
                                                    form.setValue('material_id', '');
                                                    focusKey(searchKey);
                                                }}
                                            >
                                                <X className="size-4" />
                                            </Button>
                                        </div>
                                    ) : (
                                        <div className="space-y-2">
                                            <SearchInput
                                                data-focus-key={searchKey}
                                                className="max-md:[&_input]:h-11"
                                                value={term}
                                                onChange={(event) => {
                                                    setTerm(event.target.value);
                                                    setActive(0);
                                                }}
                                                onFocus={catalog.load}
                                                onKeyDown={onSearchKey}
                                                placeholder="Cari nama atau kode barang…"
                                                aria-label="Cari barang katalog"
                                                autoComplete="off"
                                            />
                                            <div className="max-h-52 space-y-1 overflow-y-auto">
                                                {!catalog.loaded ? (
                                                    <p className="flex items-center gap-2 px-1 py-2 text-sm text-daiku-muted">
                                                        <Loader2 className="size-4 animate-spin" />
                                                        Memuat katalog…
                                                    </p>
                                                ) : (
                                                    matches.map((material, index) => (
                                                        <button
                                                            key={material.id}
                                                            type="button"
                                                            onClick={() => pick(material)}
                                                            onMouseEnter={() => setActive(index)}
                                                            className={cn(
                                                                'flex w-full items-center justify-between gap-2 rounded-md border px-3 py-2 text-left text-sm max-md:min-h-11',
                                                                index === active
                                                                    ? 'border-daiku-yellow bg-daiku-yellow-light'
                                                                    : 'border-border bg-background hover:bg-daiku-gray/60',
                                                            )}
                                                        >
                                                            <span className="min-w-0 truncate">
                                                                <span className="font-medium">{material.code}</span> {material.name}
                                                            </span>
                                                            <span className="shrink-0 text-xs text-daiku-muted">
                                                                stok {formatQty(material.stock)} {material.unit?.code}
                                                            </span>
                                                        </button>
                                                    ))
                                                )}
                                                {catalog.loaded && term.trim() !== '' && matches.length === 0 && (
                                                    <p className="px-1 text-sm text-daiku-muted">Tidak ada barang katalog yang cocok.</p>
                                                )}
                                            </div>
                                            {canRequest && term.trim().length >= 2 && (
                                                <Button type="button" variant="link" className="h-auto px-0" onClick={() => onRequest(term.trim())}>
                                                    <PackagePlus className="size-4" />
                                                    Ajukan barang (tidak ada di katalog)
                                                </Button>
                                            )}
                                        </div>
                                    )}
                                    <FormMessage />
                                </FormItem>
                            )}
                        />

                        <div className="grid grid-cols-2 items-start gap-3">
                            <FormField
                                control={form.control}
                                name="qty_planned"
                                render={({ field }) => (
                                    <FormItem data-focus-key={qtyKey}>
                                        <FormLabel required>Jumlah{picked?.unit?.code ? ` (${picked.unit.code})` : ''}</FormLabel>
                                        <FormControl>
                                            <Input {...field} inputMode="decimal" placeholder="0" autoComplete="off" className={touchInput} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="source"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Sumber</FormLabel>
                                        <Select value={field.value} onValueChange={field.onChange}>
                                            <FormControl>
                                                <SelectTrigger className={cn('w-full', touchInput)}>
                                                    <SelectValue />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                <SelectItem value="GUDANG">Gudang — stok Logistik</SelectItem>
                                                <SelectItem value="PEMBELIAN">Pembelian — dibeli khusus</SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            {source === 'PEMBELIAN' && (
                                <FormField
                                    control={form.control}
                                    name="vendor_id"
                                    render={({ field }) => (
                                        <FormItem className="col-span-2">
                                            <FormLabel>Vendor</FormLabel>
                                            <FormControl>
                                                <VendorSelect
                                                    value={field.value ?? ''}
                                                    onChange={field.onChange}
                                                    vendors={vendors}
                                                    allowEmpty
                                                    className={cn('w-full', touchInput)}
                                                />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                            )}
                        </div>

                        {(alreadyListed || alreadyPlanned) && (
                            <p className="text-xs text-warning-ink">
                                {alreadyListed
                                    ? `Sudah ada di daftar (${formatQty(parseQty(alreadyListed.qty))} ${picked?.unit?.code ?? ''}) — jumlahnya akan diganti.`
                                    : `Sudah direncanakan ${formatQty(alreadyPlanned!.qty_planned)} ${alreadyPlanned!.unit?.code ?? ''} (${SOURCE_LABEL[source]}) — jumlahnya akan diganti.`}
                            </p>
                        )}

                        <div className="flex flex-wrap justify-end gap-2">
                            {editingKey && (
                                <Button type="button" variant="ghost" className="max-md:h-11" onClick={clearEntry}>
                                    Batal ubah
                                </Button>
                            )}
                            <Button type="submit" variant="outline" className="max-md:h-11 max-md:flex-1" disabled={!materialId}>
                                {editingKey ? <Check className="size-4" /> : <Plus className="size-4" />}
                                {editingKey ? 'Perbarui baris' : 'Tambah ke daftar'}
                            </Button>
                        </div>
                    </form>
                </Form>

                <section className="space-y-2">
                    <h3 className="text-sm font-semibold text-daiku-dark">
                        Daftar <span className="font-normal text-daiku-muted">({lines.length})</span>
                    </h3>
                    {lines.length === 0 ? (
                        <p className="rounded-lg border border-dashed border-border px-3 py-4 text-center text-sm text-daiku-muted">
                            Belum ada bahan di daftar.
                        </p>
                    ) : (
                        <ul className="max-h-64 divide-y divide-border overflow-y-auto rounded-lg ring-1 ring-border">
                            {lines.map((line, index) => {
                                const vendor = line.vendor_id ? vendors.find((row) => String(row.id) === line.vendor_id)?.name : null;
                                const errors = lineErrors[index];

                                return (
                                    <li key={line.key} className={cn('flex items-start gap-2 px-3 py-2 text-sm', line.key === editingKey && 'bg-daiku-yellow-light')}>
                                        <div className="min-w-0 flex-1">
                                            <p className="truncate font-medium text-daiku-dark">{line.material.name}</p>
                                            <p className="text-xs text-daiku-muted">
                                                {[`${formatQty(parseQty(line.qty))} ${line.material.unit?.code ?? ''}`, SOURCE_LABEL[line.source], vendor]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                                {line.key === editingKey && ' · sedang diubah'}
                                            </p>
                                            {errors?.map((message) => (
                                                <p key={message} className="text-xs text-error-ink">
                                                    {message}
                                                </p>
                                            ))}
                                        </div>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            className="max-md:size-11"
                                            aria-label={`Ubah ${line.material.name}`}
                                            disabled={busy}
                                            onClick={() => editLine(line)}
                                        >
                                            <Pencil className="size-4" />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            className="max-md:size-11"
                                            aria-label={`Hapus ${line.material.name} dari daftar`}
                                            disabled={busy}
                                            onClick={() => removeLine(line)}
                                        >
                                            <X className="size-4 text-error-ink" />
                                        </Button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                    {listError && <p className="text-sm text-error-ink">{listError}</p>}
                    {savedCount !== null && (
                        <p className="flex items-center gap-1.5 text-sm font-medium text-success-ink" role="status">
                            {savedCount} bahan tersimpan
                            <Check className="size-4" />
                        </p>
                    )}
                </section>

                <DialogFooter>
                    <DialogClose asChild>
                        <Button type="button" variant="outline" className="max-md:h-11">
                            Tutup
                        </Button>
                    </DialogClose>
                    <Button type="button" className="max-md:h-11" disabled={busy || entryCount === 0} onClick={saveAll}>
                        {busy && <Loader2 className="size-4 animate-spin" />}
                        {busy ? 'Menyimpan…' : `Simpan semua (${entryCount})`}
                    </Button>
                </DialogFooter>
            </ResponsiveDialogContent>
        </Dialog>
    );
}
