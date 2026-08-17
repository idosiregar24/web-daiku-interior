import { PageHeader } from '@/Components/shared/PageHeader';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import type { PaginatedData, Penalty, User } from '@/types';
import { Head, router } from '@inertiajs/react';

interface PenaltyIndexProps {
    penalties: PaginatedData<Penalty>;
    filters: { staff_id?: string };
    fieldStaff: Pick<User, 'id' | 'name'>[];
}

function formatRupiah(value: string | number) {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(Number(value));
}

/**
 * PRD §7.1 "Penalty – View" — read-only everywhere (penalties are only
 * ever written by PenaltyService's automated job, PRD §6.5). Field Staff
 * see only their own (server-scoped in PenaltyController::index()).
 */
export default function PenaltyIndex({ penalties, filters, fieldStaff }: PenaltyIndexProps) {
    function applyFilter(next: Partial<typeof filters>) {
        router.get(route('penalties.index'), { ...filters, ...next }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout breadcrumbs={[{ label: 'Penalti' }]}>
            <Head title="Penalti" />

            <PageHeader
                title="Penalti"
                description="Riwayat penalti form harian yang belum diisi — dijatuhkan otomatis oleh sistem setiap jam 21:00 WIB."
            />

            {fieldStaff.length > 0 && (
                <div className="mb-4">
                    <Select
                        value={filters.staff_id ?? 'all'}
                        onValueChange={(value) => applyFilter({ staff_id: value === 'all' ? undefined : value })}
                    >
                        <SelectTrigger className="sm:w-56">
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
                                    <td className="p-2 text-daiku-muted">
                                        {new Date(penalty.date_occurred).toLocaleDateString('id-ID')}
                                    </td>
                                    <td className="p-2 text-right font-medium text-error">{formatRupiah(penalty.amount)}</td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
        </AppLayout>
    );
}
