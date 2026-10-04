import { DataTable } from '@/Components/shared/DataTable';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate, formatQty, formatRupiah } from '@/lib/format';
import type { Material, PaginatedData, Project, StockMovement, StockMovementType } from '@/types';
import { Head, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { History } from 'lucide-react';

interface StockMovementIndexProps {
    movements: PaginatedData<StockMovement>;
    filters: { type?: string; material_id?: string; project_id?: string };
    materials: Pick<Material, 'id' | 'name'>[];
    projects: Pick<Project, 'id' | 'name'>[];
}

const TYPE_LABEL: Record<StockMovementType, string> = {
    IN: 'Masuk',
    OUT: 'Keluar ke proyek',
    RETURN: 'Retur dari proyek',
    MERGE_OUT: 'Digabung keluar',
    MERGE_IN: 'Gabungan masuk',
};

/** Movements that take stock away; everything else adds to it. */
const OUTGOING: StockMovementType[] = ['OUT', 'MERGE_OUT'];

const columns: ColumnDef<StockMovement>[] = [
    {
        accessorKey: 'movement_date',
        header: 'Tanggal',
        cell: ({ row }) => <span className="text-daiku-muted">{formatDate(row.original.movement_date)}</span>,
    },
    {
        accessorKey: 'type',
        header: 'Jenis',
        cell: ({ row }) => <StatusChip status={row.original.type} label={TYPE_LABEL[row.original.type]} />,
    },
    {
        id: 'material',
        header: 'Material',
        cell: ({ row }) => <span className="font-medium">{row.original.material?.name}</span>,
    },
    {
        accessorKey: 'qty',
        header: 'Jumlah',
        cell: ({ row }) => {
            const out = OUTGOING.includes(row.original.type);

            return (
                <span className={`tabular-nums font-medium ${out ? 'text-daiku-dark' : 'text-success-ink'}`}>
                    {out ? '−' : '+'}
                    {formatQty(row.original.qty)} {row.original.material?.unit?.code}
                </span>
            );
        },
    },
    {
        accessorKey: 'stock_after',
        header: 'Stok Setelah',
        cell: ({ row }) => <span className="tabular-nums text-daiku-muted">{formatQty(row.original.stock_after)}</span>,
    },
    {
        id: 'project',
        header: 'Proyek',
        // OUT: the receiving project. RETURN: where the leftover came from (Sprint 11).
        cell: ({ row }) =>
            row.original.project ? (
                <div>
                    <p>{row.original.project.name}</p>
                    <p className="text-xs text-daiku-muted">{row.original.type === 'RETURN' ? 'Asal retur' : 'Tujuan'}</p>
                </div>
            ) : (
                '—'
            ),
    },
    {
        accessorKey: 'unit_cost',
        header: 'Harga/Satuan',
        cell: ({ row }) => (
            <span className="tabular-nums text-daiku-muted">
                {row.original.unit_cost === null ? '—' : formatRupiah(row.original.unit_cost)}
            </span>
        ),
    },
    {
        accessorKey: 'note',
        header: 'Catatan',
        cell: ({ row }) => <span className="text-daiku-muted">{row.original.note ?? '—'}</span>,
    },
    {
        id: 'recorder',
        header: 'Dicatat oleh',
        cell: ({ row }) => row.original.recorder?.name ?? '—',
    },
];

/**
 * PRD §4.8 stock ledger (penerimaan, barang keluar ke proyek, and — since
 * Sprint 11 — leftovers returned from a project). PRD §7.1 "Material –
 * Stok": CEO/PM read, Logistics records. Append-only: no edit/delete
 * actions exist anywhere for these rows.
 */
export default function StockMovementIndex({ movements, filters, materials, projects }: StockMovementIndexProps) {
    function applyFilter(next: Partial<StockMovementIndexProps['filters']>) {
        router.get(route('logistics.stock-movements.index'), { ...filters, ...next }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout>
            <Head title="Riwayat Stok" />

            <PageHeader
                title="Riwayat Stok"
                icon={History}
                description="Catatan penerimaan barang, barang keluar ke proyek, dan retur sisa material dari proyek."
            />

            <DataTable
                columns={columns}
                data={movements.data}
                emptyMessage="Belum ada pergerakan stok."
                pagination={movements}
                toolbar={
                    <div className="flex flex-wrap items-center gap-2">
                        <Select
                            value={filters.type ?? 'all'}
                            onValueChange={(value) => applyFilter({ type: value === 'all' ? undefined : value })}
                        >
                            <SelectTrigger className="w-48" aria-label="Filter jenis">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua jenis</SelectItem>
                                {(Object.keys(TYPE_LABEL) as StockMovementType[]).map((type) => (
                                    <SelectItem key={type} value={type}>
                                        {TYPE_LABEL[type]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select
                            value={filters.material_id ? String(filters.material_id) : 'all'}
                            onValueChange={(value) => applyFilter({ material_id: value === 'all' ? undefined : value })}
                        >
                            <SelectTrigger className="w-56" aria-label="Filter material">
                                <SelectValue placeholder="Semua material" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua material</SelectItem>
                                {materials.map((material) => (
                                    <SelectItem key={material.id} value={String(material.id)}>
                                        {material.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select
                            value={filters.project_id ? String(filters.project_id) : 'all'}
                            onValueChange={(value) => applyFilter({ project_id: value === 'all' ? undefined : value })}
                        >
                            <SelectTrigger className="w-56" aria-label="Filter proyek">
                                <SelectValue placeholder="Semua proyek" />
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
                }
            />
        </AppLayout>
    );
}
