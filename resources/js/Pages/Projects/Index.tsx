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
import type { PaginatedData, Project, User } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';

interface ProjectIndexProps {
    projects: PaginatedData<Project>;
    filters: { status?: string; pm_id?: string };
    projectManagers: Pick<User, 'id' | 'name'>[];
}

const STATUS_OPTIONS = ['ACTIVE', 'ON_HOLD', 'COMPLETED', 'CANCELLED'];

function formatRupiah(value: string | number) {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(Number(value));
}

const columns: ColumnDef<Project>[] = [
    {
        accessorKey: 'name',
        header: 'Nama Proyek',
        cell: ({ row }) => (
            <Link href={route('projects.show', { project: row.original.id })} className="font-medium hover:underline">
                {row.original.name}
            </Link>
        ),
    },
    {
        id: 'lead',
        header: 'Klien',
        cell: ({ row }) => row.original.lead?.client_name ?? '—',
    },
    {
        id: 'pm',
        header: 'Project Manager',
        cell: ({ row }) => row.original.pm?.name ?? '—',
    },
    {
        accessorKey: 'status',
        header: 'Status',
        cell: ({ row }) => <StatusChip status={row.original.status} />,
    },
    {
        accessorKey: 'start_date',
        header: 'Mulai',
        cell: ({ row }) =>
            row.original.start_date
                ? new Date(row.original.start_date).toLocaleDateString('id-ID')
                : '—',
    },
    {
        accessorKey: 'contract_value',
        header: 'Nilai Kontrak',
        cell: ({ row }) => formatRupiah(row.original.contract_value),
    },
];

export default function ProjectIndex({ projects, filters, projectManagers }: ProjectIndexProps) {
    function applyFilter(next: Partial<typeof filters>) {
        router.get(
            route('projects.index'),
            { ...filters, ...next },
            { preserveState: true, replace: true },
        );
    }

    return (
        <AppLayout breadcrumbs={[{ label: 'Proyek' }]}>
            <Head title="Proyek" />

            <PageHeader
                title="Proyek"
                description="Daftar proyek eksekusi — dibuat otomatis saat lead dikonfirmasi Deal dari CRM."
            />

            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
                <Select
                    value={filters.status ?? 'all'}
                    onValueChange={(value) =>
                        applyFilter({ status: value === 'all' ? undefined : value })
                    }
                >
                    <SelectTrigger className="sm:w-48">
                        <SelectValue placeholder="Semua status" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Semua status</SelectItem>
                        {STATUS_OPTIONS.map((status) => (
                            <SelectItem key={status} value={status}>
                                {status.replace('_', ' ')}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                <Select
                    value={filters.pm_id ?? 'all'}
                    onValueChange={(value) =>
                        applyFilter({ pm_id: value === 'all' ? undefined : value })
                    }
                >
                    <SelectTrigger className="sm:w-56">
                        <SelectValue placeholder="Semua PM" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Semua Project Manager</SelectItem>
                        {projectManagers.map((pm) => (
                            <SelectItem key={pm.id} value={String(pm.id)}>
                                {pm.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <DataTable
                columns={columns}
                data={projects.data}
                emptyMessage="Belum ada proyek. Proyek baru muncul saat lead dikonfirmasi Deal dari CRM."
            />
        </AppLayout>
    );
}
