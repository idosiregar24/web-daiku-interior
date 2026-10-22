import { MaterialRequestDialog } from '@/Components/modules/logistics/MaterialRequestDialog';
import { PmRequestDecisionDialog } from '@/Components/modules/logistics/PmRequestDecisionDialog';
import { type MaterialAction, ProjectMaterialActionDialog } from '@/Components/modules/projects/ProjectMaterialActionDialog';
import { MaterialStatusChip } from '@/Components/modules/projects/MaterialStatusChip';
import { type PendingMaterialLine, ProjectMaterialBatchDialog } from '@/Components/modules/projects/ProjectMaterialBatchDialog';
import { TmMaterialPlanner } from '@/Components/modules/projects/TmMaterialPlanner';
import { ConfirmDialog } from '@/Components/shared/ConfirmDialog';
import { EmptyState } from '@/Components/shared/EmptyState';
import { Notice } from '@/Components/shared/Notice';
import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import { StatusChip } from '@/Components/shared/StatusChip';
import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { VendorSelect } from '@/Components/shared/VendorSelect';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogClose, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { formatQty, formatRupiah } from '@/lib/format';
import { type CatalogMaterial, projectVisit, RELOAD, type ReturnCatalogMaterial, useProjectCatalog } from '@/lib/projectReload';
import { parseQty, quantityField } from '@/lib/quantity';
import { cn } from '@/lib/utils';
import type { MaterialCategory, Project, ProjectExecution, ProjectMaterial, ProjectMaterialSource, UnitOption, VendorOption } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Link, router } from '@inertiajs/react';
import {
    ArrowDownToLine,
    ArrowUpFromLine,
    Check,
    Gift,
    Hammer,
    Loader2,
    Lock,
    MoreHorizontal,
    Pencil,
    Plus,
    ShoppingCart,
    Trash2,
    Truck,
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
    /** Sprint 29 Sub 11 (E1) — stock & cost columns; never for the Pengawas / Admin Proyek. */
    seeCost: boolean;
    /** Sprint 29 Sub 11 (E2/E3) — this viewer plans through Logistik (Rencana Bahan). */
    planThroughLogistics: boolean;
    /** Sprint 29 Sub 11 — the link to Pemesanan Bahan (CEO, PM / Asisten PM, Logistik). */
    openOrders: boolean;
}

/** A Rencana Bahan line in the planner's shape (the TM card's `meetingMaterials`). */
function plannerLine(line: ProjectMaterial): ProjectExecution['meetingMaterials'][number] {
    const unit = line.unit?.code ?? null;

    return {
        id: line.id,
        material_id: line.material_id,
        name: line.display_name,
        qty: `${formatQty(line.qty_planned)} ${unit ?? ''}`.trim(),
        qty_planned: Number(line.qty_planned),
        qty_received: Number(line.qty_received),
        unit,
        source: line.source,
        channel: line.request_channel,
        order_status: line.order_status,
        reject_reason: line.reject_reason,
        expected_at: line.expected_at ?? null,
        // E5 — the planners' draft until "Ajukan ke Logistik".
        editable: line.order_status === 'DIRENCANAKAN',
    };
}

interface ProjectMaterialsPanelProps {
    project: Project;
    items: ProjectMaterial[];
    canView: boolean;
    permissions: MaterialPermissions;
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

// Mirrors UpdateProjectMaterialRequest ("Ubah Rencana"; new lines go through ProjectMaterialBatchDialog).
const planSchema = z.object({
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
    vendors,
    units,
    materialCategories,
}: ProjectMaterialsPanelProps) {
    const { materialOptions } = useProjectCatalog();
    const [planOpen, setPlanOpen] = useState(false);
    const [editing, setEditing] = useState<ProjectMaterial | null>(null);
    const [editBusy, setEditBusy] = useState(false);
    const [action, setAction] = useState<{ line: ProjectMaterial; action: MaterialAction } | null>(null);
    const [requestOpen, setRequestOpen] = useState(false);
    // What was typed in the catalog search before choosing "Ajukan barang".
    const [requestName, setRequestName] = useState('');
    // K3 — lines of "Simpan semua" on their way to the server.
    const [pending, setPending] = useState<PendingMaterialLine[]>([]);
    // Set when "Mungkin maksud Anda" sends the user back from a request to planning.
    const [pickedMaterialId, setPickedMaterialId] = useState<string | null>(null);
    const [pmDecision, setPmDecision] = useState<{ line: ProjectMaterial; decision: 'approve' | 'reject' } | null>(null);
    // Sprint 26 H8a — the Daiku ConfirmDialog instead of the native browser prompt.
    const [removing, setRemoving] = useState<ProjectMaterial | null>(null);
    const [removingBusy, setRemovingBusy] = useState(false);

    const form = useForm<PlanValues>({
        resolver: zodResolver(planSchema),
        defaultValues: { qty_planned: '', vendor_id: '' },
    });

    useEffect(() => {
        if (editing) {
            form.reset({ qty_planned: String(editing.qty_planned), vendor_id: editing.vendor_id ? String(editing.vendor_id) : '' });
        }
    }, [editing]);

    // Requests nobody approved yet (or rejected) live in their own section, outside the cost tables.
    const approvedItems = useMemo(() => items.filter((item) => item.request_status === 'DISETUJUI'), [items]);
    // Sprint 29 Sub 11 — the Pengawas's Rencana Bahan has its own section (a draft is not a request yet).
    const planItems = items.filter((item) => item.request_channel === 'RENCANA');
    const openPlanItems = planItems.filter((item) => item.request_status !== 'DISETUJUI');
    const requestItems = items.filter((item) => item.request_status !== 'DISETUJUI' && item.request_channel !== 'RENCANA');
    const pendingRequests = requestItems.filter((item) => item.request_status === 'MENUNGGU_PM' || item.request_status === 'DIAJUKAN');

    // K3 — a pending line for a catalog item + source already planned replaces that row's amount
    // (ProjectMaterialService::plan), so that row is marked instead of a second one shown.
    const savingIds = useMemo(
        () => new Set(approvedItems.filter((item) => pending.some((line) => isSameLine(item, line))).map((item) => item.id)),
        [approvedItems, pending],
    );

    const groups = useMemo(
        () =>
            SOURCES.map((source) => {
                const lines = approvedItems.filter((item) => item.source === source.value);
                const incoming = pending.filter((line) => line.source === source.value && !lines.some((item) => isSameLine(item, line)));

                return { ...source, lines, incoming, cost: lines.reduce((sum, line) => sum + Number(line.cost_total), 0) };
            }),
        [approvedItems, pending],
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

    /** "Ubah Rencana" — the amount (and a bought line's vendor). Quiet: the changed row is the feedback. */
    function onEditSubmit(values: PlanValues) {
        if (!editing) return;

        const vendorId = values.vendor_id ? Number(values.vendor_id) : null;

        router.put(
            route('project-materials.update', { project_material: editing.id }),
            { qty_planned: parseQty(values.qty_planned), ...(editing.source !== 'GUDANG' ? { vendor_id: vendorId } : {}) },
            {
                ...projectVisit(RELOAD.materials, { quiet: true }),
                onStart: () => setEditBusy(true),
                onFinish: () => setEditBusy(false),
                onError: (errors: Record<string, string>) =>
                    Object.entries(errors).forEach(([field, message]) => form.setError(field as keyof PlanValues, { message })),
                onSuccess: () => setEditing(null),
            },
        );
    }

    function remove() {
        if (!removing) return;

        router.delete(route('project-materials.destroy', { project_material: removing.id }), {
            ...projectVisit(RELOAD.materials, { quiet: true }),
            onStart: () => setRemovingBusy(true),
            onFinish: () => {
                setRemovingBusy(false);
                setRemoving(null);
            },
        });
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
                {permissions.seeCost ? (
                    <p className="inline-flex flex-wrap items-center gap-x-1 rounded-lg bg-daiku-gray/70 px-3 py-2 text-sm text-daiku-muted ring-1 ring-border ring-inset">
                        Biaya material: <span className="font-medium text-daiku-dark">{formatRupiah(totalCost)}</span>
                        {groups.map((group) => (
                            <span key={group.value}>
                                {' · '}
                                {group.label} <span className="font-medium text-daiku-dark">{formatRupiah(group.cost)}</span>
                            </span>
                        ))}
                    </p>
                ) : (
                    <span />
                )}
                {/* §5.5 Lapis 4 — search the catalog first; requesting an item is offered from there.
                    Sprint 29 Sub 11 — the Pengawas / Admin Proyek plan in the Rencana Bahan below instead. */}
                {permissions.create && !permissions.planThroughLogistics && (
                    <Button
                        size="sm"
                        className="max-md:h-11"
                        onClick={() => {
                            setPickedMaterialId(null);
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

            {permissions.planThroughLogistics && project.status !== 'COMPLETED' && project.status !== 'CANCELLED' && (
                <section className="space-y-2">
                    <h3 className="text-sm font-semibold text-daiku-dark">
                        Rencana Bahan <span className="font-normal text-daiku-muted">· katalog atau bahan lain, lalu ajukan ke Logistik</span>
                    </h3>
                    <TmMaterialPlanner
                        projectId={project.id}
                        lines={planItems.map(plannerLine)}
                        canUpdate
                        canRemove
                        throughLogistics
                    />
                </section>
            )}

            {!permissions.planThroughLogistics && openPlanItems.length > 0 && (
                <section className="space-y-2">
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 className="text-sm font-semibold text-daiku-dark">
                            Rencana Bahan <span className="font-normal text-daiku-muted">· dari Pengawas, diproses Logistik</span>
                        </h3>
                        {permissions.openOrders && (
                            <Button size="sm" variant="outline" className="max-md:h-11" asChild>
                                <Link href={route('logistics.material-orders.index', { project_id: project.id })}>
                                    <Truck className="size-4" />
                                    Pemesanan Bahan
                                </Link>
                            </Button>
                        )}
                    </div>
                    <TableCard>
                        <ul className="divide-y divide-border md:hidden">
                            {openPlanItems.map((line) => (
                                <li key={line.id} className="space-y-1 px-4 py-3 text-sm">
                                    <div className="flex items-start justify-between gap-3">
                                        <p className="font-medium text-daiku-dark">{line.display_name}</p>
                                        <span className="shrink-0 tabular-nums">
                                            {formatQty(line.qty_planned)} {line.unit?.code ?? ''}
                                        </span>
                                    </div>
                                    <MaterialStatusChip line={line} unit={line.unit?.code} inline />
                                </li>
                            ))}
                        </ul>
                        <table className="hidden w-full text-sm md:table">
                            <thead className={TABLE_HEAD_CLASS}>
                                <tr>
                                    <th className="px-4 py-2.5 text-left font-semibold">Barang</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Jumlah</th>
                                    <th className="px-4 py-2.5 text-left font-semibold">Perencana</th>
                                    <th className="px-4 py-2.5 text-left font-semibold">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {openPlanItems.map((line) => (
                                    <tr key={line.id} className="border-t border-border align-top">
                                        <td className="px-4 py-3 font-medium text-daiku-dark">{line.display_name}</td>
                                        <td className="px-4 py-3 text-right tabular-nums">
                                            {formatQty(line.qty_planned)} {line.unit?.code ?? ''}
                                        </td>
                                        <td className="px-4 py-3">{line.requester?.name ?? '—'}</td>
                                        <td className="px-4 py-3">
                                            <MaterialStatusChip line={line} unit={line.unit?.code} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </TableCard>
                </section>
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
                                            <StatusChip domain="materialRequest" status={line.request_status} />
                                            {line.reject_reason && <p className="mt-1 text-xs text-error-ink">{line.reject_reason}</p>}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex justify-end gap-1">
                                                {permissions.pmDecide && line.request_status === 'MENUNGGU_PM' && (
                                                    <>
                                                        <Button size="sm" onClick={() => setPmDecision({ line, decision: 'approve' })}>
                                                            <Check className="size-4" />
                                                            Setujui Pengajuan
                                                        </Button>
                                                        <Button size="sm" variant="outline" onClick={() => setPmDecision({ line, decision: 'reject' })}>
                                                            Tolak Pengajuan
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

            {approvedItems.length === 0 && pending.length === 0 ? (
                <EmptyState className="rounded-xl border border-dashed border-border" title="Belum ada kebutuhan material untuk proyek ini." />
            ) : (
                groups
                    .filter((group) => group.lines.length > 0 || group.incoming.length > 0)
                    .map((group) => (
                        <section key={group.value} className="space-y-2">
                            <div className="flex flex-wrap items-baseline justify-between gap-2">
                                <h3 className="text-sm font-semibold text-daiku-dark">
                                    {group.label} <span className="font-normal text-daiku-muted">· {group.hint}</span>
                                </h3>
                                {permissions.seeCost && (
                                    <span className="text-sm text-daiku-muted">
                                        Biaya <span className="font-medium text-daiku-dark">{formatRupiah(group.cost)}</span>
                                    </span>
                                )}
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
                                            {permissions.seeCost && <th className="px-4 py-2.5 text-right font-semibold">Biaya</th>}
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
                                                        {savingIds.has(line.id) && <SavingMark />}
                                                        <p className="text-xs text-daiku-muted">
                                                            {[
                                                                line.vendor?.name,
                                                                permissions.seeCost && line.unit_price && line.source !== 'GUDANG' && `${formatRupiah(line.unit_price)}/${unit}`,
                                                                permissions.seeCost &&
                                                                    line.source === 'GUDANG' &&
                                                                    line.material?.stock !== undefined &&
                                                                    `stok gudang ${formatQty(line.material.stock)} ${unit}`,
                                                            ]
                                                                .filter(Boolean)
                                                                .join(' · ')}
                                                        </p>
                                                        {/* Sprint 29 Sub 11 (E4) — Disetujui · Dipesan · Diterima sebagian · Lengkap · Dari gudang. */}
                                                        <MaterialStatusChip line={line} unit={unit} inline className="mt-1" />
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
                                                    {permissions.seeCost && <td className="px-4 py-3 text-right tabular-nums">{formatRupiah(line.cost_total)}</td>}
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
                                                                            }}
                                                                        >
                                                                            <Pencil className="size-4" />
                                                                            Ubah Rencana
                                                                        </DropdownMenuItem>
                                                                    )}
                                                                    {allowed.delete && (
                                                                        <DropdownMenuItem variant="destructive" onClick={() => setRemoving(line)}>
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
                                        {group.incoming.map((line) => (
                                            <tr key={line.key} className="border-t border-border align-top text-daiku-muted">
                                                <td className="px-4 py-3">
                                                    <p className="font-medium text-daiku-dark">{line.name}</p>
                                                    <SavingMark />
                                                </td>
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    {formatQty(line.qty_planned)} {line.unit}
                                                </td>
                                                <td colSpan={permissions.seeCost ? 6 : 5} />
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </TableCard>
                        </section>
                    ))
            )}

            <ProjectMaterialBatchDialog
                open={planOpen}
                onOpenChange={setPlanOpen}
                projectId={project.id}
                items={items}
                vendors={vendors}
                canRequest={permissions.request}
                onRequest={(term) => {
                    setRequestName(term);
                    setPlanOpen(false);
                    setRequestOpen(true);
                }}
                pickedMaterialId={pickedMaterialId}
                onPickConsumed={() => setPickedMaterialId(null)}
                onPendingChange={setPending}
            />

            <Dialog open={editing !== null} onOpenChange={(open) => !open && setEditing(null)}>
                <ResponsiveDialogContent className="max-w-md">
                    <DialogHeader>
                        <DialogTitle>Ubah Rencana Material</DialogTitle>
                    </DialogHeader>
                    <Form {...form}>
                        <form onSubmit={form.handleSubmit(onEditSubmit)} className="space-y-4">
                            <div className="rounded-md bg-daiku-gray px-3 py-2 text-sm">
                                <p className="font-medium text-daiku-dark">{editing?.display_name}</p>
                                <p className="text-xs text-daiku-muted">{SOURCES.find((source) => source.value === editing?.source)?.label}</p>
                            </div>
                            <FormField
                                control={form.control}
                                name="qty_planned"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Jumlah Kebutuhan{editing?.unit?.code ? ` (${editing.unit.code})` : ''}</FormLabel>
                                        <FormControl>
                                            <Input inputMode="decimal" autoComplete="off" autoFocus className="max-md:h-11" {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            {editing?.source === 'PEMBELIAN' && (
                                <FormField
                                    control={form.control}
                                    name="vendor_id"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Vendor</FormLabel>
                                            <FormControl>
                                                <VendorSelect
                                                    value={field.value ?? ''}
                                                    onChange={field.onChange}
                                                    vendors={vendors}
                                                    allowEmpty
                                                    className="w-full max-md:h-11"
                                                />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                            )}
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button type="button" variant="outline" className="max-md:h-11">
                                        Batal
                                    </Button>
                                </DialogClose>
                                <Button type="submit" className="max-md:h-11" disabled={editBusy}>
                                    {editBusy && <Loader2 className="size-4 animate-spin" />}
                                    {editBusy ? 'Menyimpan…' : 'Simpan'}
                                </Button>
                            </DialogFooter>
                        </form>
                    </Form>
                </ResponsiveDialogContent>
            </Dialog>

            <MaterialRequestDialog
                open={requestOpen}
                onOpenChange={setRequestOpen}
                projects={[project]}
                fixedProjectId={project.id}
                minimal={false}
                units={units}
                vendors={vendors}
                initialName={requestName}
                catalogHints={permissions.create ? materialOptions : []}
                onPickCatalog={(materialId) => {
                    // "Mungkin maksud Anda" → plan that catalog item instead of requesting it.
                    setRequestOpen(false);
                    setPickedMaterialId(String(materialId));
                    setPlanOpen(true);
                }}
                visitOptions={projectVisit(RELOAD.materials)}
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
                categories={materialCategories}
            />

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                tone="danger"
                title="Hapus dari kebutuhan proyek?"
                description={`"${removing?.display_name ?? ''}" dihapus dari kebutuhan proyek ini.`}
                confirmLabel="Ya, hapus"
                busy={removingBusy}
                onConfirm={remove}
            />
        </div>
    );
}

function isSameLine(item: ProjectMaterial, line: PendingMaterialLine): boolean {
    return item.material_id === line.material_id && item.source === line.source && item.request_channel === null;
}

/** K3 — a line from "Simpan semua" is still on its way to the server. */
function SavingMark() {
    return (
        <p className="mt-0.5 flex items-center gap-1 text-xs text-daiku-muted">
            <Loader2 className="size-3 animate-spin" />
            menyimpan…
        </p>
    );
}
