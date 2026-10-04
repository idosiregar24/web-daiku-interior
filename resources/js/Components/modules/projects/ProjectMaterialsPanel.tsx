import { MaterialRequestDialog } from '@/Components/modules/logistics/MaterialRequestDialog';
import { PmRequestDecisionDialog } from '@/Components/modules/logistics/PmRequestDecisionDialog';
import { type MaterialAction, ProjectMaterialActionDialog } from '@/Components/modules/projects/ProjectMaterialActionDialog';
import { EmptyState } from '@/Components/shared/EmptyState';
import { Notice } from '@/Components/shared/Notice';
import { SearchInput } from '@/Components/shared/SearchInput';
import { StatusChip } from '@/Components/shared/StatusChip';
import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { VendorSelect } from '@/Components/shared/VendorSelect';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { formatQty, formatRupiah } from '@/lib/format';
import { parseQty, quantityField } from '@/lib/quantity';
import { cn } from '@/lib/utils';
import type { Material, MaterialCategory, Project, ProjectMaterial, ProjectMaterialSource, UnitOption, VendorOption } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Link, router } from '@inertiajs/react';
import {
    ArrowDownToLine,
    ArrowUpFromLine,
    Check,
    Gift,
    Hammer,
    Lock,
    MoreHorizontal,
    PackagePlus,
    Pencil,
    Plus,
    ShoppingCart,
    Trash2,
    Undo2,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

/** Which lifecycle actions the tab offers (ProjectController::show(); re-checked server-side). */
export interface MaterialPermissions {
    create: boolean;
    update: boolean;
    delete: boolean;
    /** Record purchases and usage — Logistics, or the project's own PM. */
    receive: boolean;
    /** Issue from the warehouse — Logistics. */
    issue: boolean;
    /** Waste / hand over to the client — Logistics, or the project's own PM. */
    settle: boolean;
    /** Return to the warehouse — Logistics. */
    return: boolean;
    /** Sub 4 — raise an out-of-catalog request (Estimator, the project's PM). */
    request: boolean;
    /** Sub 4 — approve/reject a Tukang's request (the project's PM). */
    pmDecide: boolean;
    /** Sub 4 — Logistics decides requests on the Pengajuan Barang page. */
    review: boolean;
}

interface ProjectMaterialsPanelProps {
    project: Project;
    items: ProjectMaterial[];
    canView: boolean;
    permissions: MaterialPermissions;
    materialOptions: Pick<Material, 'id' | 'code' | 'name' | 'unit_id' | 'unit' | 'stock' | 'cost_price'>[];
    catalogOptions: Pick<Material, 'id' | 'name' | 'unit_id'>[];
    vendors: VendorOption[];
    units: UnitOption[];
    /** Material categories — registering a custom leftover as a catalog item (Logistics). */
    materialCategories: Pick<MaterialCategory, 'id' | 'name' | 'code_prefix'>[];
}

const SOURCES: { value: ProjectMaterialSource; label: string; hint: string }[] = [
    { value: 'GUDANG', label: 'Gudang', hint: 'Diambil dari stok Logistik — dibebankan harga gudang.' },
    { value: 'PEMBELIAN', label: 'Pembelian', hint: 'Barang katalog yang dibeli khusus untuk proyek — harga beli aktual.' },
    { value: 'CUSTOM', label: 'Custom', hint: 'Barang khusus proyek di luar katalog — disetujui Logistik.' },
];

// Mirrors StoreProjectMaterialRequest / UpdateProjectMaterialRequest.
const planSchema = z.object({
    material_id: z.string().min(1, 'Material wajib dipilih'),
    source: z.enum(['GUDANG', 'PEMBELIAN']),
    qty_planned: quantityField('Jumlah kebutuhan', 0),
    vendor_id: z.string().optional(),
});

type PlanValues = z.infer<typeof planSchema>;

/**
 * PRD §4.8 "Kebutuhan Material Proyek", reshaped by Sprint 11 Sub 3: one
 * table per source (Gudang · Pembelian · Custom), each line's received /
 * used / leftover, the leftover settled by return, waste or hand-over,
 * and the cost the project is charged per source. A project can't be
 * COMPLETED while any leftover remains (decision #8).
 */
export function ProjectMaterialsPanel({
    project,
    items,
    canView,
    permissions,
    materialOptions,
    catalogOptions,
    vendors,
    units,
    materialCategories,
}: ProjectMaterialsPanelProps) {
    const [planOpen, setPlanOpen] = useState(false);
    const [editing, setEditing] = useState<ProjectMaterial | null>(null);
    const [action, setAction] = useState<{ line: ProjectMaterial; action: MaterialAction } | null>(null);
    const [requestOpen, setRequestOpen] = useState(false);
    const [catalogSearch, setCatalogSearch] = useState('');
    // Set when "Mungkin maksud Anda" sends the user back from a request to planning.
    const [pickedMaterialId, setPickedMaterialId] = useState<string | null>(null);
    const [pmDecision, setPmDecision] = useState<{ line: ProjectMaterial; decision: 'approve' | 'reject' } | null>(null);

    const form = useForm<PlanValues>({
        resolver: zodResolver(planSchema),
        defaultValues: { material_id: '', source: 'GUDANG', qty_planned: '', vendor_id: '' },
    });
    const plannedSource = form.watch('source');

    useEffect(() => {
        if (planOpen) {
            form.reset(
                editing
                    ? {
                          material_id: String(editing.material_id ?? ''),
                          source: editing.source === 'PEMBELIAN' ? 'PEMBELIAN' : 'GUDANG',
                          qty_planned: String(editing.qty_planned),
                          vendor_id: editing.vendor_id ? String(editing.vendor_id) : '',
                      }
                    : { material_id: pickedMaterialId ?? '', source: 'GUDANG', qty_planned: '', vendor_id: '' },
            );
        }
    }, [planOpen, editing]);

    // §5.5 Lapis 4 — the planning form searches the catalog (name or code) before anything is requested.
    const catalogMatches = useMemo(() => {
        const term = catalogSearch.trim().toLowerCase();
        const matches = term
            ? materialOptions.filter((material) => `${material.code} ${material.name}`.toLowerCase().includes(term))
            : materialOptions;

        return matches.slice(0, 30);
    }, [catalogSearch, materialOptions]);

    // Requests nobody approved yet (or rejected) live in their own section, outside the cost tables.
    const approvedItems = useMemo(() => items.filter((item) => item.request_status === 'DISETUJUI'), [items]);
    const requestItems = items.filter((item) => item.request_status !== 'DISETUJUI');
    const pendingRequests = requestItems.filter((item) => item.request_status === 'MENUNGGU_PM' || item.request_status === 'DIAJUKAN');

    const groups = useMemo(
        () =>
            SOURCES.map((source) => {
                const lines = approvedItems.filter((item) => item.source === source.value);

                return { ...source, lines, cost: lines.reduce((sum, line) => sum + Number(line.cost_total), 0) };
            }),
        [approvedItems],
    );
    const leftovers = items.filter((item) => item.leftover > 0);
    const totalCost = groups.reduce((sum, group) => sum + group.cost, 0);

    if (!canView) {
        return (
            <EmptyState
                icon={Lock}
                className="rounded-xl border border-dashed border-border"
                title="Anda tidak memiliki akses ke kebutuhan material proyek."
            />
        );
    }

    function onPlanSubmit(values: PlanValues) {
        const options = {
            preserveScroll: true,
            onError: (errors: Record<string, string>) =>
                Object.entries(errors).forEach(([field, message]) => form.setError(field as keyof PlanValues, { message })),
            onSuccess: () => setPlanOpen(false),
        };
        const vendorId = values.source === 'PEMBELIAN' && values.vendor_id ? Number(values.vendor_id) : null;

        if (editing) {
            router.put(
                route('project-materials.update', { project_material: editing.id }),
                { qty_planned: parseQty(values.qty_planned), ...(editing.source !== 'GUDANG' ? { vendor_id: vendorId } : {}) },
                options,
            );
        } else {
            router.post(
                route('projects.materials.store', { project: project.id }),
                {
                    material_id: Number(values.material_id),
                    source: values.source,
                    qty_planned: parseQty(values.qty_planned),
                    vendor_id: vendorId,
                },
                options,
            );
        }
    }

    function remove(line: ProjectMaterial) {
        if (!confirm(`Hapus "${line.display_name}" dari kebutuhan proyek?`)) return;
        router.delete(route('project-materials.destroy', { project_material: line.id }), { preserveScroll: true });
    }

    function actionsFor(line: ProjectMaterial) {
        const approved = line.request_status === 'DISETUJUI';
        const hasLeftover = line.leftover > 0;

        return {
            issue: approved && permissions.issue && line.source === 'GUDANG',
            purchase: approved && permissions.receive && line.source !== 'GUDANG',
            usage: approved && permissions.receive && hasLeftover,
            return: approved && permissions.return && hasLeftover,
            waste: approved && permissions.settle && hasLeftover,
            handOver: approved && permissions.settle && hasLeftover,
            edit: permissions.update && line.source !== 'CUSTOM',
            delete: permissions.delete && line.qty_received === 0,
        };
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p className="inline-flex flex-wrap items-center gap-x-1 rounded-lg bg-daiku-gray/70 px-3 py-2 text-sm text-daiku-muted ring-1 ring-border ring-inset">
                    Biaya material: <span className="font-medium text-daiku-dark">{formatRupiah(totalCost)}</span>
                    {groups.map((group) => (
                        <span key={group.value}>
                            {' · '}
                            {group.label} <span className="font-medium text-daiku-dark">{formatRupiah(group.cost)}</span>
                        </span>
                    ))}
                </p>
                {/* §5.5 Lapis 4 — search the catalog first; requesting an item is offered from there. */}
                {permissions.create && (
                    <Button
                        size="sm"
                        onClick={() => {
                            setEditing(null);
                            setPickedMaterialId(null);
                            setCatalogSearch('');
                            setPlanOpen(true);
                        }}
                    >
                        <Plus className="size-4" />
                        Tambah Kebutuhan
                    </Button>
                )}
            </div>

            {(leftovers.length > 0 || pendingRequests.length > 0) && project.status !== 'COMPLETED' && (
                <Notice tone="warning">
                    Proyek belum bisa COMPLETED selama
                    {leftovers.length > 0 &&
                        ` masih ada sisa material: ${leftovers
                            .map((line) => `${line.display_name} (${formatQty(line.leftover)} ${line.unit?.code ?? ''})`)
                            .join(', ')} — bereskan lewat Retur ke Gudang, Susut, atau Serahkan ke Klien`}
                    {leftovers.length > 0 && pendingRequests.length > 0 && ';'}
                    {pendingRequests.length > 0 &&
                        ` masih ada pengajuan barang yang belum diputuskan: ${pendingRequests.map((line) => line.display_name).join(', ')}`}
                    .
                </Notice>
            )}

            {requestItems.length > 0 && (
                <section className="space-y-2">
                    <h3 className="text-sm font-semibold text-daiku-dark">
                        Pengajuan <span className="font-normal text-daiku-muted">· barang di luar katalog, menunggu keputusan PM/Logistik</span>
                    </h3>
                    <TableCard>
                        <table className="w-full text-sm">
                            <thead className={TABLE_HEAD_CLASS}>
                                <tr>
                                    <th className="px-4 py-2.5 text-left font-semibold">Barang</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Jumlah</th>
                                    <th className="px-4 py-2.5 text-left font-semibold">Pengaju</th>
                                    <th className="px-4 py-2.5 text-left font-semibold">Status</th>
                                    <th className="px-4 py-2.5" />
                                </tr>
                            </thead>
                            <tbody>
                                {requestItems.map((line) => (
                                    <tr key={line.id} className="border-t border-border align-top">
                                        <td className="px-4 py-3">
                                            <p className="font-medium text-daiku-dark">{line.display_name}</p>
                                            {line.request_reason && <p className="text-xs text-daiku-muted">“{line.request_reason}”</p>}
                                        </td>
                                        <td className="px-4 py-3 text-right tabular-nums">
                                            {formatQty(line.qty_planned)} {line.unit?.code ?? ''}
                                        </td>
                                        <td className="px-4 py-3">
                                            {line.requester?.name ?? '—'}
                                            <p className="text-xs text-daiku-muted">{line.request_channel === 'TUKANG' ? 'Tukang' : 'Tim'}</p>
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusChip
                                                status={line.request_status}
                                                label={
                                                    line.request_status === 'MENUNGGU_PM'
                                                        ? 'Menunggu PM'
                                                        : line.request_status === 'DIAJUKAN'
                                                          ? 'Menunggu Logistik'
                                                          : 'Ditolak'
                                                }
                                            />
                                            {line.reject_reason && <p className="mt-1 text-xs text-error-ink">{line.reject_reason}</p>}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex justify-end gap-1">
                                                {permissions.pmDecide && line.request_status === 'MENUNGGU_PM' && (
                                                    <>
                                                        <Button size="sm" onClick={() => setPmDecision({ line, decision: 'approve' })}>
                                                            <Check className="size-4" />
                                                            Setujui
                                                        </Button>
                                                        <Button size="sm" variant="outline" onClick={() => setPmDecision({ line, decision: 'reject' })}>
                                                            Tolak
                                                        </Button>
                                                    </>
                                                )}
                                                {permissions.review && line.request_status === 'DIAJUKAN' && (
                                                    <Button size="sm" variant="outline" asChild>
                                                        <Link href={route('logistics.material-requests.index', { project_id: project.id })}>Tinjau</Link>
                                                    </Button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </TableCard>
                </section>
            )}

            {approvedItems.length === 0 ? (
                <EmptyState className="rounded-xl border border-dashed border-border" title="Belum ada kebutuhan material untuk proyek ini." />
            ) : (
                groups
                    .filter((group) => group.lines.length > 0)
                    .map((group) => (
                        <section key={group.value} className="space-y-2">
                            <div className="flex flex-wrap items-baseline justify-between gap-2">
                                <h3 className="text-sm font-semibold text-daiku-dark">
                                    {group.label} <span className="font-normal text-daiku-muted">· {group.hint}</span>
                                </h3>
                                <span className="text-sm text-daiku-muted">
                                    Biaya <span className="font-medium text-daiku-dark">{formatRupiah(group.cost)}</span>
                                </span>
                            </div>
                            <TableCard>
                                <table className="w-full text-sm">
                                    <thead className={TABLE_HEAD_CLASS}>
                                        <tr>
                                            <th className="px-4 py-2.5 text-left font-semibold">Barang</th>
                                            <th className="px-4 py-2.5 text-right font-semibold">Rencana</th>
                                            <th className="px-4 py-2.5 text-right font-semibold">Diterima</th>
                                            <th className="px-4 py-2.5 text-right font-semibold">Terpakai</th>
                                            <th className="px-4 py-2.5 text-right font-semibold">Sisa</th>
                                            <th className="px-4 py-2.5 text-left font-semibold">Dibereskan</th>
                                            <th className="px-4 py-2.5 text-right font-semibold">Biaya</th>
                                            <th className="w-12 px-4 py-2.5" />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {group.lines.map((line) => {
                                            const unit = line.unit?.code ?? '';
                                            const allowed = actionsFor(line);
                                            const anyAction = Object.values(allowed).some(Boolean);
                                            const settled = [
                                                line.qty_returned > 0 && `retur ${formatQty(line.qty_returned)}`,
                                                line.qty_wasted > 0 && `susut ${formatQty(line.qty_wasted)}`,
                                                line.qty_handed_over > 0 && `ke klien ${formatQty(line.qty_handed_over)}`,
                                            ].filter(Boolean);

                                            return (
                                                <tr key={line.id} className="border-t border-border align-top transition-colors hover:bg-daiku-gray/60">
                                                    <td className="px-4 py-3">
                                                        <p className="font-medium text-daiku-dark">{line.display_name}</p>
                                                        <p className="text-xs text-daiku-muted">
                                                            {[
                                                                line.vendor?.name,
                                                                line.unit_price && line.source !== 'GUDANG' && `${formatRupiah(line.unit_price)}/${unit}`,
                                                                line.source === 'GUDANG' && line.material && `stok gudang ${formatQty(line.material.stock)} ${unit}`,
                                                            ]
                                                                .filter(Boolean)
                                                                .join(' · ')}
                                                        </p>
                                                        {line.request_status !== 'DISETUJUI' && (
                                                            <StatusChip
                                                                status={line.request_status}
                                                                label={line.request_status === 'DIAJUKAN' ? 'Menunggu Logistik' : 'Ditolak'}
                                                                className="mt-1"
                                                            />
                                                        )}
                                                    </td>
                                                    <td className="px-4 py-3 text-right tabular-nums text-daiku-muted">
                                                        {formatQty(line.qty_planned)} {unit}
                                                    </td>
                                                    <td className="px-4 py-3 text-right tabular-nums">{formatQty(line.qty_received)}</td>
                                                    <td
                                                        className={cn(
                                                            'px-4 py-3 text-right tabular-nums',
                                                            line.qty_planned > 0 && line.qty_used > line.qty_planned && 'font-medium text-error-ink',
                                                        )}
                                                    >
                                                        {formatQty(line.qty_used)}
                                                    </td>
                                                    <td
                                                        className={cn(
                                                            'px-4 py-3 text-right tabular-nums',
                                                            line.leftover > 0 ? 'font-medium text-warning-ink' : 'text-daiku-muted',
                                                        )}
                                                    >
                                                        {formatQty(line.leftover)} {unit}
                                                    </td>
                                                    <td className="px-4 py-3 text-xs text-daiku-muted">
                                                        {settled.length > 0 ? settled.join(' · ') : '—'}
                                                    </td>
                                                    <td className="px-4 py-3 text-right tabular-nums">{formatRupiah(line.cost_total)}</td>
                                                    <td className="px-4 py-3">
                                                        {anyAction && (
                                                            <DropdownMenu>
                                                                <DropdownMenuTrigger asChild>
                                                                    <Button variant="ghost" size="icon-sm" aria-label={`Aksi untuk ${line.display_name}`}>
                                                                        <MoreHorizontal className="size-4" />
                                                                    </Button>
                                                                </DropdownMenuTrigger>
                                                                <DropdownMenuContent align="end">
                                                                    {allowed.issue && (
                                                                        <DropdownMenuItem onClick={() => setAction({ line, action: 'issue' })}>
                                                                            <ArrowUpFromLine className="size-4" />
                                                                            Keluarkan dari Gudang
                                                                        </DropdownMenuItem>
                                                                    )}
                                                                    {allowed.purchase && (
                                                                        <DropdownMenuItem onClick={() => setAction({ line, action: 'purchase' })}>
                                                                            <ShoppingCart className="size-4" />
                                                                            Catat Pembelian
                                                                        </DropdownMenuItem>
                                                                    )}
                                                                    {allowed.usage && (
                                                                        <DropdownMenuItem onClick={() => setAction({ line, action: 'usage' })}>
                                                                            <Hammer className="size-4" />
                                                                            Catat Pemakaian
                                                                        </DropdownMenuItem>
                                                                    )}
                                                                    {(allowed.return || allowed.waste || allowed.handOver) && <DropdownMenuSeparator />}
                                                                    {allowed.return && (
                                                                        <DropdownMenuItem onClick={() => setAction({ line, action: 'return' })}>
                                                                            <Undo2 className="size-4" />
                                                                            Retur ke Gudang
                                                                        </DropdownMenuItem>
                                                                    )}
                                                                    {allowed.waste && (
                                                                        <DropdownMenuItem onClick={() => setAction({ line, action: 'waste' })}>
                                                                            <ArrowDownToLine className="size-4" />
                                                                            Catat Susut
                                                                        </DropdownMenuItem>
                                                                    )}
                                                                    {allowed.handOver && (
                                                                        <DropdownMenuItem onClick={() => setAction({ line, action: 'handOver' })}>
                                                                            <Gift className="size-4" />
                                                                            Serahkan ke Klien
                                                                        </DropdownMenuItem>
                                                                    )}
                                                                    {(allowed.edit || allowed.delete) && <DropdownMenuSeparator />}
                                                                    {allowed.edit && (
                                                                        <DropdownMenuItem
                                                                            onClick={() => {
                                                                                setEditing(line);
                                                                                setPlanOpen(true);
                                                                            }}
                                                                        >
                                                                            <Pencil className="size-4" />
                                                                            Ubah Rencana
                                                                        </DropdownMenuItem>
                                                                    )}
                                                                    {allowed.delete && (
                                                                        <DropdownMenuItem variant="destructive" onClick={() => remove(line)}>
                                                                            <Trash2 className="size-4" />
                                                                            Hapus
                                                                        </DropdownMenuItem>
                                                                    )}
                                                                </DropdownMenuContent>
                                                            </DropdownMenu>
                                                        )}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </TableCard>
                        </section>
                    ))
            )}

            <Dialog open={planOpen} onOpenChange={setPlanOpen}>
                <DialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>{editing ? 'Ubah Rencana Material' : 'Tambah Kebutuhan Material'}</DialogTitle>
                    </DialogHeader>
                    <Form {...form}>
                        <form onSubmit={form.handleSubmit(onPlanSubmit)} className="space-y-4">
                            <FormField
                                control={form.control}
                                name="material_id"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Material (katalog)</FormLabel>
                                        {editing ? (
                                            <p className="rounded-md bg-daiku-gray px-3 py-2 text-sm">{editing.display_name}</p>
                                        ) : (
                                            <div className="space-y-2">
                                                <SearchInput
                                                    value={catalogSearch}
                                                    onChange={(event) => setCatalogSearch(event.target.value)}
                                                    placeholder="Cari nama atau kode barang…"
                                                    aria-label="Cari barang katalog"
                                                />
                                                <div className="max-h-56 space-y-1 overflow-y-auto">
                                                    {catalogMatches.map((material) => (
                                                        <button
                                                            key={material.id}
                                                            type="button"
                                                            onClick={() => field.onChange(String(material.id))}
                                                            className={cn(
                                                                'flex w-full items-center justify-between gap-2 rounded-md border px-3 py-2 text-left text-sm',
                                                                field.value === String(material.id)
                                                                    ? 'border-daiku-yellow bg-daiku-yellow-light'
                                                                    : 'border-border hover:bg-daiku-gray/60',
                                                            )}
                                                        >
                                                            <span>
                                                                <span className="font-medium">{material.code}</span> {material.name}
                                                            </span>
                                                            <span className="shrink-0 text-xs text-daiku-muted">
                                                                stok {formatQty(material.stock)} {material.unit?.code}
                                                            </span>
                                                        </button>
                                                    ))}
                                                    {catalogSearch.trim() !== '' && catalogMatches.length === 0 && (
                                                        <p className="px-1 text-sm text-daiku-muted">Tidak ada barang katalog yang cocok.</p>
                                                    )}
                                                </div>
                                                {permissions.request && catalogSearch.trim().length >= 2 && (
                                                    <Button
                                                        type="button"
                                                        variant="link"
                                                        className="h-auto px-0"
                                                        onClick={() => {
                                                            setPlanOpen(false);
                                                            setRequestOpen(true);
                                                        }}
                                                    >
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
                            <FormField
                                control={form.control}
                                name="source"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Sumber</FormLabel>
                                        <Select value={field.value} onValueChange={field.onChange} disabled={editing !== null}>
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                <SelectItem value="GUDANG">Gudang — ambil dari stok Logistik</SelectItem>
                                                <SelectItem value="PEMBELIAN">Pembelian — dibeli khusus proyek</SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="qty_planned"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Jumlah Kebutuhan</FormLabel>
                                        <FormControl>
                                            <Input type="number" min="0" step="0.01" inputMode="decimal" {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            {plannedSource === 'PEMBELIAN' && (
                                <FormField
                                    control={form.control}
                                    name="vendor_id"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Vendor (opsional)</FormLabel>
                                            <FormControl>
                                                <VendorSelect value={field.value ?? ''} onChange={field.onChange} vendors={vendors} allowEmpty />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                            )}
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

            <MaterialRequestDialog
                open={requestOpen}
                onOpenChange={setRequestOpen}
                projects={[project]}
                fixedProjectId={project.id}
                minimal={false}
                units={units}
                vendors={vendors}
                initialName={catalogSearch.trim()}
                catalogHints={permissions.create ? materialOptions : []}
                onPickCatalog={(materialId) => {
                    // "Mungkin maksud Anda" → plan that catalog item instead of requesting it.
                    setRequestOpen(false);
                    setEditing(null);
                    setPickedMaterialId(String(materialId));
                    setPlanOpen(true);
                }}
            />
            <PmRequestDecisionDialog
                line={pmDecision?.line ?? null}
                decision={pmDecision?.decision ?? null}
                onOpenChange={(open) => !open && setPmDecision(null)}
            />

            <ProjectMaterialActionDialog
                line={action?.line ?? null}
                action={action?.action ?? null}
                onOpenChange={(open) => !open && setAction(null)}
                vendors={vendors}
                catalogOptions={catalogOptions}
                categories={materialCategories}
            />
        </div>
    );
}
