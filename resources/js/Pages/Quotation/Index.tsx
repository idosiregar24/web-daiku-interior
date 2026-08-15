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
import type { PaginatedData, Quotation, QuotationStatus } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';

interface QuotationIndexProps {
    quotations: PaginatedData<Quotation & { lead: { id: number; client_name: string } }>;
    filters: { status?: string };
}

const STATUS_OPTIONS: QuotationStatus[] = [
    'DRAFT', 'SUBMITTED', 'CEO_REVIEW', 'PM_REVIEW', 'SENT_TO_CLIENT', 'APPROVED', 'REJECTED',
];

function formatRupiah(value: string | number) {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(Number(value));
}

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
        cell: ({ row }) =>
            row.original.valid_until ? new Date(row.original.valid_until).toLocaleDateString('id-ID') : '—',
    },
];

/**
 * Quotation list — the "Quotation" sidebar entry's landing page (Sprint 2
 * Week 4 discoverability fix, same root cause as Design's — see
 * .claude/plan/README.md). New quotations are only ever created as a side
 * effect of a Design's Client ACC, not from here.
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
        <AppLayout breadcrumbs={[{ label: 'Quotation' }]}>
            <Head title="Quotation" />

            <PageHeader
                title="Quotation"
                description="Daftar RAB/penawaran — dibuka otomatis saat desain di-ACC klien."
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
                data={quotations.data}
                emptyMessage="Belum ada quotation. Dibuka otomatis saat desain di-ACC klien."
            />
        </AppLayout>
    );
}
