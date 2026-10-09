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
import { BarChart3, Inbox, Palette, Send } from 'lucide-react';
import { DashboardLinkButton } from '@/Components/modules/dashboards/DashboardLinkButton';
import { AssignDesignDialog, type AssignableDesign } from '@/Components/modules/design/AssignDesignDialog';
import { SectionCard } from '@/Components/shared/SectionCard';
import { Button } from '@/Components/ui/button';
import { formatRupiah } from '@/lib/format';
import { useState } from 'react';

/** Sprint 12 #15/#16 — a design the Kepala Desain is waiting on (payment) or has to assign. */
interface QueuedDesign {
    id: number;
    status: Extract<DesignStatus, 'MENUNGGU_BAYAR' | 'MENUNGGU_PENUGASAN'>;
    brief_note: string | null;
    created_at: string;
    lead: { id: number; client_name: string };
    quotation: { id: number; total_amount: string; client_approved_at: string | null } | null;
}

interface DesignIndexProps {
    designs: PaginatedData<Design & { lead: { id: number; client_name: string } }>;
    /** Sprint 22 — `ready` = only designs the architect handed to Marketing, still unsent. */
    filters: { status?: string; ready?: string };
    /** Kepala Desain / SuperAdmin only, else null. */
    queue: QueuedDesign[] | null;
    architects: { id: number; name: string }[];
}

const STATUS_OPTIONS: DesignStatus[] = [
    'MENUNGGU_BAYAR', 'MENUNGGU_PENUGASAN', 'BRIEF', 'DESAIN', 'WAITING_ACC_DESAIN', 'REVISI_DESAIN', 'ACC_DESAIN',
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
        header: 'PIC Arsitek',
        cell: ({ row }) => row.original.pic?.name ?? '—',
    },
    {
        id: 'staff',
        header: 'Asisten',
        cell: ({ row }) => {
            const staff = row.original.staff ?? [];
            if (staff.length === 0) return '—';

            return (
                <span title={staff.map((member) => member.name + (member.pivot.role_note ? ` (${member.pivot.role_note})` : '')).join(', ')}>
                    {staff.map((member) => member.name).join(', ')}
                </span>
            );
        },
    },
    {
        accessorKey: 'jenis_project',
        header: 'Jenis Project',
        cell: ({ row }) => row.original.jenis_project?.replace('_', ' ') ?? '—',
    },
    {
        accessorKey: 'status',
        header: 'Status',
        cell: ({ row }) => (
            <span className="flex flex-wrap items-center gap-1">
                <StatusChip status={row.original.status} />
                {row.original.ready_for_client_at && <StatusChip status="SIAP_DIKIRIM" label="Siap dikirim" tone="warning" />}
            </span>
        ),
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
                        <span className="ml-2 text-xs font-medium text-error-ink">+{design.delay_hari}h</span>
                    )}
                </span>
            );
        },
    },
    {
        accessorKey: 'revision_count',
        header: 'Revisi',
        cell: ({ row }) => (row.original.revision_count > 0 ? `${row.original.revision_count}x` : '—'),
    },
    {
        id: 'client_acc',
        header: 'ACC Klien',
        cell: ({ row }) => (row.original.client_acc ? 'Sudah' : 'Belum'),
    },
];

/**
 * Design list — the "Desain" sidebar entry's landing page. Since Sprint 12
 * a design is opened by the client's approval of a RAB Jasa Desain; the
 * Kepala Desain sees the locked ones on top (waiting for payment / to be
 * assigned) and assigns them here. A plain architect lists only their own.
 */
export default function DesignIndex({ designs, filters, queue, architects }: DesignIndexProps) {
    const [assigning, setAssigning] = useState<AssignableDesign | null>(null);

    function applyFilter(next: Partial<typeof filters>) {
        router.get(
            route('design.index'),
            { ...filters, ...next },
            { preserveState: true, replace: true },
        );
    }

    return (
        <AppLayout>
            <Head title="Desain" />

            <PageHeader
                title="Desain"
                icon={Palette}
                description="Proyek desain — dibuka otomatis saat klien menyetujui RAB Jasa Desain, dikerjakan setelah dibayar dan ditugaskan Kepala Desain."
                actions={<DashboardLinkButton routeName="design.dashboard" label="KPI Desain" icon={BarChart3} roles={['CEO', 'DESIGNER']} />}
            />

            {queue && (
                <SectionCard
                    title="Antrean Kepala Desain"
                    icon={Inbox}
                    description="Desain terkunci sampai pembayaran jasa desain diverifikasi Finance, lalu ditugaskan ke arsitek."
                    className="mb-6"
                    flush
                >
                    {queue.length === 0 ? (
                        <p className="px-4 py-3 text-sm text-daiku-muted sm:px-5">Tidak ada desain yang menunggu.</p>
                    ) : (
                        <ul className="divide-y divide-border">
                            {queue.map((queued) => (
                                <li key={queued.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm sm:px-5">
                                    <span className="min-w-0">
                                        <Link href={route('design.show', { design: queued.id })} className="font-medium text-daiku-dark hover:underline">
                                            {queued.lead.client_name}
                                        </Link>{' '}
                                        <span className="text-daiku-muted">
                                            {queued.quotation && `· RAB Jasa Desain ${formatRupiah(queued.quotation.total_amount)}`}
                                            {queued.quotation?.client_approved_at &&
                                                ` · disetujui ${new Date(queued.quotation.client_approved_at).toLocaleDateString('id-ID')}`}
                                        </span>
                                    </span>
                                    <span className="flex items-center gap-2">
                                        <StatusChip status={queued.status} />
                                        {queued.status === 'MENUNGGU_PENUGASAN' && (
                                            <Button size="sm" onClick={() => setAssigning({ id: queued.id, client_name: queued.lead.client_name })}>
                                                Tugaskan Desain
                                            </Button>
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </SectionCard>
            )}

            {assigning && (
                <AssignDesignDialog
                    open
                    onOpenChange={(open) => !open && setAssigning(null)}
                    design={assigning}
                    architects={architects}
                />
            )}

            <DataTable
                columns={columns}
                data={designs.data}
                emptyMessage="Belum ada proyek desain. Desain dibuka otomatis saat klien menyetujui RAB Jasa Desain."
                pagination={designs}
                toolbar={
                    <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
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
                        <Button
                            type="button"
                            variant={filters.ready ? 'default' : 'outline'}
                            onClick={() => applyFilter({ ready: filters.ready ? undefined : '1' })}
                            aria-pressed={Boolean(filters.ready)}
                        >
                            <Send className="size-4" />
                            Siap dikirim ke klien
                        </Button>
                    </div>
                }
            />
        </AppLayout>
    );
}
