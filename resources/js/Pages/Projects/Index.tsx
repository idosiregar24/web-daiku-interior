import { formatRupiah } from '@/lib/format';
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
import type { PaginatedData, Project, ProjectOpening, User } from '@/types';
import { OpenProjectDialog } from '@/Components/modules/projects/OpenProjectDialog';
import { SectionCard } from '@/Components/shared/SectionCard';
import { Button } from '@/Components/ui/button';
import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { AlertTriangle, FolderKanban, Hourglass } from 'lucide-react';
import { DashboardLinkButton } from '@/Components/modules/dashboards/DashboardLinkButton';

interface ProjectIndexProps {
    projects: PaginatedData<Project>;
    filters: { status?: string; pm_id?: string };
    projectManagers: Pick<User, 'id' | 'name'>[];
    /** Sprint 12 #19 — approved RAB Proyek waiting for "Buka Proyek" (CEO / PM see it, CEO opens). */
    pendingOpenings: ProjectOpening[];
    canOpenProjects: boolean;
    assistantPms: Pick<User, 'id' | 'name'>[];
}

const STATUS_OPTIONS = ['ACTIVE', 'ON_HOLD', 'COMPLETED', 'CANCELLED'];

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

export default function ProjectIndex({ projects, filters, projectManagers, pendingOpenings, canOpenProjects, assistantPms }: ProjectIndexProps) {
    const [opening, setOpening] = useState<ProjectOpening | null>(null);

    function applyFilter(next: Partial<typeof filters>) {
        router.get(
            route('projects.index'),
            { ...filters, ...next },
            { preserveState: true, replace: true },
        );
    }

    return (
        <AppLayout>
            <Head title="Proyek" />

            <PageHeader
                title="Proyek"
                icon={FolderKanban}
                description="Daftar proyek eksekusi — dibuka CEO setelah klien menyetujui RAB Proyek."
                actions={<DashboardLinkButton routeName="projects.dashboard" label="Monitor Proyek" icon={AlertTriangle} roles={['CEO', 'PM', 'ASISTEN_PM']} />}
            />

            {pendingOpenings.length > 0 && (
                <SectionCard
                    title="Menunggu Dibuka"
                    icon={Hourglass}
                    description="RAB Proyek yang sudah disetujui klien — CEO menentukan PM dan membuka proyeknya."
                    className="mb-6"
                    flush
                >
                    <ul className="divide-y divide-border">
                        {pendingOpenings.map((pending) => (
                            <li key={pending.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm sm:px-5">
                                <span>
                                    <span className="font-medium text-daiku-dark">{pending.lead.client_name}</span>{' '}
                                    <span className="text-daiku-muted">
                                        · {formatRupiah(pending.quotation.total_amount)}
                                        {pending.quotation.client_approved_at &&
                                            ` · disetujui ${new Date(pending.quotation.client_approved_at).toLocaleDateString('id-ID')}`}
                                    </span>
                                </span>
                                {canOpenProjects ? (
                                    <Button size="sm" onClick={() => setOpening(pending)}>
                                        Buka Proyek
                                    </Button>
                                ) : (
                                    <span className="text-xs text-daiku-muted">Menunggu CEO</span>
                                )}
                            </li>
                        ))}
                    </ul>
                </SectionCard>
            )}

            {opening && (
                <OpenProjectDialog
                    open
                    onOpenChange={(open) => !open && setOpening(null)}
                    opening={opening}
                    projectManagers={projectManagers}
                    assistantPms={assistantPms}
                />
            )}

            <DataTable
                columns={columns}
                data={projects.data}
                emptyMessage="Belum ada proyek. Proyek dibuka CEO setelah klien menyetujui RAB Proyek."
                pagination={projects}
                toolbar={
                    <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
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
                }
            />
        </AppLayout>
    );
}
