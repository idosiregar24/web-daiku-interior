import { StatusChip } from '@/Components/shared/StatusChip';
import { DataTable } from '@/Components/shared/DataTable';
import { PageHeader } from '@/Components/shared/PageHeader';
import { Input } from '@/Components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import type { Lead, PageProps, PaginatedData } from '@/types';
import { Head, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { useState } from 'react';

interface LeadIndexProps {
    leads: PaginatedData<Lead>;
    filters: { status?: string; priority?: string; search?: string };
}

const STATUS_OPTIONS = ['FOLLOW_UP', 'DEAL_DESAIN', 'CLOSING', 'LOST'];
const PRIORITY_OPTIONS = ['HOT', 'WARM', 'COLD'];

const columns: ColumnDef<Lead>[] = [
    {
        accessorKey: 'client_name',
        header: 'Nama Klien',
    },
    {
        accessorKey: 'contact',
        header: 'Kontak',
    },
    {
        accessorKey: 'source',
        header: 'Sumber',
    },
    {
        accessorKey: 'priority',
        header: 'Prioritas',
        cell: ({ row }) => <StatusChip status={row.original.priority} />,
    },
    {
        accessorKey: 'status',
        header: 'Status',
        cell: ({ row }) => <StatusChip status={row.original.status} />,
    },
    {
        id: 'assignee',
        header: 'PIC Marketing',
        cell: ({ row }) => row.original.assignee?.name ?? '—',
    },
    {
        accessorKey: 'follow_up_date',
        header: 'Follow-up',
        cell: ({ row }) => {
            const date = row.original.follow_up_date;
            if (!date) return '—';

            const isOverdue =
                new Date(date) < new Date() &&
                !['LOST', 'CLOSING'].includes(row.original.status);

            return (
                <span className={isOverdue ? 'font-medium text-error' : ''}>
                    {new Date(date).toLocaleDateString('id-ID')}
                </span>
            );
        },
    },
];

export default function LeadIndex({ leads, filters }: LeadIndexProps) {
    const [search, setSearch] = useState(filters.search ?? '');

    function applyFilter(next: Partial<typeof filters>) {
        router.get(
            route('crm.leads.index'),
            { ...filters, ...next },
            { preserveState: true, replace: true },
        );
    }

    return (
        <AppLayout header={<h2 className="text-base font-semibold text-daiku-dark">CRM</h2>}>
            <Head title="Data Lead" />

            <PageHeader
                title="Data Lead"
                description="Kelola calon klien dan pipeline penjualan."
            />

            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
                <Input
                    placeholder="Cari nama klien..."
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') applyFilter({ search });
                    }}
                    onBlur={() => applyFilter({ search })}
                    className="sm:max-w-xs"
                />

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
                    value={filters.priority ?? 'all'}
                    onValueChange={(value) =>
                        applyFilter({ priority: value === 'all' ? undefined : value })
                    }
                >
                    <SelectTrigger className="sm:w-48">
                        <SelectValue placeholder="Semua prioritas" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Semua prioritas</SelectItem>
                        {PRIORITY_OPTIONS.map((priority) => (
                            <SelectItem key={priority} value={priority}>
                                {priority}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <DataTable
                columns={columns}
                data={leads.data}
                emptyMessage="Belum ada lead. Tambah lead baru untuk mulai mengisi pipeline."
            />
        </AppLayout>
    );
}
