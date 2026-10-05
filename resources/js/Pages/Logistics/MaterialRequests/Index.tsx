import { MaterialRequestDialog } from '@/Components/modules/logistics/MaterialRequestDialog';
import {
    type CatalogOption,
    DECISION_LABEL,
    MaterialRequestReviewDialog,
    type MaterialRequestRow,
} from '@/Components/modules/logistics/MaterialRequestReviewDialog';
import { PmRequestDecisionDialog } from '@/Components/modules/logistics/PmRequestDecisionDialog';
import { DataTable } from '@/Components/shared/DataTable';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { useCreateParam } from '@/hooks/useCreateParam';
import AppLayout from '@/Layouts/AppLayout';
import { formatDateTime, formatQty, formatRupiah } from '@/lib/format';
import type { MaterialCategory, MaterialRequestStatus, PaginatedData, Project, UnitOption, VendorOption } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { Check, ClipboardList, PackagePlus, X } from 'lucide-react';
import { useState } from 'react';

type StatusFilter = 'pending' | MaterialRequestStatus | 'all';

interface MaterialRequestIndexProps {
    requests: PaginatedData<MaterialRequestRow>;
    filters: { status: StatusFilter; project_id: number | null };
    counts: { MENUNGGU_PM: number; DIAJUKAN: number };
    permissions: { review: boolean; pmDecide: boolean; request: boolean };
    isTukang: boolean;
    requestProjects: Pick<Project, 'id' | 'name'>[];
    units: UnitOption[];
    vendors: VendorOption[];
    catalog: CatalogOption[];
    categories: Pick<MaterialCategory, 'id' | 'name' | 'code_prefix'>[];
    /** "Mungkin maksud Anda" while a requester types (§5.5 Lapis 4). */
    catalogHints: { id: number; code: string; name: string }[];
}

const STATUS_FILTERS: { value: StatusFilter; label: string }[] = [
    { value: 'pending', label: 'Belum diputuskan' },
    { value: 'MENUNGGU_PM', label: 'Menunggu PM' },
    { value: 'DIAJUKAN', label: 'Menunggu Logistik' },
    { value: 'DISETUJUI', label: 'Disetujui' },
    { value: 'DITOLAK', label: 'Ditolak' },
    { value: 'all', label: 'Semua' },
];

const STATUS_LABEL: Record<MaterialRequestStatus, string> = {
    MENUNGGU_PM: 'Menunggu PM',
    DIAJUKAN: 'Menunggu Logistik',
    DISETUJUI: 'Disetujui',
    DITOLAK: 'Ditolak',
};

/**
 * Sprint 11 Sub 4 — "Pengajuan Barang": barang di luar katalog dan
 * kebutuhan dari Tukang. Logistik meninjau (4 keputusan), PM menyetujui
 * pengajuan Tukang proyeknya, Tukang melihat pengajuannya sendiri, CEO
 * membaca. Server menyaring baris per peran.
 */
export default function MaterialRequestIndex({
    requests,
    filters,
    counts,
    permissions,
    isTukang,
    requestProjects,
    units,
    vendors,
    catalog,
    categories,
    catalogHints,
}: MaterialRequestIndexProps) {
    const [requestOpen, setRequestOpen] = useState(false);
    const [reviewing, setReviewing] = useState<MaterialRequestRow | null>(null);
    const [pmDecision, setPmDecision] = useState<{ line: MaterialRequestRow; decision: 'approve' | 'reject' } | null>(null);

    function applyFilter(next: Partial<MaterialRequestIndexProps['filters']>) {
        const merged = { ...filters, ...next };
        router.get(
            route('logistics.material-requests.index'),
            { status: merged.status === 'pending' ? undefined : merged.status, project_id: merged.project_id ?? undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    /** The status chip (+ rejection reason) — grid cell and phone card alike. */
    function statusOf(line: MaterialRequestRow) {
        return (
            <div className="space-y-1">
                <StatusChip
                    status={line.request_status}
                    label={line.request_status === 'DISETUJUI' && line.review_decision ? DECISION_LABEL[line.review_decision] : STATUS_LABEL[line.request_status]}
                />
                {line.reject_reason && <p className="max-w-[14rem] text-xs text-error-ink">{line.reject_reason}</p>}
            </div>
        );
    }

    /** Logistics reviews DIAJUKAN, the project's PM decides MENUNGGU_PM — grid cell and phone card alike. */
    function actionsOf(line: MaterialRequestRow, block = false) {
        const size = block ? 'default' : 'sm';

        if (permissions.review && line.request_status === 'DIAJUKAN') {
            return (
                <Button size={size} className={block ? 'w-full' : undefined} onClick={() => setReviewing(line)}>
                    Tinjau Pengajuan
                </Button>
            );
        }

        if (permissions.pmDecide && line.request_status === 'MENUNGGU_PM') {
            return (
                <div className={block ? 'grid grid-cols-2 gap-2' : 'flex justify-end gap-1'}>
                    <Button size={size} onClick={() => setPmDecision({ line, decision: 'approve' })}>
                        <Check className="size-4" />
                        Setujui Pengajuan
                    </Button>
                    <Button size={size} variant="outline" onClick={() => setPmDecision({ line, decision: 'reject' })}>
                        <X className="size-4" />
                        Tolak Pengajuan
                    </Button>
                </div>
            );
        }

        return null;
    }

    const columns: ColumnDef<MaterialRequestRow>[] = [
        {
            id: 'item',
            header: 'Barang',
            cell: ({ row }) => {
                const line = row.original;
                const asked = line.requested_snapshot;

                return (
                    <div className="max-w-xs">
                        <p className="font-medium text-daiku-dark">{line.display_name}</p>
                        {asked && asked.name !== line.display_name && (
                            <p className="text-xs text-daiku-muted">Diajukan: {asked.name}</p>
                        )}
                        {line.request_reason && <p className="truncate text-xs text-daiku-muted">“{line.request_reason}”</p>}
                    </div>
                );
            },
        },
        {
            id: 'qty',
            header: 'Jumlah',
            cell: ({ row }) => (
                <span className="tabular-nums">
                    {formatQty(row.original.qty_planned)} {row.original.unit?.code ?? <span className="text-daiku-muted">(satuan?)</span>}
                    {row.original.unit_price && (
                        <span className="block text-xs text-daiku-muted">{formatRupiah(row.original.unit_price)}/satuan</span>
                    )}
                </span>
            ),
        },
        {
            id: 'project',
            header: 'Proyek',
            cell: ({ row }) => (
                <Link
                    href={route('projects.show', { project: row.original.project.id, tab: 'material' })}
                    className="underline decoration-daiku-yellow underline-offset-2"
                >
                    {row.original.project.name}
                </Link>
            ),
        },
        {
            id: 'requester',
            header: 'Pengaju',
            cell: ({ row }) => (
                <div className="text-sm">
                    <p>{row.original.requester?.name ?? '—'}</p>
                    <p className="text-xs text-daiku-muted">
                        {row.original.request_channel === 'TUKANG' ? 'Tukang' : 'Tim'} ·{' '}
                        {formatDateTime(row.original.submitted_at ?? row.original.created_at)}
                    </p>
                </div>
            ),
        },
        {
            id: 'status',
            header: 'Status',
            cell: ({ row }) => statusOf(row.original),
        },
        {
            id: 'actions',
            header: '',
            cell: ({ row }) => actionsOf(row.original),
        },
    ];

    // Sprint 13 #6 — arriving from the topbar "+ Buat" opens the add dialog.
    useCreateParam(permissions.request && requestProjects.length > 0, () => setRequestOpen(true));

    return (
        <AppLayout>
            <Head title="Pengajuan Barang" />

            <PageHeader
                title="Pengajuan Barang"
                icon={ClipboardList}
                description={
                    permissions.review
                        ? 'Barang di luar katalog dan kebutuhan dari Tukang — Logistik yang memutuskan dan menginput barangnya.'
                        : 'Ajukan barang yang tidak ada di katalog. Barang baru boleh dibeli setelah disetujui Logistik.'
                }
                actions={
                    permissions.request &&
                    requestProjects.length > 0 && (
                        <Button size="sm" onClick={() => setRequestOpen(true)}>
                            <PackagePlus className="size-4" />
                            Ajukan Barang
                        </Button>
                    )
                }
            />

            <DataTable
                columns={columns}
                data={requests.data}
                emptyMessage={filters.status === 'pending' ? 'Tidak ada pengajuan yang menunggu keputusan.' : 'Belum ada pengajuan.'}
                pagination={requests}
                // Sprint 13 P2/P3 — PM in the field, Logistics in the warehouse: a card per request.
                mobileCard={(line) => (
                    <div className="flex flex-col gap-2">
                        <div className="flex items-start justify-between gap-3">
                            <div className="min-w-0">
                                <p className="font-medium text-daiku-dark">{line.display_name}</p>
                                <p className="text-sm tabular-nums">
                                    {formatQty(line.qty_planned)} {line.unit?.code ?? '(satuan?)'}
                                    {line.unit_price && <span className="text-daiku-muted"> · {formatRupiah(line.unit_price)}/satuan</span>}
                                </p>
                            </div>
                            {statusOf(line)}
                        </div>
                        {line.request_reason && <p className="text-xs text-daiku-muted">“{line.request_reason}”</p>}
                        <p className="text-xs text-daiku-muted">
                            <Link href={route('projects.show', { project: line.project.id, tab: 'material' })} className="underline decoration-daiku-yellow underline-offset-2">
                                {line.project.name}
                            </Link>{' '}
                            · {line.requester?.name ?? '—'} · {formatDateTime(line.submitted_at ?? line.created_at)}
                        </p>
                        {actionsOf(line, true)}
                    </div>
                )}
                toolbar={
                    <div className="flex flex-wrap items-center gap-2">
                        <Select value={filters.status} onValueChange={(value) => applyFilter({ status: value as StatusFilter })}>
                            <SelectTrigger className="w-52" aria-label="Filter status">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {STATUS_FILTERS.map((option) => (
                                    <SelectItem key={option.value} value={option.value}>
                                        {option.label}
                                        {option.value === 'MENUNGGU_PM' && counts.MENUNGGU_PM > 0 && ` (${counts.MENUNGGU_PM})`}
                                        {option.value === 'DIAJUKAN' && counts.DIAJUKAN > 0 && ` (${counts.DIAJUKAN})`}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-sm text-daiku-muted">
                            {counts.DIAJUKAN} menunggu Logistik · {counts.MENUNGGU_PM} menunggu PM
                        </p>
                    </div>
                }
            />

            <MaterialRequestDialog
                open={requestOpen}
                onOpenChange={setRequestOpen}
                projects={requestProjects}
                minimal={isTukang}
                units={units}
                vendors={vendors}
                catalogHints={catalogHints}
            />
            <MaterialRequestReviewDialog
                line={reviewing}
                onOpenChange={(open) => !open && setReviewing(null)}
                catalog={catalog}
                units={units}
                vendors={vendors}
                categories={categories}
            />
            <PmRequestDecisionDialog
                line={pmDecision?.line ?? null}
                decision={pmDecision?.decision ?? null}
                onOpenChange={(open) => !open && setPmDecision(null)}
            />
        </AppLayout>
    );
}
