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
import { ProjectPhaseMini } from '@/Components/modules/projects/ProjectPhaseStepper';
import { domainStatusLabel } from '@/lib/waitingOn';

interface ProjectIndexProps {
    projects: PaginatedData<Project>;
    /** Sprint 27 P6a — `phase` (5 phases or SELESAI); `supervised` = '1' for a QA's "Yang saya awasi". */
    filters: { status?: string; pm_id?: string; phase?: string; supervised?: string };
    /** QA/QC users — the "Yang saya awasi" filter (on by default). */
    canFilterSupervised: boolean;
    projectManagers: Pick<User, 'id' | 'name'>[];
    /** Approved RAB Proyek waiting for "Buka Proyek" (CEO / PM see it) — Sprint 23 K13: `can_open` = the approving PM. */
    pendingOpenings: ProjectOpening[];
    /** The form's pickers — null unless this user may open one of them. */
    openingForm: ProjectOpeningFormOptions | null;
}

const STATUS_OPTIONS = ['ACTIVE', 'ON_HOLD', 'COMPLETED', 'CANCELLED'];

/** Sprint 27 P6a — the list's "Tahap" filter (Project::scopeByPhase). */
const PHASE_OPTIONS: { value: string; label: string }[] = [
    ...(['GAMBAR_KERJA', 'TM', 'PERSIAPAN', 'PRODUKSI', 'PEMASANGAN'] as const).map((phase) => ({
        value: phase,
        label: domainStatusLabel('projectPhase', phase) ?? phase,
    })),
    { value: 'SELESAI', label: 'Selesai' },
];

/** Status + (Sprint 27 P6b) where a running project is in the flow — shared by the grid and the phone card. */
function ProjectStatusCell({ project }: { project: Project }) {
    return (
        <div className="space-y-1.5">
            <StatusChip domain="project" status={project.status} />
            {(project.status === 'ACTIVE' || project.status === 'ON_HOLD') && <ProjectPhaseMini project={project} />}
        </div>
    );
}

function actualActivity(project: Project): string | null {
    const total = project.tasks_count ?? 0;

    return total === 0 ? null : `${Math.floor(((project.done_tasks_count ?? 0) * 100) / total)}%`;
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
        cell: ({ row }) => <ProjectStatusCell project={row.original} />,
    },
    {
        // Sprint 24 D11 — actual activity: DONE ÷ all daily targets.
        id: 'progress',
        header: 'Actual Activity',
        cell: ({ row }) => {
            const value = actualActivity(row.original);

            return value === null ? <span className="text-muted-foreground">—</span> : <span className="tabular-nums">{value}</span>;
        },
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

export default function ProjectIndex({ projects, filters, canFilterSupervised, projectManagers, pendingOpenings, openingForm }: ProjectIndexProps) {
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
                mobileCard={(project) => (
                    <Link href={route('projects.show', { project: project.id })} className="block space-y-2 p-4">
                        <div>
                            <p className="font-medium text-foreground">{project.name}</p>
                            <p className="text-xs text-muted-foreground">
                                {project.lead?.client_name ?? '—'} · PM {project.pm?.name ?? '—'}
                            </p>
                        </div>
                        <ProjectStatusCell project={project} />
                        <p className="text-xs text-muted-foreground">
                            Actual activity <span className="font-medium text-foreground tabular-nums">{actualActivity(project) ?? '—'}</span>
                        </p>
                    </Link>
                )}
                toolbar={
                    <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                        {canFilterSupervised && (
                            <Select value={filters.supervised === '0' ? '0' : '1'} onValueChange={(value) => applyFilter({ supervised: value })}>
                                <SelectTrigger className="sm:w-48" aria-label="Saring proyek yang diawasi">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="1">Yang saya awasi</SelectItem>
                                    <SelectItem value="0">Semua proyek</SelectItem>
                                </SelectContent>
                            </Select>
                        )}

                        <Select value={filters.phase ?? 'all'} onValueChange={(value) => applyFilter({ phase: value === 'all' ? undefined : value })}>
                            <SelectTrigger className="sm:w-48" aria-label="Saring tahap">
                                <SelectValue placeholder="Semua tahap" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua tahap</SelectItem>
                                {PHASE_OPTIONS.map((option) => (
                                    <SelectItem key={option.value} value={option.value}>
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

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
