import { DataTable } from '@/Components/shared/DataTable';
import { PageHeader } from '@/Components/shared/PageHeader';
import { Pagination } from '@/Components/shared/Pagination';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate } from '@/lib/format';
import type { Material, PaginatedData, Project, StockMovement } from '@/types';
import { Head, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';

interface StockMovementIndexProps {
    movements: PaginatedData<StockMovement>;
    filters: { type?: string; material_id?: string; project_id?: string };
    materials: Pick<Material, 'id' | 'name'>[];
    projects: Pick<Project, 'id' | 'name'>[];
}

const columns: ColumnDef<StockMovement>[] = [
    {
        accessorKey: 'movement_date',
        header: 'Tanggal',
        cell: ({ row }) => <span className="text-daiku-muted">{formatDate(row.original.movement_date)}</span>,
    },
    {
        accessorKey: 'type',
        header: 'Jenis',
        cell: ({ row }) => (
            <StatusChip status={row.original.type} label={row.original.type === 'IN' ? 'Masuk' : 'Keluar'} />
        ),
    },
    {
        id: 'material',
        header: 'Material',
        cell: ({ row }) => <span className="font-medium">{row.original.material?.name}</span>,
    },
    {
        accessorKey: 'qty',
        header: 'Jumlah',
        cell: ({ row }) => (
            <span className={`tabular-nums font-medium ${row.original.type === 'IN' ? 'text-success' : 'text-daiku-dark'}`}>
                {row.original.type === 'IN' ? '+' : '−'}
                {row.original.qty} {row.original.material?.unit}
            </span>
        ),
    },
    {
        accessorKey: 'stock_after',
        header: 'Stok Setelah',
        cell: ({ row }) => <span className="tabular-nums text-daiku-muted">{row.original.stock_after}</span>,
    },
    {
        id: 'project',
        header: 'Proyek',
        cell: ({ row }) => row.original.project?.name ?? '—',
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
 * PRD §4.8 stock ledger (penerimaan + pemakaian per proyek) — PRD §7.1
 * "Material – Stok": CEO/PM read, Logistics records. Append-only: no
 * edit/delete actions exist anywhere for these rows.
 */
export default function StockMovementIndex({ movements, filters, materials, projects }: StockMovementIndexProps) {
    function applyFilter(next: Partial<StockMovementIndexProps['filters']>) {
        router.get(route('logistics.stock-movements.index'), { ...filters, ...next }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout
            breadcrumbs={[
                { label: 'Logistik' },
                { label: 'Material', routeName: 'logistics.materials.index' },
                { label: 'Riwayat Stok' },
            ]}
        >
            <Head title="Riwayat Stok" />

            <PageHeader title="Riwayat Stok" description="Catatan penerimaan barang dan pemakaian material per proyek." />

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <Select
                    value={filters.type ?? 'all'}
                    onValueChange={(value) => applyFilter({ type: value === 'all' ? undefined : value })}
                >
                    <SelectTrigger className="w-40" aria-label="Filter jenis">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Semua jenis</SelectItem>
                        <SelectItem value="IN">Masuk</SelectItem>
                        <SelectItem value="OUT">Keluar</SelectItem>
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

            <DataTable columns={columns} data={movements.data} emptyMessage="Belum ada pergerakan stok." />
            <Pagination paginator={movements} />
        </AppLayout>
    );
}
