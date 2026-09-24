import { MaterialFormDialog } from '@/Components/modules/logistics/MaterialFormDialog';
import { StockMovementDialog } from '@/Components/modules/logistics/StockMovementDialog';
import { DataTable } from '@/Components/shared/DataTable';
import { PageHeader } from '@/Components/shared/PageHeader';
import { Pagination } from '@/Components/shared/Pagination';
import { StatCard } from '@/Components/shared/StatCard';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Switch } from '@/Components/ui/switch';
import AppLayout from '@/Layouts/AppLayout';
import { formatRupiah, formatRupiahCompact } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Material, PaginatedData, Project, StockMovementType } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import {
    AlertTriangle,
    ArrowDownToLine,
    ArrowUpFromLine,
    Boxes,
    Download,
    History,
    MoreHorizontal,
    Pencil,
    Plus,
    Trash2,
    TrendingUp,
    Wallet,
} from 'lucide-react';
import { useEffect, useState } from 'react';

interface MaterialIndexProps {
    materials: PaginatedData<Material>;
    filters: { search: string; category: string; low_stock: boolean };
    categories: string[];
    summary: { totalItems: number; lowStockCount: number; stockValue: number; potentialMargin: number };
    canManage: boolean;
    projects: Pick<Project, 'id' | 'name'>[];
}

/**
 * PRD §4.8 Material Master + Margin Tracker + stock alert (CSV Sprint 5:
 * "tabel + margin profit + alert stok minimum — badge merah jika <
 * min_stock"). Read for CEO/EST/PM, Logistics manages.
 */
export default function MaterialIndex({ materials, filters, categories, summary, canManage, projects }: MaterialIndexProps) {
    const [formOpen, setFormOpen] = useState(false);
    const [editing, setEditing] = useState<Material | null>(null);
    const [movement, setMovement] = useState<{ material: Material; type: StockMovementType } | null>(null);
    const [search, setSearch] = useState(filters.search);

    function applyFilter(next: Partial<MaterialIndexProps['filters']>) {
        const merged = { ...filters, ...next };

        router.get(
            route('logistics.materials.index'),
            {
                search: merged.search || undefined,
                category: merged.category || undefined,
                low_stock: merged.low_stock ? 1 : undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    // Debounced server-side search (frontend-standards.md §4: filtering on the backend).
    useEffect(() => {
        if (search === filters.search) {
            return;
        }

        const timeout = setTimeout(() => applyFilter({ search }), 350);

        return () => clearTimeout(timeout);
    }, [search]);

    function openCreate() {
        setEditing(null);
        setFormOpen(true);
    }

    function destroy(material: Material) {
        if (!confirm(`Hapus material "${material.name}"?`)) return;
        router.delete(route('logistics.materials.destroy', { material: material.id }), { preserveScroll: true });
    }

    const columns: ColumnDef<Material>[] = [
        {
            accessorKey: 'name',
            header: 'Material',
            cell: ({ row }) => (
                <div>
                    <p className="font-medium text-daiku-dark">{row.original.name}</p>
                    <p className="text-xs text-daiku-muted">{row.original.category ?? 'Tanpa kategori'}</p>
                </div>
            ),
        },
        {
            accessorKey: 'cost_price',
            header: 'Harga Modal',
            cell: ({ row }) => <span className="tabular-nums">{formatRupiah(row.original.cost_price)}</span>,
        },
        {
            accessorKey: 'sell_price',
            header: 'Harga Jual',
            cell: ({ row }) => <span className="tabular-nums">{formatRupiah(row.original.sell_price)}</span>,
        },
        {
            accessorKey: 'margin',
            header: 'Margin',
            cell: ({ row }) => (
                <div className="tabular-nums">
                    <p className={cn('font-medium', row.original.margin < 0 ? 'text-error' : 'text-success')}>
                        {formatRupiah(row.original.margin)}
                    </p>
                    {row.original.margin_percent !== null && (
                        <p className="text-xs text-daiku-muted">{row.original.margin_percent}%</p>
                    )}
                </div>
            ),
        },
        {
            accessorKey: 'stock',
            header: 'Stok',
            cell: ({ row }) => {
                const material = row.original;

                return (
                    <div className="flex items-center gap-2 tabular-nums">
                        <span className={cn('font-medium', material.is_low_stock && 'text-error')}>
                            {material.stock} {material.unit}
                        </span>
                        {material.is_low_stock && (
                            <Badge
                                variant="secondary"
                                className="border-transparent bg-error/10 text-error"
                                title={`Di bawah stok minimum (${material.min_stock} ${material.unit})`}
                            >
                                <AlertTriangle className="size-3" />
                                Min {material.min_stock}
                            </Badge>
                        )}
                    </div>
                );
            },
        },
        ...(canManage
            ? [
                  {
                      id: 'actions',
                      header: '',
                      cell: ({ row }) => {
                          const material = row.original;

                          return (
                              <DropdownMenu>
                                  <DropdownMenuTrigger asChild>
                                      <Button variant="ghost" size="icon-sm" aria-label={`Aksi untuk ${material.name}`}>
                                          <MoreHorizontal className="size-4" />
                                      </Button>
                                  </DropdownMenuTrigger>
                                  <DropdownMenuContent align="end">
                                      <DropdownMenuItem onClick={() => setMovement({ material, type: 'IN' })}>
                                          <ArrowDownToLine className="size-4" />
                                          Terima Barang
                                      </DropdownMenuItem>
                                      <DropdownMenuItem
                                          disabled={material.stock === 0}
                                          onClick={() => setMovement({ material, type: 'OUT' })}
                                      >
                                          <ArrowUpFromLine className="size-4" />
                                          Pemakaian Proyek
                                      </DropdownMenuItem>
                                      <DropdownMenuItem asChild>
                                          <Link href={route('logistics.stock-movements.index', { material_id: material.id })}>
                                              <History className="size-4" />
                                              Riwayat Stok
                                          </Link>
                                      </DropdownMenuItem>
                                      <DropdownMenuSeparator />
                                      <DropdownMenuItem
                                          onClick={() => {
                                              setEditing(material);
                                              setFormOpen(true);
                                          }}
                                      >
                                          <Pencil className="size-4" />
                                          Edit
                                      </DropdownMenuItem>
                                      <DropdownMenuItem variant="destructive" onClick={() => destroy(material)}>
                                          <Trash2 className="size-4" />
                                          Hapus
                                      </DropdownMenuItem>
                                  </DropdownMenuContent>
                              </DropdownMenu>
                          );
                      },
                  } satisfies ColumnDef<Material>,
              ]
            : []),
    ];

    return (
        <AppLayout breadcrumbs={[{ label: 'Logistik' }, { label: 'Material' }]}>
            <Head title="Material" />

            <PageHeader
                title="Material"
                description="Daftar material, harga modal & jual, margin, dan stok."
                actions={
                    <>
                        <Button variant="outline" size="sm" asChild>
                            <a href={route('logistics.materials.export')}>
                                <Download className="size-4" />
                                Export Excel
                            </a>
                        </Button>
                        {canManage && (
                            <Button size="sm" onClick={openCreate}>
                                <Plus className="size-4" />
                                Tambah Material
                            </Button>
                        )}
                    </>
                }
            />

            <div className="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard label="Jumlah Material" value={summary.totalItems} icon={Boxes} />
                <StatCard
                    label="Stok di Bawah Minimum"
                    value={summary.lowStockCount}
                    icon={AlertTriangle}
                    tone={summary.lowStockCount > 0 ? 'error' : 'default'}
                    hint={summary.lowStockCount > 0 ? 'Perlu pengadaan ulang' : 'Semua stok aman'}
                />
                <StatCard label="Nilai Stok (harga modal)" value={formatRupiahCompact(summary.stockValue)} icon={Wallet} />
                <StatCard
                    label="Potensi Margin Stok"
                    value={formatRupiahCompact(summary.potentialMargin)}
                    icon={TrendingUp}
                    hint="Jika seluruh stok terjual"
                />
            </div>

            <div className="mb-4 flex flex-wrap items-center gap-3">
                <Input
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Cari nama material…"
                    className="w-full sm:w-64"
                    aria-label="Cari material"
                />
                <Select
                    value={filters.category || 'all'}
                    onValueChange={(value) => applyFilter({ category: value === 'all' ? '' : value })}
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
                <label className="flex items-center gap-2 text-sm text-daiku-dark">
                    <Switch
                        checked={filters.low_stock}
                        onCheckedChange={(checked) => applyFilter({ low_stock: checked })}
                    />
                    Hanya stok menipis
                </label>
                <Button variant="ghost" size="sm" asChild className="ml-auto">
                    <Link href={route('logistics.stock-movements.index')}>
                        <History className="size-4" />
                        Riwayat Stok
                    </Link>
                </Button>
            </div>

            <DataTable
                columns={columns}
                data={materials.data}
                emptyMessage={
                    filters.search || filters.category || filters.low_stock
                        ? 'Tidak ada material yang cocok dengan filter.'
                        : 'Belum ada material.'
                }
            />
            <Pagination paginator={materials} />

            {canManage && (
                <>
                    <MaterialFormDialog
                        open={formOpen}
                        onOpenChange={setFormOpen}
                        material={editing}
                        categories={categories}
                    />
                    <StockMovementDialog
                        open={movement !== null}
                        onOpenChange={(open) => !open && setMovement(null)}
                        material={movement?.material ?? null}
                        type={movement?.type ?? 'IN'}
                        projects={projects}
                    />
                </>
            )}
        </AppLayout>
    );
}
