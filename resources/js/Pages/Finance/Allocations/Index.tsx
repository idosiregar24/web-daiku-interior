import { AllocationFormDialog } from '@/Components/modules/finance/AllocationFormDialog';
import { CATEGORY_OPTIONS } from '@/Components/modules/finance/TransactionFormDialog';
import { PageHeader } from '@/Components/shared/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import AppLayout from '@/Layouts/AppLayout';
import { formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { FinanceAllocationConfig } from '@/types';
import { Head } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import { useState } from 'react';

interface AllocationIndexProps {
    allocations: FinanceAllocationConfig[];
    activeTotal: number;
}

/** Worked example shown under the table, so the percentages read as money. */
const EXAMPLE_CONTRACT_VALUE = 100_000_000;

const CATEGORY_LABEL = Object.fromEntries(CATEGORY_OPTIONS.PENGELUARAN.map((option) => [option.value, option.label]));

/**
 * PRD §4.7 "Alokasi Persentase Otomatis" — CEO/Finance edit the
 * percentages here; each project's Finance tab shows the resulting budget
 * breakdown (FinanceAllocationService::breakdownFor). Rows are
 * deactivated, never deleted.
 */
export default function AllocationIndex({ allocations, activeTotal }: AllocationIndexProps) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editing, setEditing] = useState<FinanceAllocationConfig | null>(null);

    function openCreate() {
        setEditing(null);
        setDialogOpen(true);
    }

    function openEdit(allocation: FinanceAllocationConfig) {
        setEditing(allocation);
        setDialogOpen(true);
    }

    return (
        <AppLayout breadcrumbs={[{ label: 'Finance', routeName: 'finance.dashboard' }, { label: 'Alokasi Persentase' }]}>
            <Head title="Alokasi Persentase" />

            <PageHeader
                title="Alokasi Persentase"
                description="Persentase nilai proyek yang dialokasikan otomatis per pos anggaran."
                actions={
                    <Button size="sm" onClick={openCreate}>
                        <Plus className="size-4" />
                        Tambah Alokasi
                    </Button>
                }
            />

            <div className="mb-4 grid gap-4 sm:grid-cols-2">
                <Card>
                    <CardContent>
                        <p className="text-sm text-daiku-muted">Total alokasi aktif</p>
                        <p className={cn('text-2xl font-semibold', activeTotal > 100 ? 'text-error' : 'text-daiku-dark')}>
                            {activeTotal.toLocaleString('id-ID', { maximumFractionDigits: 2 })}%
                        </p>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent>
                        <p className="text-sm text-daiku-muted">
                            Contoh: proyek senilai {formatRupiah(EXAMPLE_CONTRACT_VALUE)}
                        </p>
                        <p className="text-2xl font-semibold text-daiku-dark">
                            {formatRupiah((EXAMPLE_CONTRACT_VALUE * activeTotal) / 100)}
                        </p>
                        <p className="text-xs text-daiku-muted">dialokasikan ke pos-pos di bawah</p>
                    </CardContent>
                </Card>
            </div>

            {allocations.length === 0 ? (
                <p className="rounded-lg border border-daiku-border py-10 text-center text-sm text-daiku-muted">
                    Belum ada konfigurasi alokasi.
                </p>
            ) : (
                <div className="overflow-x-auto rounded-lg border border-daiku-border">
                    <table className="w-full text-sm">
                        <thead className="bg-daiku-yellow-light">
                            <tr>
                                <th className="p-2 text-left font-medium">Label</th>
                                <th className="p-2 text-left font-medium">Kategori</th>
                                <th className="p-2 text-right font-medium">Persentase</th>
                                <th className="p-2 text-right font-medium">Contoh Nominal</th>
                                <th className="p-2 text-left font-medium">Status</th>
                                <th className="w-12 p-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {allocations.map((allocation) => (
                                <tr
                                    key={allocation.id}
                                    className={cn('border-t border-daiku-border', !allocation.is_active && 'text-daiku-muted')}
                                >
                                    <td className="p-2 font-medium">{allocation.label}</td>
                                    <td className="p-2">{CATEGORY_LABEL[allocation.kategori] ?? allocation.kategori}</td>
                                    <td className="p-2 text-right">{Number(allocation.percentage).toLocaleString('id-ID')}%</td>
                                    <td className="p-2 text-right">
                                        {formatRupiah((EXAMPLE_CONTRACT_VALUE * Number(allocation.percentage)) / 100)}
                                    </td>
                                    <td className="p-2">
                                        <Badge
                                            variant="secondary"
                                            className={
                                                allocation.is_active
                                                    ? 'bg-success/10 text-success'
                                                    : 'bg-daiku-gray text-daiku-muted'
                                            }
                                        >
                                            {allocation.is_active ? 'Aktif' : 'Nonaktif'}
                                        </Badge>
                                    </td>
                                    <td className="p-2 text-right">
                                        <Button
                                            variant="ghost"
                                            size="icon-sm"
                                            onClick={() => openEdit(allocation)}
                                            aria-label={`Edit alokasi ${allocation.label}`}
                                        >
                                            <Pencil className="size-4" />
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            <AllocationFormDialog open={dialogOpen} onOpenChange={setDialogOpen} allocation={editing} />
        </AppLayout>
    );
}
