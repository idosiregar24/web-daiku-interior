import { StatusChip } from '@/Components/shared/StatusChip';
import { DataTable } from '@/Components/shared/DataTable';
import { PageHeader } from '@/Components/shared/PageHeader';
import { SearchInput } from '@/Components/shared/SearchInput';
import { Button } from '@/Components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { LeadFormDialog } from '@/Components/modules/crm/LeadFormDialog';
import { LeadStatusDialog } from '@/Components/modules/crm/LeadStatusDialog';
import { QuotationDecisionDialog } from '@/Components/modules/quotation/QuotationDecisionDialog';
import AppLayout from '@/Layouts/AppLayout';
import type { Lead, LeadCategoryOption, LeadSourceOption, PageProps, PaginatedData, User } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { BarChart3, MoreHorizontal, Plus, Users } from 'lucide-react';
import { useState } from 'react';

interface LeadIndexProps {
    leads: PaginatedData<Lead>;
    filters: {
        status?: string;
        priority?: string;
        search?: string;
        lead_source_id?: string;
        lead_category_id?: string;
    };
    marketers: Pick<User, 'id' | 'name'>[];
    leadSources: Pick<LeadSourceOption, 'id' | 'name'>[];
    leadCategories: Pick<LeadCategoryOption, 'id' | 'name'>[];
}

const STATUS_OPTIONS = ['FOLLOW_UP', 'DEAL_DESAIN', 'CLOSING', 'LOST'];
const PRIORITY_OPTIONS = ['HOT', 'WARM', 'COLD'];

export default function LeadIndex({ leads, filters, marketers, leadSources, leadCategories }: LeadIndexProps) {
    const { auth } = usePage<PageProps>().props;
    const role = auth.user?.role;
    // PRD §4.1: only Marketing and CEO create/edit leads — mirrors the
    // `role:CEO|MARKETING` write routes so other readers don't see actions
    // that would 403.
    const canManage = role === 'CEO' || role === 'MARKETING' || role === 'SUPERADMIN';

    const [search, setSearch] = useState(filters.search ?? '');

    const [formOpen, setFormOpen] = useState(false);
    const [statusOpen, setStatusOpen] = useState(false);
    const [clientRejectOpen, setClientRejectOpen] = useState(false);
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

    function openClientReject(lead: Lead) {
        setActiveLead(lead);
        setClientRejectOpen(true);
    }

    const columns: ColumnDef<Lead>[] = [
        {
            accessorKey: 'client_name',
            header: 'Nama Klien',
            cell: ({ row }) => (
                <Link
                    href={route('crm.leads.show', { lead: row.original.id })}
                    className="font-medium text-foreground underline-offset-4 hover:underline hover:decoration-daiku-yellow"
                >
                    {row.original.client_name}
                </Link>
            ),
        },
        {
            accessorKey: 'contact',
            header: 'Kontak',
        },
        {
            id: 'source',
            header: 'Sumber',
            // FK row first; the legacy string covers leads whose master row was removed.
            cell: ({ row }) => row.original.lead_source?.name ?? row.original.source,
        },
        {
            id: 'category',
            header: 'Kategori',
            cell: ({ row }) => row.original.lead_category?.name ?? row.original.category ?? '—',
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
            id: 'next_follow_up',
            header: 'Follow-up',
            // Sprint 12: the next open FU-n (Lead::scopeWithNextFollowUp()).
            cell: ({ row }) => {
                const date = row.original.next_follow_up_date;
                if (!date) return row.original.follow_ups_count ? <span className="text-daiku-muted">{row.original.follow_ups_count} FU selesai</span> : '—';

                const isOverdue =
                    new Date(date) < new Date() &&
                    !['LOST', 'CLOSING'].includes(row.original.status);

                return (
                    <span className={isOverdue ? 'font-medium text-error-ink' : ''}>
                        {new Date(date).toLocaleDateString('id-ID')}
                        <span className="block text-xs font-normal text-daiku-muted">{row.original.follow_ups_count ?? 0} FU tercatat</span>
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
                            <Button variant="ghost" size="icon-sm" aria-label={`Aksi untuk ${lead.client_name}`}>
                                <MoreHorizontal className="size-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-auto min-w-40">
                            <DropdownMenuItem asChild>
                                <Link href={route('crm.leads.show', { lead: lead.id })}>Lihat Detail</Link>
                            </DropdownMenuItem>
                            {canManage && (
                                <>
                                    <DropdownMenuItem onSelect={() => openEdit(lead)}>Edit Lead</DropdownMenuItem>
                                    <DropdownMenuItem
                                        disabled={lead.status === 'LOST' || lead.status === 'CLOSING'}
                                        onSelect={() => openStatus(lead)}
                                    >
                                        Ubah Status
                                    </DropdownMenuItem>
                                    {lead.quotation?.status === 'SENT_TO_CLIENT' && (
                                        <DropdownMenuItem onSelect={() => openClientReject(lead)}>
                                            Klien Menolak Penawaran
                                        </DropdownMenuItem>
                                    )}
                                </>
                            )}
                            {lead.design && (
                                <DropdownMenuItem asChild>
                                    <Link href={route('design.show', { design: lead.design.id })}>Lihat Desain</Link>
                                </DropdownMenuItem>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                );
            },
        },
    ];

    return (
        <AppLayout>
            <Head title="Data Lead" />

            <PageHeader
                title="Data Lead"
                icon={Users}
                description="Kelola calon klien dan pipeline penjualan."
                actions={
                    <div className="flex items-center gap-2">
                        {(role === 'CEO' || role === 'MARKETING' || role === 'SUPERADMIN') && (
                            <Button variant="outline" asChild>
                                <Link href={route('crm.dashboard')}>
                                    <BarChart3 className="size-4" />
                                    Statistik Pipeline
                                </Link>
                            </Button>
                        )}
                        {canManage && (
                            <Button onClick={openCreate}>
                                <Plus className="size-4" />
                                Tambah Lead
                            </Button>
                        )}
                    </div>
                }
            />

            <DataTable
                columns={columns}
                data={leads.data}
                emptyMessage="Belum ada lead. Tambah lead baru untuk mulai mengisi pipeline."
                pagination={leads}
                toolbar={
                    <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                        <SearchInput
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

                        <Select
                            value={filters.lead_source_id ?? 'all'}
                            onValueChange={(value) =>
                                applyFilter({ lead_source_id: value === 'all' ? undefined : value })
                            }
                        >
                            <SelectTrigger className="sm:w-48">
                                <SelectValue placeholder="Semua sumber" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua sumber</SelectItem>
                                {leadSources.map((source) => (
                                    <SelectItem key={source.id} value={String(source.id)}>
                                        {source.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select
                            value={filters.lead_category_id ?? 'all'}
                            onValueChange={(value) =>
                                applyFilter({ lead_category_id: value === 'all' ? undefined : value })
                            }
                        >
                            <SelectTrigger className="sm:w-48">
                                <SelectValue placeholder="Semua kategori" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua kategori</SelectItem>
                                {leadCategories.map((category) => (
                                    <SelectItem key={category.id} value={String(category.id)}>
                                        {category.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                }
            />

            <LeadFormDialog
                open={formOpen}
                onOpenChange={setFormOpen}
                editing={activeLead}
                marketers={marketers}
                leadSources={leadSources}
                leadCategories={leadCategories}
            />
            <LeadStatusDialog open={statusOpen} onOpenChange={setStatusOpen} lead={activeLead} />
            {canManage && activeLead?.quotation && (
                <QuotationDecisionDialog
                    open={clientRejectOpen}
                    onOpenChange={setClientRejectOpen}
                    quotation={activeLead.quotation}
                    role="CLIENT"
                    decision="reject"
                    clientName={activeLead.client_name}
                />
            )}
        </AppLayout>
    );
}
