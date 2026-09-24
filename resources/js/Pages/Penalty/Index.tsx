import { PageHeader } from '@/Components/shared/PageHeader';
import { Pagination } from '@/Components/shared/Pagination';
import { StatCard } from '@/Components/shared/StatCard';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate, formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { PaginatedData, Penalty, User } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { AlertOctagon, PiggyBank, Users } from 'lucide-react';

interface StaffPenaltySummary {
    staffId: number;
    name: string;
    count: number;
    total: number;
    lastDate: string | null;
}

interface PenaltyIndexProps {
    penalties: PaginatedData<Penalty>;
    filters: { staff_id?: string };
    fieldStaff: Pick<User, 'id' | 'name'>[];
    perStaff: StaffPenaltySummary[];
    grandTotal: number;
    canViewFamilyFund: boolean;
}

/**
 * PRD §7.1 "Penalty – View" — read-only everywhere (penalties are only
 * ever written by PenaltyService's automated job, PRD §6.5). Field Staff
 * see only their own (server-scoped in PenaltyController::index()).
 * CSV Sprint 6: per-tukang summary + total + link to the family fund
 * (the link only for roles that can open it).
 */
export default function PenaltyIndex({ penalties, filters, fieldStaff, perStaff, grandTotal, canViewFamilyFund }: PenaltyIndexProps) {
    const isOwnView = fieldStaff.length === 0;
    const penaltyCount = perStaff.reduce((sum, row) => sum + row.count, 0);

    function applyFilter(next: Partial<typeof filters>) {
        router.get(route('penalties.index'), { ...filters, ...next }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout breadcrumbs={[{ label: 'Penalti' }]}>
            <Head title="Penalti" />

            <PageHeader
                title="Penalti"
                description="Riwayat penalti form harian yang belum diisi — dijatuhkan otomatis oleh sistem setiap jam 21:00 WIB."
                actions={
                    canViewFamilyFund && (
                        <Button variant="outline" size="sm" asChild>
                            <Link href={route('family-fund.index')}>
                                <PiggyBank className="size-4" />
                                Dana Family Gathering
                            </Link>
                        </Button>
                    )
                }
            />

            <div className="mb-4 grid gap-4 sm:grid-cols-3">
                <StatCard
                    label={isOwnView ? 'Total Penalti Saya' : 'Total Penalti'}
                    value={formatRupiah(grandTotal)}
                    icon={AlertOctagon}
                    hint="Seluruhnya masuk Dana Family Gathering"
                />
                <StatCard label="Jumlah Pelanggaran" value={penaltyCount} />
                {!isOwnView && <StatCard label="Tukang Terkena Penalti" value={perStaff.length} icon={Users} />}
            </div>

            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="order-2 lg:order-1">
                    {!isOwnView && (
                        <div className="mb-4">
                            <Select
                                value={filters.staff_id ?? 'all'}
                                onValueChange={(value) => applyFilter({ staff_id: value === 'all' ? undefined : value })}
                            >
                                <SelectTrigger className="sm:w-56" aria-label="Filter tukang">
                                    <SelectValue placeholder="Semua tukang" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Semua tukang</SelectItem>
                                    {fieldStaff.map((staff) => (
                                        <SelectItem key={staff.id} value={String(staff.id)}>
                                            {staff.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}

                    <div className="overflow-hidden rounded-lg border border-daiku-border">
                        <table className="w-full text-sm">
                            <thead className="bg-daiku-yellow-light">
                                <tr>
                                    <th className="p-2 text-left font-medium">Tukang</th>
                                    <th className="p-2 text-left font-medium">Jenis</th>
                                    <th className="p-2 text-left font-medium">Tanggal</th>
                                    <th className="p-2 text-right font-medium">Nominal</th>
                                </tr>
                            </thead>
                            <tbody>
                                {penalties.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={4} className="p-6 text-center text-daiku-muted">
                                            Belum ada penalti.
                                        </td>
                                    </tr>
                                ) : (
                                    penalties.data.map((penalty) => (
                                        <tr key={penalty.id} className="border-t border-daiku-border">
                                            <td className="p-2 font-medium">{penalty.staff?.name ?? '—'}</td>
                                            <td className="p-2 text-daiku-muted">{penalty.type.replace(/_/g, ' ')}</td>
                                            <td className="p-2 text-daiku-muted">{formatDate(penalty.date_occurred)}</td>
                                            <td className="p-2 text-right font-medium text-error tabular-nums">
                                                {formatRupiah(penalty.amount)}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                    <Pagination paginator={penalties} />
                </div>

                {!isOwnView && (
                    <Card className="order-1 h-fit lg:order-2">
                        <CardHeader>
                            <CardTitle className="text-base">Per Tukang</CardTitle>
                        </CardHeader>
                        <CardContent>
                            {perStaff.length === 0 ? (
                                <p className="text-sm text-daiku-muted">Belum ada penalti.</p>
                            ) : (
                                <ul className="divide-y divide-daiku-border">
                                    {perStaff.map((row) => {
                                        const active = filters.staff_id === String(row.staffId);

                                        return (
                                            <li key={row.staffId}>
                                                <button
                                                    type="button"
                                                    onClick={() => applyFilter({ staff_id: active ? undefined : String(row.staffId) })}
                                                    aria-pressed={active}
                                                    className={cn(
                                                        'flex w-full items-center justify-between gap-3 rounded-md px-2 py-2 text-left text-sm hover:bg-daiku-yellow-light',
                                                        active && 'bg-daiku-yellow-light',
                                                    )}
                                                >
                                                    <span>
                                                        <span className="block font-medium text-daiku-dark">{row.name}</span>
                                                        <span className="block text-xs text-daiku-muted">
                                                            {row.count}× · terakhir {formatDate(row.lastDate)}
                                                        </span>
                                                    </span>
                                                    <span className="font-semibold tabular-nums">{formatRupiah(row.total)}</span>
                                                </button>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
