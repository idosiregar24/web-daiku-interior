import { AllocationFormDialog } from '@/Components/modules/finance/AllocationFormDialog';
import { CATEGORY_OPTIONS } from '@/Components/modules/finance/TransactionFormDialog';
import { PageHeader } from '@/Components/shared/PageHeader';
import { EmptyState } from '@/Components/shared/EmptyState';
import { ProgressBar } from '@/Components/shared/ProgressBar';
import { StatCard } from '@/Components/shared/StatCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { Button } from '@/Components/ui/button';
import AppLayout from '@/Layouts/AppLayout';
import { formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { FinanceAllocationConfig } from '@/types';
import { Head } from '@inertiajs/react';
import { Calculator, Pencil, Percent, Plus } from 'lucide-react';
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
        <AppLayout>
            <Head title="Alokasi Persentase" />

            <PageHeader
                title="Alokasi Persentase"
                icon={Percent}
                description="Persentase nilai proyek yang dialokasikan otomatis per pos anggaran."
                actions={
                    <Button size="sm" onClick={openCreate}>
                        <Plus className="size-4" />
                        Tambah Alokasi
                    </Button>
                }
            />

            <div className="mb-6 grid gap-4 sm:grid-cols-2">
                <StatCard
                    label="Total alokasi aktif"
                    value={`${activeTotal.toLocaleString('id-ID', { maximumFractionDigits: 2 })}%`}
                    icon={Percent}
                    tone={activeTotal > 100 ? 'error' : 'default'}
                >
                    <ProgressBar
                        value={activeTotal}
                        label="Total alokasi aktif dari 100%"
                        tone={activeTotal > 100 ? 'error' : 'default'}
                    />
                </StatCard>
                <StatCard
                    label={`Contoh: proyek senilai ${formatRupiah(EXAMPLE_CONTRACT_VALUE)}`}
                    value={formatRupiah((EXAMPLE_CONTRACT_VALUE * activeTotal) / 100)}
                    icon={Calculator}
                    hint="dialokasikan ke pos-pos di bawah"
                />
            </div>

            {allocations.length === 0 ? (
                <EmptyState className="rounded-xl border border-dashed border-border" title="Belum ada konfigurasi alokasi." />
            ) : (
                <TableCard>
                    <table className="w-full text-sm">
                        <thead className={TABLE_HEAD_CLASS}>
                            <tr>
                                <th className="px-4 py-2.5 text-left font-semibold">Label</th>
                                <th className="px-4 py-2.5 text-left font-semibold">Kategori</th>
                                <th className="px-4 py-2.5 text-right font-semibold">Persentase</th>
                                <th className="px-4 py-2.5 text-right font-semibold">Contoh Nominal</th>
                                <th className="px-4 py-2.5 text-left font-semibold">Status</th>
                                <th className="w-12 px-4 py-2.5" />
                            </tr>
                        </thead>
                        <tbody>
                            {allocations.map((allocation) => (
                                <tr
                                    key={allocation.id}
                                    className={cn(
                                        'border-t border-border transition-colors hover:bg-daiku-gray/60',
                                        !allocation.is_active && 'text-daiku-muted',
                                    )}
                                >
                                    <td className="px-4 py-3 font-medium">{allocation.label}</td>
                                    <td className="px-4 py-3">{CATEGORY_LABEL[allocation.kategori] ?? allocation.kategori}</td>
                                    <td className="px-4 py-3 text-right">{Number(allocation.percentage).toLocaleString('id-ID')}%</td>
                                    <td className="px-4 py-3 text-right">
                                        {formatRupiah((EXAMPLE_CONTRACT_VALUE * Number(allocation.percentage)) / 100)}
                                    </td>
                                    <td className="px-4 py-3">
                                        <StatusChip
                                            status={allocation.is_active ? 'ACTIVE_CONFIG' : 'INACTIVE_CONFIG'}
                                            tone={allocation.is_active ? 'success' : 'neutral'}
                                            label={allocation.is_active ? 'Aktif' : 'Nonaktif'}
                                        />
                                    </td>
                                    <td className="px-4 py-3 text-right">
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
                </TableCard>
            )}

            <AllocationFormDialog open={dialogOpen} onOpenChange={setDialogOpen} allocation={editing} />
        </AppLayout>
    );
}
