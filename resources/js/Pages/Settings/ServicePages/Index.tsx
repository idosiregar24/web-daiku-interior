import { PublishChip } from '@/Components/modules/settings/PortfolioFields';
import { DataTable } from '@/Components/shared/DataTable';
import { ModuleTabs } from '@/Components/shared/ModuleTabs';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate } from '@/lib/format';
import type { ServicePageRow } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { ExternalLink, LayoutTemplate, Pencil } from 'lucide-react';

/** "/layanan/kitchen-set-pekanbaru" — the path is what people recognise. */
function pathOf(url: string): string {
    try {
        return new URL(url).pathname;
    } catch {
        return url;
    }
}

function StatusCell({ row }: { row: ServicePageRow }) {
    return (
        <div className="flex flex-wrap items-center gap-1.5">
            <PublishChip published={row.is_published} />
            {row.still_placeholder && <StatusChip status="PLACEHOLDER" label="Masih teks contoh" tone="warning" />}
        </div>
    );
}

/**
 * Sprint 20 Sub 06 (K4) — ⚙ Pengaturan → Halaman Layanan, CEO + Marketing.
 * One page per service of ServiceCatalog (fixed — no add/delete here).
 */
export default function ServicePageIndex({ pages }: { pages: ServicePageRow[] }) {
    /** Row actions — one source for the grid column and the phone card. */
    function actionsOf(row: ServicePageRow) {
        return (
            <div className="flex justify-end gap-1">
                <Button asChild variant="ghost" size="icon-sm" aria-label={`Lihat halaman ${row.title}`} title="Lihat halaman">
                    <a href={row.public_url} target="_blank" rel="noopener noreferrer">
                        <ExternalLink className="size-4" />
                    </a>
                </Button>
                <Button asChild variant="ghost" size="icon-sm" aria-label={`Edit ${row.title}`} title="Edit">
                    <Link href={route('settings.service-pages.edit', { servicePage: row.id })}>
                        <Pencil className="size-4" />
                    </Link>
                </Button>
            </div>
        );
    }

    const columns: ColumnDef<ServicePageRow>[] = [
        {
            accessorKey: 'title',
            header: 'Layanan',
            cell: ({ row }) => (
                <Link href={route('settings.service-pages.edit', { servicePage: row.original.id })} className="block">
                    <span className="block font-medium text-daiku-dark hover:underline">{row.original.title}</span>
                    <span className="block text-xs text-daiku-muted">{pathOf(row.original.public_url)}</span>
                </Link>
            ),
        },
        {
            accessorKey: 'types',
            header: 'Jenis Proyek',
            cell: ({ row }) => <span className="text-daiku-muted">{row.original.types.join(', ')}</span>,
        },
        {
            accessorKey: 'portfolio_count',
            header: 'Portofolio Terbit',
            cell: ({ row }) =>
                row.original.portfolio_count > 0 ? (
                    <span className="tabular-nums">{row.original.portfolio_count}</span>
                ) : (
                    <span className="text-warning-ink">Belum ada</span>
                ),
        },
        { accessorKey: 'is_published', header: 'Status', cell: ({ row }) => <StatusCell row={row.original} /> },
        {
            accessorKey: 'updated_at',
            header: 'Diubah',
            cell: ({ row }) => <span className="text-daiku-muted">{formatDate(row.original.updated_at)}</span>,
        },
        { id: 'actions', header: '', cell: ({ row }) => actionsOf(row.original) },
    ];

    return (
        <AppLayout>
            <Head title="Halaman Layanan" />

            <PageHeader
                title="Halaman Layanan"
                icon={LayoutTemplate}
                description="Satu halaman per layanan, untuk pencarian Google seperti “kitchen set” + kota. Halaman draf tetap bisa dibuka dari beranda, tetapi tidak diindeks Google sampai diterbitkan — dan baru bisa terbit setelah teks contohnya diganti tulisan sendiri."
            />

            <ModuleTabs />

            <DataTable
                columns={columns}
                data={pages}
                emptyMessage="Belum ada halaman layanan."
                mobileCard={(row) => (
                    <div className="flex items-start gap-3">
                        <Link href={route('settings.service-pages.edit', { servicePage: row.id })} className="min-w-0 flex-1">
                            <p className="font-medium text-daiku-dark">{row.title}</p>
                            <p className="text-xs text-daiku-muted">
                                {row.types.join(', ')} · {row.portfolio_count} portofolio terbit
                            </p>
                            <div className="mt-1.5">
                                <StatusCell row={row} />
                            </div>
                        </Link>
                        {actionsOf(row)}
                    </div>
                )}
            />
        </AppLayout>
    );
}
