import { StatusChip } from '@/Components/shared/StatusChip';
import { DataTable } from '@/Components/shared/DataTable';
import { PageHeader } from '@/Components/shared/PageHeader';
import { Button } from '@/Components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { Input } from '@/Components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { ConfirmDealDialog } from '@/Components/modules/crm/ConfirmDealDialog';
import { LeadFormDialog } from '@/Components/modules/crm/LeadFormDialog';
import { LeadStatusDialog } from '@/Components/modules/crm/LeadStatusDialog';
import { OpenDesignDialog } from '@/Components/modules/crm/OpenDesignDialog';
import AppLayout from '@/Layouts/AppLayout';
import type { Lead, LeadSourceOption, PageProps, PaginatedData, User } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { MoreHorizontal, Plus } from 'lucide-react';
import { useState } from 'react';

interface LeadIndexProps {
    leads: PaginatedData<Lead>;
    filters: { status?: string; priority?: string; search?: string };
    marketers: Pick<User, 'id' | 'name'>[];
    projectManagers: Pick<User, 'id' | 'name'>[];
    designers: Pick<User, 'id' | 'name'>[];
    leadSources: LeadSourceOption[];
}

const STATUS_OPTIONS = ['FOLLOW_UP', 'DEAL_DESAIN', 'CLOSING', 'LOST'];
const PRIORITY_OPTIONS = ['HOT', 'WARM', 'COLD'];

export default function LeadIndex({ leads, filters, marketers, projectManagers, designers, leadSources }: LeadIndexProps) {
    const { auth } = usePage<PageProps>().props;
    const role = auth.user?.role;
    const canOpenDesign = role === 'DESIGNER' || role === 'SUPERADMIN';

    const [search, setSearch] = useState(filters.search ?? '');

    const [formOpen, setFormOpen] = useState(false);
    const [statusOpen, setStatusOpen] = useState(false);
    const [dealOpen, setDealOpen] = useState(false);
    const [designOpen, setDesignOpen] = useState(false);
    const [activeLead, setActiveLead] = useState<Lead | null>(null);

    function applyFilter(next: Partial<typeof filters>) {
        router.get(
            route('crm.leads.index'),
            { ...filters, ...next },
            { preserveState: true, replace: true },
        );
    }

    function openCreate() {
        setActiveLead(null);
        setFormOpen(true);
    }

    function openEdit(lead: Lead) {
        setActiveLead(lead);
        setFormOpen(true);
    }

    function openStatus(lead: Lead) {
        setActiveLead(lead);
        setStatusOpen(true);
    }

    function openDeal(lead: Lead) {
        setActiveLead(lead);
        setDealOpen(true);
    }

    function openDesign(lead: Lead) {
        setActiveLead(lead);
        setDesignOpen(true);
    }

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
        {
            id: 'actions',
            header: '',
            cell: ({ row }) => {
                const lead = row.original;

                return (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon-sm">
                                <MoreHorizontal className="size-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem onSelect={() => openEdit(lead)}>Edit Lead</DropdownMenuItem>
                            <DropdownMenuItem
                                disabled={lead.status === 'LOST' || lead.status === 'CLOSING'}
                                onSelect={() => openStatus(lead)}
                            >
                                Ubah Status
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                disabled={lead.status !== 'DEAL_DESAIN'}
                                onSelect={() => openDeal(lead)}
                            >
                                Konfirmasi Deal
                            </DropdownMenuItem>
                            {canOpenDesign && lead.status === 'DEAL_DESAIN' && (
                                lead.design ? (
                                    <DropdownMenuItem asChild>
                                        <Link href={route('design.show', { design: lead.design.id })}>
                                            Lihat Desain
                                        </Link>
                                    </DropdownMenuItem>
                                ) : (
                                    <DropdownMenuItem onSelect={() => openDesign(lead)}>
                                        Buka Desain
                                    </DropdownMenuItem>
                                )
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                );
            },
        },
    ];

    return (
        <AppLayout breadcrumbs={[{ label: 'CRM' }, { label: 'Data Lead' }]}>
            <Head title="Data Lead" />

            <PageHeader
                title="Data Lead"
                description="Kelola calon klien dan pipeline penjualan."
                actions={
                    <div className="flex items-center gap-2">
                        {(role === 'CEO' || role === 'MARKETING' || role === 'SUPERADMIN') && (
                            <Button variant="outline" asChild>
                                <Link href={route('crm.dashboard')}>Statistik Pipeline</Link>
                            </Button>
                        )}
                        <Button onClick={openCreate}>
                            <Plus className="size-4" />
                            Tambah Lead
                        </Button>
                    </div>
                }
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

            <LeadFormDialog
                open={formOpen}
                onOpenChange={setFormOpen}
                editing={activeLead}
                marketers={marketers}
                leadSources={leadSources}
            />
            <LeadStatusDialog open={statusOpen} onOpenChange={setStatusOpen} lead={activeLead} />
            <ConfirmDealDialog
                open={dealOpen}
                onOpenChange={setDealOpen}
                lead={activeLead}
                projectManagers={projectManagers}
            />
            {canOpenDesign && (
                <OpenDesignDialog
                    open={designOpen}
                    onOpenChange={setDesignOpen}
                    lead={activeLead}
                    designers={designers}
                />
            )}
        </AppLayout>
    );
}
