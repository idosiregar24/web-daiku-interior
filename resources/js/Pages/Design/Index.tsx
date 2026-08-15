import { DataTable } from '@/Components/shared/DataTable';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import type { Design, DesignStatus, PaginatedData } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';

interface DesignIndexProps {
    designs: PaginatedData<Design & { lead: { id: number; client_name: string } }>;
    filters: { status?: string };
}

const STATUS_OPTIONS: DesignStatus[] = [
    'BRIEF', 'DESAIN', 'WAITING_ACC_DESAIN', 'REVISI_DESAIN', 'ACC_DESAIN',
    'GAMBAR_RAB', 'PEMBUATAN_PENAWARAN', 'WAITING_ACC_PENAWARAN', 'PRODUKSI',
    'REJECT_PRODUKSI', 'DONE_PRODUKSI', 'HOLD_CLIENT', 'REVISI_CLIENT',
];

const columns: ColumnDef<Design & { lead: { id: number; client_name: string } }>[] = [
    {
        id: 'client',
        header: 'Klien',
        cell: ({ row }) => (
            <Link href={route('design.show', { design: row.original.id })} className="font-medium hover:underline">
                {row.original.lead.client_name}
            </Link>
        ),
    },
    {
        id: 'pic',
        header: 'PIC',
        cell: ({ row }) => row.original.pic?.name ?? '—',
    },
    {
        accessorKey: 'jenis_project',
        header: 'Jenis Project',
        cell: ({ row }) => row.original.jenis_project?.replace('_', ' ') ?? '—',
    },
    {
        accessorKey: 'status',
        header: 'Status',
        cell: ({ row }) => <StatusChip status={row.original.status} />,
    },
    {
        accessorKey: 'deadline',
        header: 'Deadline',
        cell: ({ row }) => {
            const design = row.original;
            if (!design.deadline) return '—';

            return (
                <span>
                    {new Date(design.deadline).toLocaleDateString('id-ID')}
                    {design.delay_hari > 0 && (
                        <span className="ml-2 text-xs font-medium text-error">+{design.delay_hari}h</span>
                    )}
                </span>
            );
        },
    },
    {
        id: 'client_acc',
        header: 'Client ACC',
        cell: ({ row }) => (row.original.client_acc ? 'Sudah' : 'Belum'),
    },
];

/**
 * Design list — the "Desain" sidebar entry's landing page (Sprint 2 Week
 * 4 discoverability fix; see .claude/plan/README.md). New designs are
 * still opened from the CRM Lead index's "Buka Desain" action, not here.
 */
export default function DesignIndex({ designs, filters }: DesignIndexProps) {
    function applyFilter(next: Partial<typeof filters>) {
        router.get(
            route('design.index'),
            { ...filters, ...next },
            { preserveState: true, replace: true },
        );
    }

    return (
        <AppLayout breadcrumbs={[{ label: 'Desain' }]}>
            <Head title="Desain" />

            <PageHeader
                title="Desain"
                description="Daftar proyek desain — dibuka dari lead CRM berstatus DEAL_DESAIN."
            />

            <div className="mb-4">
                <Select
                    value={filters.status ?? 'all'}
                    onValueChange={(value) => applyFilter({ status: value === 'all' ? undefined : value })}
                >
                    <SelectTrigger className="sm:w-56">
                        <SelectValue placeholder="Semua status" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Semua status</SelectItem>
                        {STATUS_OPTIONS.map((status) => (
                            <SelectItem key={status} value={status}>
                                {status.replace(/_/g, ' ')}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <DataTable
                columns={columns}
                data={designs.data}
                emptyMessage="Belum ada proyek desain. Buka dari lead CRM berstatus DEAL_DESAIN."
            />
        </AppLayout>
    );
}
