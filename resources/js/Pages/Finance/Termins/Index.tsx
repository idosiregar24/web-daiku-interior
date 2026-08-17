import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { TerminCalendar } from '@/Components/modules/finance/TerminCalendar';
import AppLayout from '@/Layouts/AppLayout';
import type { PageProps, PaginatedData, Termin } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { FileDown } from 'lucide-react';

interface TerminIndexProps {
    termins: PaginatedData<Termin>;
    filters: { status?: string };
    calendarTermins: Termin[];
    calendarMonth: string;
}

function formatRupiah(value: string | number) {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(Number(value));
}

function formatDate(value: string) {
    return new Date(value).toLocaleDateString('id-ID');
}

/**
 * "Termin list page Finance: status chip + tombol mark as paid"
 * (.claude/plan/sprint-04.md Ido Week 8) — PRD §7.1 "Finance – Termin"
 * row (CEO read, Finance RU). PM sees/schedules termins through the
 * project-scoped Finance tab instead (Projects/Show.tsx) — see
 * ProjectController::show()'s docblock. Calendar tab is PRD §8.4's
 * "Finance: ... termin calendar view" — see TerminCalendar's docblock.
 */
export default function TerminIndex({ termins, filters, calendarTermins, calendarMonth }: TerminIndexProps) {
    const { auth } = usePage<PageProps>().props;
    const canMarkPaid = auth.user.role === 'FINANCE' || auth.user.role === 'SUPERADMIN';

    function applyFilter(next: Partial<typeof filters>) {
        router.get(route('finance.termins.index'), { ...filters, ...next }, { preserveState: true, replace: true });
    }

    function onMarkPaid(termin: Termin) {
        if (!confirm(`Tandai termin #${termin.termin_number} (${termin.project?.name}) sudah dibayar?`)) return;
        router.post(route('finance.termins.markPaid', { termin: termin.id }));
    }

    return (
        <AppLayout breadcrumbs={[{ label: 'Finance', routeName: 'finance.dashboard' }, { label: 'Termin' }]}>
            <Head title="Termin" />

            <PageHeader title="Termin" description="Jadwal pembayaran termin seluruh proyek (selalu Sabtu)." />

            <Tabs defaultValue="list">
                <TabsList>
                    <TabsTrigger value="list">List</TabsTrigger>
                    <TabsTrigger value="calendar">Kalender</TabsTrigger>
                </TabsList>

                <TabsContent value="list" className="mt-4">
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
                                <SelectItem value="SCHEDULED">Terjadwal</SelectItem>
                                <SelectItem value="INVOICED">Invoice Terbit</SelectItem>
                                <SelectItem value="PAID">Sudah Dibayar</SelectItem>
                                <SelectItem value="OVERDUE">Terlambat</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="overflow-hidden rounded-lg border border-daiku-border">
                        <table className="w-full text-sm">
                            <thead className="bg-daiku-yellow-light">
                                <tr>
                                    <th className="p-2 text-left font-medium">Proyek</th>
                                    <th className="p-2 text-left font-medium">Termin</th>
                                    <th className="p-2 text-left font-medium">Rekening</th>
                                    <th className="p-2 text-left font-medium">Jadwal</th>
                                    <th className="p-2 text-right font-medium">Nominal</th>
                                    <th className="p-2 text-left font-medium">Status</th>
                                    <th className="w-40 p-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {termins.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={7} className="p-6 text-center text-daiku-muted">
                                            Belum ada termin.
                                        </td>
                                    </tr>
                                ) : (
                                    termins.data.map((termin) => (
                                        <tr key={termin.id} className="border-t border-daiku-border">
                                            <td className="p-2 font-medium">{termin.project?.name ?? '—'}</td>
                                            <td className="p-2 text-daiku-muted">
                                                #{termin.termin_number} ({termin.percentage}%)
                                            </td>
                                            <td className="p-2 text-daiku-muted">{termin.bankAccount?.label ?? '—'}</td>
                                            <td className="p-2 text-daiku-muted">{formatDate(termin.scheduled_date)}</td>
                                            <td className="p-2 text-right font-medium text-daiku-dark">{formatRupiah(termin.amount)}</td>
                                            <td className="p-2">
                                                <StatusChip status={termin.status} />
                                            </td>
                                            <td className="p-2">
                                                <div className="flex items-center gap-2">
                                                    <Button variant="outline" size="icon-sm" asChild>
                                                        <a href={route('finance.termins.pdf', { termin: termin.id })} target="_blank" rel="noopener noreferrer">
                                                            <FileDown className="size-4" />
                                                        </a>
                                                    </Button>
                                                    {canMarkPaid && termin.status !== 'PAID' && (
                                                        <Button variant="outline" size="sm" onClick={() => onMarkPaid(termin)}>
                                                            Tandai Dibayar
                                                        </Button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </TabsContent>

                <TabsContent value="calendar" className="mt-4">
                    <TerminCalendar termins={calendarTermins} month={calendarMonth} canMarkPaid={canMarkPaid} />
                </TabsContent>
            </Tabs>
        </AppLayout>
    );
}
