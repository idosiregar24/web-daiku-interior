import { MaterialFormDialog } from '@/Components/modules/logistics/MaterialFormDialog';
import { StockMovementDialog } from '@/Components/modules/logistics/StockMovementDialog';
import { DataTable } from '@/Components/shared/DataTable';
import { ModuleTabs } from '@/Components/shared/ModuleTabs';
import { PageHeader } from '@/Components/shared/PageHeader';
import { SearchInput } from '@/Components/shared/SearchInput';
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
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Switch } from '@/Components/ui/switch';
import { useCreateParam } from '@/hooks/useCreateParam';
import AppLayout from '@/Layouts/AppLayout';
import { formatQty, formatRupiah, formatRupiahCompact } from '@/lib/format';
import { cn } from '@/lib/utils';
import { StatusChip } from '@/Components/shared/StatusChip';
import type { Material, MaterialCategory, PaginatedData, Project, StockMovementType, UnitOption } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import {
    AlertTriangle,
    CopyX,
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
    filters: { search: string; category_id: number | null; low_stock: boolean; show_merged: boolean };
    /** Data Master → Kategori Material (Sprint 11 Sub 5). */
    categories: Pick<MaterialCategory, 'id' | 'name' | 'code_prefix' | 'is_active'>[];
    summary: { totalItems: number; lowStockCount: number; stockValue: number; potentialMargin: number; possibleDuplicates: number };
    canManage: boolean;
    projects: Pick<Project, 'id' | 'name'>[];
    /** Active Master Satuan units — for the create/edit form. */
    units: UnitOption[];
}

/**
 * PRD §4.8 Material Master + Margin Tracker + stock alert (CSV Sprint 5:
 * "tabel + margin profit + alert stok minimum — badge merah jika <
 * min_stock"). Read for CEO/EST/PM, Logistics manages.
 */
export default function MaterialIndex({ materials, filters, categories, summary, canManage, projects, units }: MaterialIndexProps) {
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
                category_id: merged.category_id || undefined,
                low_stock: merged.low_stock ? 1 : undefined,
                show_merged: merged.show_merged ? 1 : undefined,
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

    /** Logistics' actions on one material — grid row menu and phone card alike. */
    function menuFor(material: Material) {
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
                        Catat Pemakaian Proyek
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
                        Ubah Material
                    </DropdownMenuItem>
                    <DropdownMenuItem variant="destructive" onClick={() => destroy(material)}>
                        <Trash2 className="size-4" />
                        Hapus
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        );
    }

    const columns: ColumnDef<Material>[] = [
        {
            accessorKey: 'name',
            header: 'Material',
            cell: ({ row }) => (
                <div>
                    <p className="font-medium text-daiku-dark">{row.original.name}</p>
                    <p className="text-xs text-daiku-muted">
                        {row.original.code} · {row.original.category?.name ?? 'Tanpa kategori'}
                        {!row.original.is_active && ' · digabung (nonaktif)'}
                    </p>
                    {row.original.possible_duplicate && (
                        <StatusChip status="WARNING" tone="warning" label="Kemungkinan dobel" className="mt-1" />
                    )}
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
                    <p className={cn('font-medium', row.original.margin < 0 ? 'text-error-ink' : 'text-success-ink')}>
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
                        <span className={cn('font-medium', material.is_low_stock && 'text-error-ink')}>
                            {formatQty(material.stock)} {material.unit?.code}
                        </span>
                        {material.is_low_stock && (
                            <Badge
                                variant="secondary"
                                className="border-transparent bg-error/10 text-error-ink"
                                title={`Di bawah stok minimum (${formatQty(material.min_stock)} ${material.unit?.code ?? ''})`}
                            >
                                <AlertTriangle className="size-3" />
                                Min {formatQty(material.min_stock)}
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
                      // A merged item is an inactive record — nothing to do with it any more.
                      cell: ({ row }) => (row.original.is_active ? menuFor(row.original) : null),
                  } satisfies ColumnDef<Material>,
              ]
            : []),
    ];

    // Sprint 13 #6 — arriving from the topbar "+ Buat" opens the add dialog.
    useCreateParam(canManage, openCreate);

    return (
        <AppLayout>
            <Head title="Material" />

            <PageHeader
                title="Material"
                icon={Boxes}
                description="Daftar material, harga modal & jual, margin, dan stok."
                actions={
                    <>
                        <Button variant="outline" size="sm" asChild>
                            <a href={route('logistics.materials.export')}>
                                <Download className="size-4" />
                                Unduh Excel
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

            <ModuleTabs />

            <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
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

            <DataTable
                columns={columns}
                data={materials.data}
                // Sprint 13 P2/P3 — the warehouse on a phone: stock first, no sideways scrolling.
                mobileCard={(material) => (
                    <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                            <p className="font-medium text-daiku-dark">{material.name}</p>
                            <p className="text-xs text-daiku-muted">
                                {material.code} · {material.category?.name ?? 'Tanpa kategori'}
                                {!material.is_active && ' · digabung (nonaktif)'}
                            </p>
                            <p className={cn('mt-1 text-base font-semibold tabular-nums', material.is_low_stock ? 'text-error-ink' : 'text-foreground')}>
                                Stok {formatQty(material.stock)} {material.unit?.code}
                                {material.is_low_stock && (
                                    <span className="ml-2 text-xs font-medium">
                                        (min {formatQty(material.min_stock)})
                                    </span>
                                )}
                            </p>
                            <p className="text-xs text-daiku-muted tabular-nums">
                                Modal {formatRupiah(material.cost_price)} · Jual {formatRupiah(material.sell_price)}
                            </p>
                        </div>
                        {canManage && material.is_active && menuFor(material)}
                    </div>
                )}
                emptyMessage={
                    filters.search || filters.category_id || filters.low_stock
                        ? 'Tidak ada material yang cocok dengan filter.'
                        : 'Belum ada material.'
                }
                pagination={materials}
                toolbar={
                    <div className="flex flex-wrap items-center gap-2">
                        <SearchInput
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Cari nama atau kode…"
                            className="w-full sm:w-64"
                            aria-label="Cari material"
                        />
                        <Select
                            value={filters.category_id ? String(filters.category_id) : 'all'}
                            onValueChange={(value) => applyFilter({ category_id: value === 'all' ? null : Number(value) })}
                        >
                            <SelectTrigger className="w-44" aria-label="Filter kategori">
                                <SelectValue placeholder="Semua kategori" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua kategori</SelectItem>
                                {categories.map((category) => (
                                    <SelectItem key={category.id} value={String(category.id)}>
                                        {category.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <label className="flex h-8 items-center gap-2 rounded-lg border border-border px-2.5 text-sm text-foreground">
                            <Switch
                                checked={filters.low_stock}
                                onCheckedChange={(checked) => applyFilter({ low_stock: checked })}
                            />
                            Hanya stok menipis
                        </label>
                        <label className="flex h-8 items-center gap-2 rounded-lg border border-border px-2.5 text-sm text-foreground">
                            <Switch
                                checked={filters.show_merged}
                                onCheckedChange={(checked) => applyFilter({ show_merged: checked })}
                            />
                            Tampilkan yang digabung
                        </label>
                        {canManage && (
                            <Button variant="outline" size="sm" asChild className="ml-auto">
                                <Link href={route('logistics.materials.duplicates')}>
                                    <CopyX className="size-4" />
                                    Cek Duplikat{summary.possibleDuplicates > 0 && ` (${summary.possibleDuplicates})`}
                                </Link>
                            </Button>
                        )}
                        <Button variant="ghost" size="sm" asChild className={canManage ? undefined : 'ml-auto'}>
                            <Link href={route('logistics.stock-movements.index')}>
                                <History className="size-4" />
                                Riwayat Stok
                            </Link>
                        </Button>
                    </div>
                }
            />

            {canManage && (
                <>
                    <MaterialFormDialog
                        open={formOpen}
                        onOpenChange={setFormOpen}
                        material={editing}
                        categories={categories}
                        units={units}
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
