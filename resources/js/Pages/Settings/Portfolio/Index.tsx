import { portfolioBasePayload, portfolioBaseSchema, type PortfolioBaseValues } from '@/Components/modules/settings/companyProfileSchema';
import { ConsentMark, PortfolioBaseFields, PublishChip } from '@/Components/modules/settings/PortfolioFields';
import { DataTable } from '@/Components/shared/DataTable';
import { ModuleTabs } from '@/Components/shared/ModuleTabs';
import { PageHeader } from '@/Components/shared/PageHeader';
import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import { SearchInput } from '@/Components/shared/SearchInput';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogClose, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Form } from '@/Components/ui/form';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import type { CityOption, PaginatedData, PortfolioRow, ProjectTypeOption } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { ExternalLink, GalleryHorizontalEnd, ImageIcon, Pencil, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';

interface PortfolioIndexProps {
    items: PaginatedData<PortfolioRow>;
    filters: { search?: string; type?: string; status?: string };
    projectTypes: ProjectTypeOption[];
    cities: CityOption[];
}

const EMPTY: PortfolioBaseValues = { title: '', project_type: '', city_id: '', location_label: '', year: '' };

function Cover({ row, className }: { row: PortfolioRow; className: string }) {
    return row.cover_thumb_url ? (
        <img src={row.cover_thumb_url} alt="" loading="lazy" className={`${className} rounded-md object-cover`} />
    ) : (
        <span className={`${className} flex items-center justify-center rounded-md bg-daiku-gray`}>
            <ImageIcon className="size-4 text-daiku-muted/60" aria-hidden />
        </span>
    );
}

/**
 * Sprint 20 Sub 04 (K2) — ⚙ Pengaturan → Portofolio, CEO + Marketing.
 * What the public site shows under /portofolio and on the service pages.
 * A new item starts as a draft; photos, the client's consent and
 * publishing happen on its edit page.
 */
export default function PortfolioIndex({ items, filters, projectTypes, cities }: PortfolioIndexProps) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');
    const [processing, setProcessing] = useState(false);

    const form = useForm<PortfolioBaseValues>({ resolver: zodResolver(portfolioBaseSchema), defaultValues: EMPTY });

    function applyFilter(next: Partial<PortfolioIndexProps['filters']>) {
        router.get(route('settings.portfolio.index'), { ...filters, ...next }, { preserveState: true, preserveScroll: true, replace: true });
    }

    useEffect(() => {
        if (search === (filters.search ?? '')) {
            return;
        }

        const timeout = setTimeout(() => applyFilter({ search: search || undefined }), 350);

        return () => clearTimeout(timeout);
    }, [search]);

    function openForm() {
        form.reset(EMPTY);
        setOpen(true);
    }

    function onSubmit(values: PortfolioBaseValues) {
        // Redirects to the new item's edit page.
        router.post(route('settings.portfolio.store'), portfolioBasePayload(values), {
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: (errors) =>
                Object.entries(errors).forEach(([field, message]) => form.setError(field as keyof PortfolioBaseValues, { message })),
        });
    }

    /** Row actions — one source for the grid column and the phone card. */
    function actionsOf(row: PortfolioRow) {
        return (
            <div className="flex justify-end gap-1">
                {row.public_url && (
                    <Button asChild variant="ghost" size="icon-sm" aria-label={`Lihat ${row.title} di situs`} title="Lihat di situs">
                        <a href={row.public_url} target="_blank" rel="noopener noreferrer">
                            <ExternalLink className="size-4" />
                        </a>
                    </Button>
                )}
                <Button asChild variant="ghost" size="icon-sm" aria-label={`Edit ${row.title}`} title="Edit">
                    <Link href={route('settings.portfolio.edit', { portfolio: row.id })}>
                        <Pencil className="size-4" />
                    </Link>
                </Button>
            </div>
        );
    }

    const columns: ColumnDef<PortfolioRow>[] = [
        {
            accessorKey: 'title',
            header: 'Portofolio',
            cell: ({ row }) => (
                <Link href={route('settings.portfolio.edit', { portfolio: row.original.id })} className="flex items-center gap-3">
                    <Cover row={row.original} className="h-12 w-16 shrink-0" />
                    <span className="min-w-0">
                        <span className="block font-medium text-daiku-dark hover:underline">{row.original.title}</span>
                        <span className="block text-xs text-daiku-muted">{row.original.year ?? 'Tahun belum diisi'}</span>
                    </span>
                </Link>
            ),
        },
        { accessorKey: 'type_label', header: 'Jenis' },
        {
            accessorKey: 'place',
            header: 'Lokasi',
            cell: ({ row }) => <span className="text-daiku-muted">{row.original.place ?? '—'}</span>,
        },
        {
            accessorKey: 'photos_count',
            header: 'Foto',
            cell: ({ row }) => (
                <span className={row.original.photos_count === 0 ? 'text-warning-ink' : 'tabular-nums'}>
                    {row.original.photos_count === 0 ? 'Belum ada' : row.original.photos_count}
                </span>
            ),
        },
        {
            accessorKey: 'is_published',
            header: 'Status',
            cell: ({ row }) => (
                <div className="flex flex-col items-start gap-1">
                    <PublishChip published={row.original.is_published} />
                    {!row.original.is_published && <ConsentMark consent={row.original.client_consent} />}
                </div>
            ),
        },
        { id: 'actions', header: '', cell: ({ row }) => actionsOf(row.original) },
    ];

    const filtered = Boolean(filters.search || filters.type || filters.status);

    return (
        <AppLayout>
            <Head title="Portofolio" />

            <PageHeader
                title="Portofolio"
                icon={GalleryHorizontalEnd}
                description="Proyek yang ditampilkan di situs publik. Hanya yang berstatus Terbit — dengan izin klien dan minimal satu foto — yang terlihat pengunjung."
                actions={
                    <Button size="sm" onClick={openForm}>
                        <Plus className="size-4" />
                        Tambah Portofolio
                    </Button>
                }
            />

            <ModuleTabs />

            <DataTable
                columns={columns}
                data={items.data}
                emptyMessage={
                    filtered
                        ? 'Tidak ada portofolio yang cocok dengan filter.'
                        : 'Belum ada portofolio. Tambahkan di sini, atau pakai "Jadikan Portofolio" di proyek yang sudah selesai.'
                }
                pagination={items}
                mobileCard={(row) => (
                    <div className="flex gap-3">
                        <Link href={route('settings.portfolio.edit', { portfolio: row.id })} className="shrink-0">
                            <Cover row={row} className="h-16 w-20" />
                        </Link>
                        <div className="min-w-0 flex-1">
                            <Link href={route('settings.portfolio.edit', { portfolio: row.id })} className="block font-medium text-daiku-dark">
                                {row.title}
                            </Link>
                            <p className="text-xs text-daiku-muted">
                                {[row.type_label, row.place, row.year].filter(Boolean).join(' · ')}
                                {' · '}
                                {row.photos_count} foto
                            </p>
                            <div className="mt-1.5 flex flex-wrap items-center gap-2">
                                <PublishChip published={row.is_published} />
                                {!row.is_published && <ConsentMark consent={row.client_consent} />}
                            </div>
                        </div>
                        {actionsOf(row)}
                    </div>
                )}
                toolbar={
                    <div className="flex flex-wrap items-center gap-2">
                        <SearchInput
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Cari judul…"
                            className="w-full sm:w-64"
                            aria-label="Cari portofolio"
                        />
                        <Select value={filters.type ?? 'all'} onValueChange={(value) => applyFilter({ type: value === 'all' ? undefined : value })}>
                            <SelectTrigger className="w-44" aria-label="Filter jenis">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua jenis</SelectItem>
                                {projectTypes.map((type) => (
                                    <SelectItem key={type.value} value={type.value}>
                                        {type.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select value={filters.status ?? 'all'} onValueChange={(value) => applyFilter({ status: value === 'all' ? undefined : value })}>
                            <SelectTrigger className="w-36" aria-label="Filter status">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua status</SelectItem>
                                <SelectItem value="published">Terbit</SelectItem>
                                <SelectItem value="draft">Draf</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                }
            />

            <Dialog open={open} onOpenChange={setOpen}>
                <ResponsiveDialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Tambah Portofolio</DialogTitle>
                        <DialogDescription>Tersimpan sebagai draf. Setelah itu unggah foto, isi cerita proyek, lalu terbitkan.</DialogDescription>
                    </DialogHeader>
                    <Form {...form}>
                        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                            <PortfolioBaseFields control={form.control} projectTypes={projectTypes} cities={cities} autoFocus />
                            <DialogFooter>
                                <DialogClose asChild>
                                    <Button type="button" variant="outline">
                                        Batal
                                    </Button>
                                </DialogClose>
                                <Button type="submit" disabled={processing}>
                                    Buat Draf
                                </Button>
                            </DialogFooter>
                        </form>
                    </Form>
                </ResponsiveDialogContent>
            </Dialog>
        </AppLayout>
    );
}
