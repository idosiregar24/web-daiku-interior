import { ConfirmDialog } from '@/Components/shared/ConfirmDialog';
import { SearchInput } from '@/Components/shared/SearchInput';
import { Button } from '@/Components/ui/button';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { quietSuccess } from '@/hooks/useFlashToasts';
import { formatQty } from '@/lib/format';
import { parseQty, quantityField } from '@/lib/quantity';
import { cn } from '@/lib/utils';
import type { Material, ProjectExecution } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { Check, Plus, Trash2, X } from 'lucide-react';
import { type KeyboardEvent, useId, useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

export type MaterialOption = Pick<Material, 'id' | 'code' | 'name' | 'unit_id' | 'unit' | 'stock'>;
type PlannedLine = ProjectExecution['meetingMaterials'][number];

const SOURCE_LABEL: Record<string, string> = { GUDANG: 'Gudang', PEMBELIAN: 'Beli', CUSTOM: 'Custom' };

// Mirrors StoreProjectMaterialRequest (vendor stays optional — set it later on the Material tab).
const schema = z.object({
    material_id: z.string().min(1, 'Pilih barang dari katalog.'),
    qty_planned: quantityField('Jumlah'),
    source: z.enum(['GUDANG', 'PEMBELIAN']),
});

type Values = z.infer<typeof schema>;

const VISIT = { preserveState: true, preserveScroll: true, ...quietSuccess };

/**
 * Sprint 24 — "Bahan yang digunakan" planned right in the TM card: search
 * the catalog, type the amount, Enter — no dialog, no trip to the Material
 * tab. Amounts are edited in place; the Pengawas may drop a line until
 * production starts. Same routes & policies as the Material tab
 * (projects.materials.store, project-materials.update / destroy).
 */
export function TmMaterialPlanner({
    projectId,
    lines,
    options,
    canUpdate,
    canRemove,
}: {
    projectId: number;
    lines: PlannedLine[];
    options: MaterialOption[];
    canUpdate: boolean;
    canRemove: boolean;
}) {
    const [term, setTerm] = useState('');
    const [listOpen, setListOpen] = useState(false);
    const [active, setActive] = useState(0);
    // Input doesn't forward refs (React 18) — focus by id.
    const searchId = useId();
    const qtyId = useId();
    // By a data attribute: FormControl gives the qty input its own id (for the label).
    const focus = (key: string) => requestAnimationFrame(() => document.querySelector<HTMLInputElement>(`[data-focus-key="${key}"] input, input[data-focus-key="${key}"]`)?.focus());
    const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { material_id: '', qty_planned: '', source: 'GUDANG' } });

    const materialId = form.watch('material_id');
    const source = form.watch('source');
    const picked = options.find((option) => String(option.id) === materialId) ?? null;
    const existing = picked ? lines.find((line) => line.material_id === picked.id && line.source === source) : undefined;
    const plannedIds = useMemo(() => new Set(lines.map((line) => line.material_id)), [lines]);

    const matches = useMemo(() => {
        const needle = term.trim().toLowerCase();

        return (needle ? options.filter((option) => `${option.code} ${option.name}`.toLowerCase().includes(needle)) : options).slice(0, 8);
    }, [term, options]);

    function pick(option: MaterialOption) {
        form.setValue('material_id', String(option.id), { shouldValidate: true });
        // Something in the warehouse → take it from there; otherwise it has to be bought.
        form.setValue('source', Number(option.stock) > 0 ? 'GUDANG' : 'PEMBELIAN');
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
        router.post(
            route('projects.materials.store', { project: projectId }),
            { material_id: Number(values.material_id), source: values.source, qty_planned: parseQty(values.qty_planned), vendor_id: null },
            {
                ...VISIT,
                onSuccess: () => {
                    form.reset({ material_id: '', qty_planned: '', source: 'GUDANG' });
                    focus(searchId);
                },
                onError: (errors) => Object.entries(errors).forEach(([key, message]) => form.setError(key as keyof Values, { message })),
            },
        );
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
                                        <span className="shrink-0 text-xs text-daiku-muted">
                                            stok {formatQty(picked.stock)} {picked.unit?.code}
                                        </span>
                                        <Button type="button" variant="ghost" size="icon-sm" aria-label="Ganti barang" onClick={clearPick}>
                                            <X className="size-4" />
                                        </Button>
                                    </div>
                                ) : (
                                    <div className="relative">
                                        <SearchInput
                                            data-focus-key={searchId}
                                            value={term}
                                            onChange={(event) => {
                                                setTerm(event.target.value);
                                                setActive(0);
                                                setListOpen(true);
                                            }}
                                            onFocus={() => setListOpen(true)}
                                            onBlur={() => setTimeout(() => setListOpen(false), 150)}
                                            onKeyDown={onSearchKey}
                                            placeholder="Ketik nama atau kode barang…"
                                            aria-label="Cari barang katalog"
                                            autoComplete="off"
                                        />
                                        {listOpen && (
                                            <ul className="absolute inset-x-0 top-full z-20 mt-1 max-h-64 overflow-y-auto rounded-lg bg-popover py-1 shadow-md ring-1 ring-border">
                                                {matches.length === 0 ? (
                                                    <li className="px-3 py-2 text-sm text-daiku-muted">
                                                        Tidak ada di katalog — ajukan lewat tab Material (Ajukan Barang).
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
                                                                    'flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm',
                                                                    index === active && 'bg-daiku-yellow-light',
                                                                )}
                                                            >
                                                                <span className="min-w-0 truncate">
                                                                    <span className="font-medium">{option.code}</span> {option.name}
                                                                </span>
                                                                <span className="flex shrink-0 items-center gap-1 text-xs text-daiku-muted">
                                                                    {plannedIds.has(option.id) && <Check className="size-3.5 text-success-ink" aria-label="Sudah direncanakan" />}
                                                                    stok {formatQty(option.stock)} {option.unit?.code}
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
                    <div className="grid grid-cols-[1fr_1fr_auto] items-start gap-2">
                        <FormField
                            control={form.control}
                            name="qty_planned"
                            render={({ field }) => (
                                <FormItem data-focus-key={qtyId}>
                                    <FormLabel required>Jumlah{picked?.unit?.code ? ` (${picked.unit.code})` : ''}</FormLabel>
                                    <FormControl>
                                        <Input {...field} inputMode="decimal" placeholder="0" autoComplete="off" />
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
                                            <SelectTrigger className="w-full">
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
                        <Button type="submit" className="mt-[1.375rem]" disabled={form.formState.isSubmitting}>
                            <Plus className="size-4" />
                            Tambah
                        </Button>
                    </div>
                    {existing && (
                        <p className="text-xs text-warning-ink">
                            Sudah direncanakan {existing.qty} ({SOURCE_LABEL[existing.source]}) — jumlahnya akan diganti.
                        </p>
                    )}
                </form>
            </Form>

            {lines.length === 0 ? (
                <p className="rounded-lg border border-dashed border-border px-3 py-6 text-center text-sm text-muted-foreground">Belum ada bahan.</p>
            ) : (
                <ul className="max-h-80 divide-y divide-border overflow-y-auto rounded-lg ring-1 ring-border">
                    {lines.map((line) => (
                        <PlannedRow
                            // Re-seed the local amount whenever the server's changes.
                            key={`${line.id}-${line.qty_planned}`}
                            line={line}
                            canUpdate={canUpdate && line.editable}
                            canRemove={canRemove && line.editable}
                        />
                    ))}
                </ul>
            )}
        </div>
    );
}

function PlannedRow({ line, canUpdate, canRemove }: { line: PlannedLine; canUpdate: boolean; canRemove: boolean }) {
    const [value, setValue] = useState(String(line.qty_planned).replace('.', ','));
    const [error, setError] = useState<string | null>(null);
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
                <p className="text-xs text-muted-foreground">{SOURCE_LABEL[line.source]}</p>
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
                        className="h-8 w-20 text-right tabular-nums"
                    />
                    <span className="w-10 text-xs text-muted-foreground">{line.unit}</span>
                </div>
            ) : (
                <span className="shrink-0 text-muted-foreground tabular-nums">{line.qty}</span>
            )}
            {canRemove && (
                <>
                    <Button type="button" variant="ghost" size="icon-sm" aria-label={`Hapus ${line.name}`} onClick={() => setConfirming(true)}>
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
