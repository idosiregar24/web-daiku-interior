import { AssetFormDialog, CONDITION_LABELS } from '@/Components/modules/logistics/AssetFormDialog';
import { DataTable } from '@/Components/shared/DataTable';
import { PageHeader } from '@/Components/shared/PageHeader';
import { Pagination } from '@/Components/shared/Pagination';
import { StatCard } from '@/Components/shared/StatCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate, formatRupiah, formatRupiahCompact } from '@/lib/format';
import type { Asset, AssetCondition, PaginatedData } from '@/types';
import { Head, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { AlertTriangle, Download, Package, Pencil, Plus, Trash2, Wallet } from 'lucide-react';
import { useEffect, useState } from 'react';

interface AssetIndexProps {
    assets: PaginatedData<Asset>;
    filters: { search?: string; condition?: string; category?: string };
    categories: string[];
    summary: { totalItems: number; totalValue: number; damagedCount: number };
    canManage: boolean;
}

/** PRD §4.8 "Aset Inventaris" — §7.1: CEO/PM/Finance read, Logistics CRUD. */
export default function AssetIndex({ assets, filters, categories, summary, canManage }: AssetIndexProps) {
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<Asset | null>(null);
    const [search, setSearch] = useState(filters.search ?? '');

    function applyFilter(next: Partial<AssetIndexProps['filters']>) {
        router.get(route('logistics.assets.index'), { ...filters, ...next }, { preserveState: true, replace: true });
    }

    useEffect(() => {
        if (search === (filters.search ?? '')) {
            return;
        }

        const timeout = setTimeout(() => applyFilter({ search: search || undefined }), 350);

        return () => clearTimeout(timeout);
    }, [search]);

    function destroy(asset: Asset) {
        if (!confirm(`Hapus aset "${asset.name}"?`)) return;
        router.delete(route('logistics.assets.destroy', { asset: asset.id }), { preserveScroll: true });
    }

    const columns: ColumnDef<Asset>[] = [
        {
            accessorKey: 'name',
            header: 'Aset',
            cell: ({ row }) => (
                <div>
                    <p className="font-medium text-daiku-dark">{row.original.name}</p>
                    <p className="text-xs text-daiku-muted">{row.original.category ?? '—'}</p>
                </div>
            ),
        },
        {
            accessorKey: 'condition',
            header: 'Kondisi',
            cell: ({ row }) => (
                <StatusChip status={row.original.condition} label={CONDITION_LABELS[row.original.condition]} />
            ),
        },
        {
            accessorKey: 'location',
            header: 'Lokasi',
            cell: ({ row }) => row.original.location ?? '—',
        },
        {
            accessorKey: 'purchase_date',
            header: 'Tanggal Beli',
            cell: ({ row }) => <span className="text-daiku-muted">{formatDate(row.original.purchase_date)}</span>,
        },
        {
            accessorKey: 'value',
            header: 'Nilai',
            cell: ({ row }) => <span className="tabular-nums">{row.original.value ? formatRupiah(row.original.value) : '—'}</span>,
        },
        ...(canManage
            ? [
                  {
                      id: 'actions',
                      header: '',
                      cell: ({ row }) => (
                          <div className="flex justify-end gap-1">
                              <Button
                                  variant="ghost"
                                  size="icon-sm"
                                  aria-label={`Edit ${row.original.name}`}
                                  onClick={() => {
                                      setEditing(row.original);
                                      setFormOpen(true);
                                  }}
                              >
                                  <Pencil className="size-4" />
                              </Button>
                              <Button
                                  variant="ghost"
                                  size="icon-sm"
                                  aria-label={`Hapus ${row.original.name}`}
                                  onClick={() => destroy(row.original)}
                              >
                                  <Trash2 className="size-4" />
                              </Button>
                          </div>
                      ),
                  } satisfies ColumnDef<Asset>,
              ]
            : []),
    ];

    return (
        <AppLayout breadcrumbs={[{ label: 'Logistik' }, { label: 'Aset Inventaris' }]}>
            <Head title="Aset Inventaris" />

            <PageHeader
                title="Aset Inventaris"
                description="Alat, mesin, dan kendaraan perusahaan beserta kondisi dan lokasinya."
                actions={
                    <>
                        <Button variant="outline" size="sm" asChild>
                            <a href={route('logistics.assets.export')}>
                                <Download className="size-4" />
                                Export Excel
                            </a>
                        </Button>
                        {canManage && (
                            <Button
                                size="sm"
                                onClick={() => {
                                    setEditing(null);
                                    setFormOpen(true);
                                }}
                            >
                                <Plus className="size-4" />
                                Tambah Aset
                            </Button>
                        )}
                    </>
                }
            />

            <div className="mb-4 grid gap-4 sm:grid-cols-3">
                <StatCard label="Jumlah Aset" value={summary.totalItems} icon={Package} />
                <StatCard label="Total Nilai Aset" value={formatRupiahCompact(summary.totalValue)} icon={Wallet} />
                <StatCard
                    label="Aset Rusak"
                    value={summary.damagedCount}
                    icon={AlertTriangle}
                    tone={summary.damagedCount > 0 ? 'error' : 'default'}
                />
            </div>

            <div className="mb-4 flex flex-wrap items-center gap-2">
                <Input
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Cari nama atau lokasi…"
                    className="w-full sm:w-64"
                    aria-label="Cari aset"
                />
                <Select
                    value={filters.condition ?? 'all'}
                    onValueChange={(value) => applyFilter({ condition: value === 'all' ? undefined : value })}
                >
                    <SelectTrigger className="w-40" aria-label="Filter kondisi">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Semua kondisi</SelectItem>
                        {(Object.keys(CONDITION_LABELS) as AssetCondition[]).map((condition) => (
                            <SelectItem key={condition} value={condition}>
                                {CONDITION_LABELS[condition]}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Select
                    value={filters.category ?? 'all'}
                    onValueChange={(value) => applyFilter({ category: value === 'all' ? undefined : value })}
                >
                    <SelectTrigger className="w-44" aria-label="Filter kategori">
                        <SelectValue placeholder="Semua kategori" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Semua kategori</SelectItem>
                        {categories.map((category) => (
                            <SelectItem key={category} value={category}>
                                {category}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <DataTable columns={columns} data={assets.data} emptyMessage="Belum ada aset." />
            <Pagination paginator={assets} />

            {canManage && (
                <AssetFormDialog open={formOpen} onOpenChange={setFormOpen} asset={editing} categories={categories} />
            )}
        </AppLayout>
    );
}
