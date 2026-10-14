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
import type { PaginatedData, Project, ProjectOpening, ProjectOpeningFormOptions, User } from '@/types';
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
    /** Approved RAB Proyek waiting for "Buka Proyek" (CEO / PM see it) — Sprint 23 K13: `can_open` = the approving PM. */
    pendingOpenings: ProjectOpening[];
    /** The form's pickers — null unless this user may open one of them. */
    openingForm: ProjectOpeningFormOptions | null;
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
        cell: ({ row }) => <StatusChip domain="project" status={row.original.status} />,
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

export default function ProjectIndex({ projects, filters, projectManagers, pendingOpenings, openingForm }: ProjectIndexProps) {
    // "Proyek siap dibuka" in Perlu Tindakan links here with `?opening=<id>` — open its form straight away.
    const [opening, setOpening] = useState<ProjectOpening | null>(() => {
        const requested = typeof window === 'undefined' ? null : Number(new URLSearchParams(window.location.search).get('opening'));

        return pendingOpenings.find((pending) => pending.id === requested && pending.can_open) ?? null;
    });

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
                description="Daftar proyek eksekusi — dibuka PM setelah klien menyetujui RAB Proyek."
                actions={<DashboardLinkButton routeName="projects.dashboard" label="Monitor Proyek" icon={AlertTriangle} roles={['CEO', 'PM', 'ASISTEN_PM']} />}
            />

            {pendingOpenings.length > 0 && (
                <SectionCard
                    title="Menunggu Dibuka"
                    icon={Hourglass}
                    description="RAB Proyek yang sudah disetujui klien — PM yang menyetujui RAB itu melengkapi data lalu membuka proyeknya."
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
                                {pending.can_open && openingForm ? (
                                    <Button size="sm" onClick={() => setOpening(pending)}>
                                        Buka Proyek
                                    </Button>
                                ) : (
                                    <StatusChip
                                        domain="projectOpening"
                                        status={pending.status}
                                        waitingOn={pending.opener?.is_active ? `PM ${pending.opener.name}` : 'PM'}
                                    />
                                )}
                            </li>
                        ))}
                    </ul>
                </SectionCard>
            )}

            {opening && openingForm && (
                <OpenProjectDialog open onOpenChange={(open) => !open && setOpening(null)} opening={opening} options={openingForm} />
            )}

            <DataTable
                columns={columns}
                data={projects.data}
                emptyMessage="Belum ada proyek. Proyek dibuka PM setelah klien menyetujui RAB Proyek."
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
