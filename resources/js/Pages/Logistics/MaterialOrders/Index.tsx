import { type CatalogOption, MaterialRequestReviewDialog, type MaterialRequestRow } from '@/Components/modules/logistics/MaterialRequestReviewDialog';
import { partialVisit } from '@/Components/modules/logistics/partialVisit';
import { MaterialStatusChip } from '@/Components/modules/projects/MaterialStatusChip';
import { type MaterialAction, ProjectMaterialActionDialog } from '@/Components/modules/projects/ProjectMaterialActionDialog';
import { DatePicker } from '@/Components/shared/DatePicker';
import { EmptyState } from '@/Components/shared/EmptyState';
import { PageHeader } from '@/Components/shared/PageHeader';
import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import { StatusChip } from '@/Components/shared/StatusChip';
import { TABLE_HEAD_CLASS, TableCard } from '@/Components/shared/TableCard';
import { VendorSelect } from '@/Components/shared/VendorSelect';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Dialog, DialogClose, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import { formatQty } from '@/lib/format';
import type { MaterialCategory, PaginatedData, Project, UnitOption, VendorOption } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, Link, router } from '@inertiajs/react';
import { format, parseISO } from 'date-fns';
import { ArrowUpFromLine, Check, ClipboardCheck, Loader2, ShoppingCart, Truck, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

type StatusFilter = 'open' | 'DIAJUKAN' | 'DISETUJUI' | 'DIPESAN' | 'DITERIMA' | 'DITOLAK' | 'all';

/** A line as MaterialOrderController::index() sends it — same shape as a Pengajuan Barang row. */
type OrderRow = MaterialRequestRow & { material?: (NonNullable<MaterialRequestRow['material']> & { code?: string }) | null };

interface MaterialOrderIndexProps {
    lines: PaginatedData<OrderRow>;
    filters: { status: StatusFilter; project_id: number | null };
    counts: { DIAJUKAN: number; DISETUJUI: number; DIPESAN: number };
    projects: Pick<Project, 'id' | 'name'>[];
    permissions: { decide: boolean };
    vendors: VendorOption[];
    units: UnitOption[];
    catalog: CatalogOption[];
    categories: Pick<MaterialCategory, 'id' | 'name' | 'code_prefix'>[];
}

const STATUS_FILTERS: { value: StatusFilter; label: string }[] = [
    { value: 'open', label: 'Perlu diproses' },
    { value: 'DIAJUKAN', label: 'Diajukan — perlu keputusan' },
    { value: 'DISETUJUI', label: 'Disetujui — belum dipesan / dikeluarkan' },
    { value: 'DIPESAN', label: 'Dipesan — menunggu datang' },
    { value: 'DITERIMA', label: 'Lengkap diterima' },
    { value: 'DITOLAK', label: 'Ditolak' },
    { value: 'all', label: 'Semua' },
];

const SOURCE_LABEL: Record<string, string> = { GUDANG: 'Gudang', PEMBELIAN: 'Pembelian', CUSTOM: 'Custom' };

// Reloaded after a save: the list, the counters and the project filter (Sprint 29 Sub 01 L9).
const ONLY = ['lines', 'counts', 'projects'] as const;

const approvable = (line: OrderRow) => line.order_status === 'DIAJUKAN' && line.material_id !== null;
const rejectable = (line: OrderRow) => line.order_status === 'DIAJUKAN';
/** ProjectMaterialService::markOrdered() — approved, bought (not from the warehouse), nothing arrived yet. */
const orderable = (line: OrderRow) => (line.order_status === 'DISETUJUI' || line.order_status === 'DIPESAN') && line.source !== 'GUDANG';
const receivable = (line: OrderRow) => ['DISETUJUI', 'DIPESAN', 'SEBAGIAN'].includes(line.order_status);

/**
 * Sprint 29 Sub 11 (E3/E4) — "Pemesanan Bahan": the Rencana Bahan the
 * Pengawas / Admin Proyek submitted, per project. Logistik approves or
 * rejects (one row or the ticked ones), reviews a custom item against the
 * catalog, marks bought items "Dipesan" with the expected arrival, and
 * records what arrives. The CEO and the projects' PM read along.
 */
export default function MaterialOrderIndex({ lines, filters, counts, projects, permissions, vendors, units, catalog, categories }: MaterialOrderIndexProps) {
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [deciding, setDeciding] = useState<{ ids: number[]; decision: 'approve' | 'reject' } | null>(null);
    const [ordering, setOrdering] = useState<OrderRow[] | null>(null);
    const [reviewing, setReviewing] = useState<OrderRow | null>(null);
    const [action, setAction] = useState<{ line: OrderRow; action: MaterialAction } | null>(null);

    const groups = useMemo(() => {
        const byProject = new Map<number, { project: OrderRow['project']; lines: OrderRow[] }>();
        for (const line of lines.data) {
            const group = byProject.get(line.project.id) ?? { project: line.project, lines: [] };
            group.lines.push(line);
            byProject.set(line.project.id, group);
        }

        return [...byProject.values()];
    }, [lines.data]);

    const picked = lines.data.filter((line) => selected.has(line.id));
    const toApprove = picked.filter(approvable);
    const toReject = picked.filter(rejectable);
    const toOrder = picked.filter(orderable);
    const selectable = (line: OrderRow) => permissions.decide && (rejectable(line) || orderable(line));

    function toggle(ids: number[], on: boolean) {
        setSelected((current) => {
            const next = new Set(current);
            ids.forEach((id) => (on ? next.add(id) : next.delete(id)));
            return next;
        });
    }

    function applyFilter(next: Partial<MaterialOrderIndexProps['filters']>) {
        const merged = { ...filters, ...next };
        setSelected(new Set());
        router.get(
            route('logistics.material-orders.index'),
            { status: merged.status === 'open' ? undefined : merged.status, project_id: merged.project_id ?? undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    const done = () => setSelected(new Set());

    /** One row's buttons — the grid cell and the phone card alike. */
    function actionsOf(line: OrderRow, block = false) {
        if (!permissions.decide) return null;

        const size = block ? 'default' : 'sm';
        const buttons = [];

        if (approvable(line)) {
            buttons.push(
                <Button key="approve" size={size} onClick={() => setDeciding({ ids: [line.id], decision: 'approve' })}>
                    <Check className="size-4" />
                    Setujui
                </Button>,
                <Button key="reject" size={size} variant="outline" onClick={() => setDeciding({ ids: [line.id], decision: 'reject' })}>
                    <X className="size-4" />
                    Tolak
                </Button>,
            );
        } else if (line.order_status === 'DIAJUKAN') {
            // A custom item — matched to the catalog (or registered) first, golden rule #10.
            buttons.push(
                <Button key="review" size={size} onClick={() => setReviewing(line)}>
                    <ClipboardCheck className="size-4" />
                    Tinjau
                </Button>,
            );
        }

        if (orderable(line)) {
            buttons.push(
                <Button key="order" size={size} variant={line.order_status === 'DIPESAN' ? 'outline' : 'default'} onClick={() => setOrdering([line])}>
                    <Truck className="size-4" />
                    {line.order_status === 'DIPESAN' ? 'Ubah Pesanan' : 'Tandai Dipesan'}
                </Button>,
            );
        }

        if (receivable(line)) {
            buttons.push(
                line.source === 'GUDANG' ? (
                    <Button key="issue" size={size} variant="outline" onClick={() => setAction({ line, action: 'issue' })}>
                        <ArrowUpFromLine className="size-4" />
                        Keluarkan dari Gudang
                    </Button>
                ) : (
                    <Button key="receive" size={size} variant="outline" onClick={() => setAction({ line, action: 'purchase' })}>
                        <ShoppingCart className="size-4" />
                        Catat Penerimaan
                    </Button>
                ),
            );
        }

        return buttons.length > 0 ? <div className={block ? 'grid grid-cols-2 gap-2' : 'flex flex-wrap justify-end gap-1'}>{buttons}</div> : null;
    }

    function itemOf(line: OrderRow) {
        return (
            <div className="min-w-0">
                <p className="font-medium text-daiku-dark">{line.display_name}</p>
                <p className="text-xs text-daiku-muted">
                    {[
                        line.material?.code,
                        line.requester?.name && `oleh ${line.requester.name}`,
                        line.request_reason && `“${line.request_reason}”`,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </p>
                {line.photo_link && (
                    <a href={line.photo_link} target="_blank" rel="noreferrer noopener" className="text-xs underline decoration-daiku-yellow underline-offset-2">
                        Lihat foto / contoh
                    </a>
                )}
            </div>
        );
    }

    function sourceOf(line: OrderRow) {
        const unit = line.unit?.code ?? '';

        return (
            <div className="space-y-0.5 text-sm">
                <StatusChip status={line.source} label={SOURCE_LABEL[line.source]} />
                {line.source === 'GUDANG' && line.material?.stock !== undefined && (
                    <p className="text-xs text-daiku-muted">
                        stok {formatQty(line.material.stock)} {unit}
                    </p>
                )}
                {line.vendor?.name && <p className="text-xs text-daiku-muted">{line.vendor.name}</p>}
                {line.ordered_at && (
                    <p className="text-xs text-daiku-muted">
                        dipesan {format(parseISO(line.ordered_at), 'dd/MM')}
                        {line.orderer?.name ? ` · ${line.orderer.name}` : ''}
                    </p>
                )}
                {line.order_note && <p className="text-xs text-daiku-muted">“{line.order_note}”</p>}
            </div>
        );
    }

    return (
        <AppLayout>
            <Head title="Pemesanan Bahan" />

            <PageHeader
                title="Pemesanan Bahan"
                icon={Truck}
                description={
                    permissions.decide
                        ? 'Rencana bahan yang diajukan Pengawas per proyek — setujui atau tolak, pesan yang dibeli, lalu catat yang datang.'
                        : 'Rencana bahan proyek dari Pengawas dan sampai mana Logistik memprosesnya.'
                }
                step={
                    permissions.decide && counts.DIAJUKAN + counts.DISETUJUI > 0
                        ? `${counts.DIAJUKAN} bahan menunggu keputusan · ${counts.DISETUJUI} disetujui belum dipesan / dikeluarkan · ${counts.DIPESAN} dipesan.`
                        : undefined
                }
            />

            <TableCard
                pagination={lines}
                toolbar={
                    <div className="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                        <div className="flex flex-wrap items-center gap-2">
                            <Select value={filters.status} onValueChange={(value) => applyFilter({ status: value as StatusFilter })}>
                                <SelectTrigger className="w-full sm:w-72" aria-label="Filter status">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {STATUS_FILTERS.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {option.label}
                                            {option.value !== 'open' && option.value in counts && counts[option.value as keyof typeof counts] > 0
                                                ? ` (${counts[option.value as keyof typeof counts]})`
                                                : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.project_id ? String(filters.project_id) : 'all'}
                                onValueChange={(value) => applyFilter({ project_id: value === 'all' ? null : Number(value) })}
                            >
                                <SelectTrigger className="w-full sm:w-64" aria-label="Filter proyek">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Semua proyek</SelectItem>
                                    {projects.map((project) => (
                                        <SelectItem key={project.id} value={String(project.id)}>
                                            {project.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                }
            >
                {groups.length === 0 ? (
                    <EmptyState title={filters.status === 'open' ? 'Tidak ada rencana bahan yang perlu diproses.' : 'Belum ada rencana bahan.'} />
                ) : (
                    groups.map((group) => {
                        const ids = group.lines.filter(selectable).map((line) => line.id);
                        const all = ids.length > 0 && ids.every((id) => selected.has(id));

                        return (
                            <section key={group.project.id} className="border-b border-border last:border-b-0">
                                <div className="flex flex-wrap items-center justify-between gap-2 bg-daiku-gray/70 px-4 py-2.5">
                                    <div className="flex items-center gap-3">
                                        {ids.length > 0 && (
                                            <Checkbox
                                                checked={all}
                                                onCheckedChange={(on) => toggle(ids, on === true)}
                                                aria-label={`Pilih semua bahan ${group.project.name}`}
                                                className="max-md:size-5"
                                            />
                                        )}
                                        <Link
                                            href={route('projects.show', { project: group.project.id, tab: 'material' })}
                                            className="font-semibold text-daiku-dark underline decoration-daiku-yellow underline-offset-2"
                                        >
                                            {group.project.name}
                                        </Link>
                                    </div>
                                    <span className="text-sm text-daiku-muted">{group.lines.length} bahan</span>
                                </div>

                                {/* Phone (Logistik in the warehouse): a card per line. */}
                                <ul className="divide-y divide-border md:hidden">
                                    {group.lines.map((line) => (
                                        <li key={line.id} className="space-y-2 px-4 py-3">
                                            <div className="flex items-start gap-3">
                                                {selectable(line) && (
                                                    <Checkbox
                                                        checked={selected.has(line.id)}
                                                        onCheckedChange={(on) => toggle([line.id], on === true)}
                                                        aria-label={`Pilih ${line.display_name}`}
                                                        className="mt-0.5 size-5"
                                                    />
                                                )}
                                                <div className="min-w-0 flex-1">{itemOf(line)}</div>
                                                <span className="shrink-0 text-sm tabular-nums">
                                                    {formatQty(line.qty_planned)} {line.unit?.code ?? ''}
                                                </span>
                                            </div>
                                            <div className="flex flex-wrap items-start justify-between gap-2">
                                                {sourceOf(line)}
                                                <MaterialStatusChip line={line} unit={line.unit?.code} />
                                            </div>
                                            {actionsOf(line, true)}
                                        </li>
                                    ))}
                                </ul>

                                <table className="hidden w-full text-sm md:table">
                                    <thead className={TABLE_HEAD_CLASS}>
                                        <tr>
                                            <th className="w-10 px-4 py-2.5" />
                                            <th className="px-4 py-2.5 text-left font-semibold">Bahan</th>
                                            <th className="px-4 py-2.5 text-right font-semibold">Jumlah</th>
                                            <th className="px-4 py-2.5 text-left font-semibold">Sumber · Pesanan</th>
                                            <th className="px-4 py-2.5 text-left font-semibold">Status</th>
                                            <th className="px-4 py-2.5" />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {group.lines.map((line) => (
                                            <tr key={line.id} className="border-t border-border align-top">
                                                <td className="px-4 py-3">
                                                    {selectable(line) && (
                                                        <Checkbox
                                                            checked={selected.has(line.id)}
                                                            onCheckedChange={(on) => toggle([line.id], on === true)}
                                                            aria-label={`Pilih ${line.display_name}`}
                                                        />
                                                    )}
                                                </td>
                                                <td className="max-w-xs px-4 py-3">{itemOf(line)}</td>
                                                <td className="px-4 py-3 text-right tabular-nums">
                                                    {formatQty(line.qty_planned)} {line.unit?.code ?? ''}
                                                    {Number(line.qty_received) > 0 && (
                                                        <span className="block text-xs text-daiku-muted">diterima {formatQty(line.qty_received)}</span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">{sourceOf(line)}</td>
                                                <td className="px-4 py-3">
                                                    <MaterialStatusChip line={line} unit={line.unit?.code} />
                                                </td>
                                                <td className="px-4 py-3">{actionsOf(line)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </section>
                        );
                    })
                )}
            </TableCard>

            {/* Sprint 34 — the ticked lines' actions stay in view while scrolling (they used to sit in the toolbar above the list). */}
            {permissions.decide && picked.length > 0 && (
                <div className="sticky bottom-3 z-20 mt-3 flex flex-col gap-2 rounded-xl bg-background p-3 shadow-lg ring-2 ring-daiku-yellow sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-sm font-medium text-foreground">
                        {picked.length} bahan dipilih
                        {toApprove.length > 0 && toApprove.length < picked.length && (
                            <span className="font-normal text-muted-foreground"> · {toApprove.length} bisa disetujui</span>
                        )}
                    </p>
                    <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
                        <Button className="max-md:h-11" disabled={toApprove.length === 0} onClick={() => setDeciding({ ids: toApprove.map((line) => line.id), decision: 'approve' })}>
                            <Check className="size-4" />
                            {toApprove.length > 1 && toApprove.length === picked.length ? `Setujui semua (${toApprove.length})` : `Setujui (${toApprove.length})`}
                        </Button>
                        <Button variant="outline" className="max-md:h-11" disabled={toReject.length === 0} onClick={() => setDeciding({ ids: toReject.map((line) => line.id), decision: 'reject' })}>
                            <X className="size-4" />
                            Tolak ({toReject.length})
                        </Button>
                        {toOrder.length > 0 && (
                            <Button variant="outline" className="max-md:h-11" onClick={() => setOrdering(toOrder)}>
                                <Truck className="size-4" />
                                Tandai Dipesan ({toOrder.length})
                            </Button>
                        )}
                        <Button variant="ghost" className="max-md:h-11" onClick={done}>
                            Batal pilih
                        </Button>
                    </div>
                </div>
            )}

            <DecideDialog
                key={deciding ? `${deciding.decision}-${deciding.ids.join('-')}` : 'decide'}
                state={deciding}
                lines={lines.data}
                vendors={vendors}
                onClose={() => setDeciding(null)}
                onDone={done}
            />
            <OrderDialog
                key={ordering ? ordering.map((line) => line.id).join('-') : 'order'}
                lines={ordering}
                vendors={vendors}
                onClose={() => setOrdering(null)}
                onDone={done}
            />
            <MaterialRequestReviewDialog
                line={reviewing}
                onOpenChange={(open) => !open && setReviewing(null)}
                catalog={catalog}
                units={units}
                vendors={vendors}
                categories={categories}
                // A new catalog item may be born from the decision.
                only={[...ONLY, 'catalog']}
            />
            <ProjectMaterialActionDialog
                line={action?.line ?? null}
                action={action?.action ?? null}
                onOpenChange={(open) => !open && setAction(null)}
                vendors={vendors}
                categories={categories}
                only={ONLY}
            />
        </AppLayout>
    );
}

// Mirrors DecideMaterialPlanRequest (reason required to reject, 3–1000 characters).
const decideSchema = z.object({
    reason: z.string().max(1000, 'Maksimal 1000 karakter.'),
    source: z.enum(['', 'GUDANG', 'PEMBELIAN']),
    vendor_id: z.string(),
});

type DecideValues = z.infer<typeof decideSchema>;

/** Setujui (optionally another source / the vendor) or Tolak (reason) — one line or the ticked ones. */
function DecideDialog({
    state,
    lines,
    vendors,
    onClose,
    onDone,
}: {
    state: { ids: number[]; decision: 'approve' | 'reject' } | null;
    lines: OrderRow[];
    vendors: VendorOption[];
    onClose: () => void;
    onDone: () => void;
}) {
    const [busy, setBusy] = useState(false);
    const form = useForm<DecideValues>({ resolver: zodResolver(decideSchema), defaultValues: { reason: '', source: '', vendor_id: '' } });
    const source = form.watch('source');

    if (!state) return null;

    const reject = state.decision === 'reject';
    const rows = lines.filter((line) => state.ids.includes(line.id));
    const buys = source === 'PEMBELIAN' || (source === '' && rows.some((line) => line.source === 'PEMBELIAN'));

    function onSubmit(values: DecideValues) {
        if (!state) return;
        if (reject && values.reason.trim().length < 3) {
            form.setError('reason', { message: 'Alasan penolakan wajib diisi (minimal 3 karakter).' });
            return;
        }

        router.post(
            route('logistics.material-orders.decide'),
            {
                ids: state.ids,
                decision: state.decision,
                reason: reject ? values.reason.trim() : null,
                source: reject ? null : values.source || null,
                vendor_id: !reject && values.vendor_id ? Number(values.vendor_id) : null,
            },
            {
                ...partialVisit(ONLY),
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onError: (errors) => Object.entries(errors).forEach(([field, message]) => form.setError((field in values ? field : 'reason') as keyof DecideValues, { message })),
                onSuccess: () => {
                    onDone();
                    onClose();
                },
            },
        );
    }

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <ResponsiveDialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{reject ? `Tolak ${rows.length} bahan` : `Setujui ${rows.length} bahan`}</DialogTitle>
                    <DialogDescription>{rows.map((line) => `${line.display_name} (${formatQty(line.qty_planned)} ${line.unit?.code ?? ''})`).join(', ')}</DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        {reject ? (
                            <FormField
                                control={form.control}
                                name="reason"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Alasan penolakan</FormLabel>
                                        <FormControl>
                                            <Textarea {...field} rows={3} placeholder="Pengawas menerima alasan ini." autoFocus />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        ) : (
                            <>
                                <FormField
                                    control={form.control}
                                    name="source"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Sumber</FormLabel>
                                            <Select value={field.value || 'KEEP'} onValueChange={(value) => field.onChange(value === 'KEEP' ? '' : value)}>
                                                <FormControl>
                                                    <SelectTrigger className="w-full max-md:h-11">
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                </FormControl>
                                                <SelectContent>
                                                    <SelectItem value="KEEP">Sesuai rencana (stok cukup → Gudang, kurang → Pembelian)</SelectItem>
                                                    <SelectItem value="GUDANG">Semua dari Gudang</SelectItem>
                                                    <SelectItem value="PEMBELIAN">Semua dibeli (Pembelian)</SelectItem>
                                                </SelectContent>
                                            </Select>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                {buys && (
                                    <FormField
                                        control={form.control}
                                        name="vendor_id"
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormLabel>Vendor (untuk yang dibeli)</FormLabel>
                                                <FormControl>
                                                    <VendorSelect value={field.value} onChange={field.onChange} vendors={vendors} allowEmpty className="w-full max-md:h-11" />
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
                                <Button type="button" variant="outline" className="max-md:h-11">
                                    Batal
                                </Button>
                            </DialogClose>
                            <Button type="submit" variant={reject ? 'destructive' : 'default'} className="max-md:h-11" disabled={busy}>
                                {busy && <Loader2 className="size-4 animate-spin" />}
                                {reject ? 'Tolak' : 'Setujui'}
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </ResponsiveDialogContent>
        </Dialog>
    );
}

// Mirrors MarkMaterialOrderedRequest.
const orderSchema = z.object({
    expected_at: z.date({ error: 'Perkiraan tiba wajib diisi.' }),
    vendor_id: z.string(),
    note: z.string().max(500, 'Maksimal 500 karakter.'),
});

type OrderValues = z.infer<typeof orderSchema>;

/** "Tandai Dipesan": the expected arrival (+ vendor, note) for one line or the ticked ones. */
function OrderDialog({ lines, vendors, onClose, onDone }: { lines: OrderRow[] | null; vendors: VendorOption[]; onClose: () => void; onDone: () => void }) {
    const [busy, setBusy] = useState(false);
    const first = lines?.[0];
    const form = useForm<OrderValues>({
        resolver: zodResolver(orderSchema),
        defaultValues: {
            expected_at: first?.expected_at ? parseISO(first.expected_at) : undefined,
            vendor_id: lines?.length === 1 && first?.vendor_id ? String(first.vendor_id) : '',
            note: lines?.length === 1 ? (first?.order_note ?? '') : '',
        },
    });

    if (!lines) return null;

    function onSubmit(values: OrderValues) {
        if (!lines) return;

        router.post(
            route('logistics.material-orders.order'),
            {
                ids: lines.map((line) => line.id),
                expected_at: format(values.expected_at, 'yyyy-MM-dd'),
                vendor_id: values.vendor_id ? Number(values.vendor_id) : null,
                note: values.note.trim() || null,
            },
            {
                ...partialVisit(ONLY),
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onError: (errors) => Object.entries(errors).forEach(([field, message]) => form.setError((field === 'ids' ? 'expected_at' : field) as keyof OrderValues, { message })),
                onSuccess: () => {
                    onDone();
                    onClose();
                },
            },
        );
    }

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <ResponsiveDialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Tandai Dipesan · {lines.length} bahan</DialogTitle>
                    <DialogDescription>{lines.map((line) => line.display_name).join(', ')} — Pengawas mendapat notifikasi beserta perkiraan tibanya.</DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="expected_at"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Perkiraan tiba</FormLabel>
                                    <FormControl>
                                        <DatePicker value={field.value} onChange={field.onChange} className="w-full max-md:h-11" />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="vendor_id"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Vendor</FormLabel>
                                    <FormControl>
                                        <VendorSelect value={field.value} onChange={field.onChange} vendors={vendors} allowEmpty className="w-full max-md:h-11" />
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
                                    <FormLabel>Catatan pesanan</FormLabel>
                                    <FormControl>
                                        <Input {...field} autoComplete="off" placeholder="No. PO, kontak vendor…" className="max-md:h-11" />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline" className="max-md:h-11">
                                    Batal
                                </Button>
                            </DialogClose>
                            <Button type="submit" className="max-md:h-11" disabled={busy}>
                                {busy && <Loader2 className="size-4 animate-spin" />}
                                Tandai Dipesan
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </ResponsiveDialogContent>
        </Dialog>
    );
}
