import { formatDate, formatRupiah } from '@/lib/format';
import { QUOTATION_TYPE_LABEL, quotationTitle } from '@/Components/modules/quotation/labels';
import { isQuotationExpired } from '@/Components/modules/quotation/QuotationExpiryNotice';
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
import type { PaginatedData, Quotation, QuotationStatus, QuotationType } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { BarChart3, FileText } from 'lucide-react';
import { DashboardLinkButton } from '@/Components/modules/dashboards/DashboardLinkButton';

interface QuotationIndexProps {
    quotations: PaginatedData<Quotation & { lead: { id: number; client_name: string } }>;
    filters: { status?: string; type?: string };
}

const STATUS_OPTIONS: QuotationStatus[] = [
    'DIMINTA', 'DRAFT', 'SUBMITTED', 'WAITING_CEO', 'APPROVED_INTERNAL', 'READY_TO_SEND', 'SENT_TO_CLIENT', 'CLIENT_APPROVED', 'CANCELLED',
];

const columns: ColumnDef<Quotation & { lead: { id: number; client_name: string } }>[] = [
    {
        id: 'client',
        header: 'Klien',
        cell: ({ row }) => (
            <Link href={route('quotations.show', { quotation: row.original.id })} className="font-medium hover:underline">
                {row.original.lead.client_name}
            </Link>
        ),
    },
    {
        accessorKey: 'type',
        header: 'Jenis',
        cell: ({ row }) => (
            <span>
                {quotationTitle(row.original)}
                {row.original.requester && (
                    <span className="block text-xs text-daiku-muted">diminta {row.original.requester.name}</span>
                )}
            </span>
        ),
    },
    {
        accessorKey: 'status',
        header: 'Status',
        cell: ({ row }) => <StatusChip status={row.original.status} />,
    },
    {
        accessorKey: 'total_amount',
        header: 'Total',
        cell: ({ row }) => formatRupiah(row.original.total_amount),
    },
    {
        accessorKey: 'version',
        header: 'Versi',
    },
    {
        accessorKey: 'valid_until',
        header: 'Berlaku Sampai',
        cell: ({ row }) => {
            const quotation = row.original;
            if (!quotation.valid_until) return '—';

            return isQuotationExpired(quotation) ? (
                <span className="font-medium text-error-ink">{formatDate(quotation.valid_until)} · kedaluwarsa</span>
            ) : (
                formatDate(quotation.valid_until)
            );
        },
    },
];

/**
 * Quotation list — the "Quotation" sidebar entry's landing page (Sprint 2
 * Week 4 discoverability fix, same root cause as Design's — see
 * .claude/plan/README.md). Quotations are opened by Marketing's "Minta RAB"
 * on the lead (Sprint 12 #7 — Jasa Survey / Jasa Desain / Proyek) or by a
 * Design's Client ACC (project RAB), not from here.
 */
export default function QuotationIndex({ quotations, filters }: QuotationIndexProps) {
    function applyFilter(next: Partial<typeof filters>) {
        router.get(
            route('quotations.index'),
            { ...filters, ...next },
            { preserveState: true, replace: true },
        );
    }

    return (
        <AppLayout>
            <Head title="Quotation" />

            <PageHeader
                title="Quotation"
                icon={FileText}
                description="Daftar RAB Jasa Survey, Jasa Desain, dan Proyek — diminta Marketing dari halaman lead atau dibuka saat desain di-ACC klien."
                actions={<DashboardLinkButton routeName="quotations.dashboard" label="Dashboard Quotation" icon={BarChart3} roles={['CEO', 'ESTIMATOR']} />}
            />

            <DataTable
                columns={columns}
                data={quotations.data}
                emptyMessage="Belum ada quotation. Marketing meminta RAB dari halaman lead."
                pagination={quotations}
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
                        <Select
                            value={filters.type ?? 'all'}
                            onValueChange={(value) => applyFilter({ type: value === 'all' ? undefined : value })}
                        >
                            <SelectTrigger className="sm:w-56">
                                <SelectValue placeholder="Semua jenis" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua jenis</SelectItem>
                                {(Object.keys(QUOTATION_TYPE_LABEL) as QuotationType[]).map((type) => (
                                    <SelectItem key={type} value={type}>
                                        {QUOTATION_TYPE_LABEL[type]}
                                    </SelectItem>
                                ))}
                                {/* Sprint 14 Sub 02 — RAB Proyek with their own name (Quotation::FILTER_CUSTOM). */}
                                <SelectItem value="CUSTOM">RAB Lainnya (nama khusus)</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                }
            />
        </AppLayout>
    );
}
