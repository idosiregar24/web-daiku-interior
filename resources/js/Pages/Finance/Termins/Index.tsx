import { formatRupiah } from '@/lib/format';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { EmptyState } from '@/Components/shared/EmptyState';
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
import { isPartiallyPaid, TerminPaymentDialog } from '@/Components/modules/finance/TerminPaymentDialog';
import AppLayout from '@/Layouts/AppLayout';
import type { BankAccount, PageProps, PaginatedData, Termin } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { CalendarClock, CalendarDays, FileDown, List } from 'lucide-react';
import { useState } from 'react';

type BankAccountOption = Pick<BankAccount, 'id' | 'label'>;

interface TerminIndexProps {
    termins: PaginatedData<Termin>;
    filters: { status?: string };
    calendarTermins: Termin[];
    calendarMonth: string;
    /** Only filled for FINANCE/SUPERADMIN (the "Catat Pembayaran" dialog). */
    bankAccounts: BankAccountOption[];
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
 * DP/Pelunasan/Sisa Piutang + "Catat Pembayaran" = PRD §4.7 "DP +
 * pelunasan, sisa piutang otomatis terhitung" (TerminService::recordPayment()).
 */
export default function TerminIndex({ termins, filters, calendarTermins, calendarMonth, bankAccounts }: TerminIndexProps) {
    const { auth } = usePage<PageProps>().props;
    const canMarkPaid = auth.user.role === 'FINANCE' || auth.user.role === 'SUPERADMIN';
    const [payingTermin, setPayingTermin] = useState<Termin | null>(null);
    const [tab, setTab] = useState('list');

    function applyFilter(next: Partial<typeof filters>) {
        router.get(route('finance.termins.index'), { ...filters, ...next }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout breadcrumbs={[{ label: tab === 'calendar' ? 'Kalender' : 'List' }]}>
            <Head title="Termin" />

            <PageHeader title="Termin" icon={CalendarClock} description="Jadwal pembayaran termin seluruh proyek (selalu Sabtu)." />

            <Tabs value={tab} onValueChange={setTab}>
                <TabsList>
                    <TabsTrigger value="list" className="px-3">
                        <List />
                        List
                    </TabsTrigger>
                    <TabsTrigger value="calendar" className="px-3">
                        <CalendarDays />
                        Kalender
                    </TabsTrigger>
                </TabsList>

                <TabsContent value="list" className="mt-4">
                    <TableCard
                        pagination={termins}
                        toolbar={
                            <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
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
                        }
                    >
                        <table className="w-full text-sm">
                            <thead className={TABLE_HEAD_CLASS}>
                                <tr>
                                    <th className="px-4 py-2.5 text-left font-semibold">Proyek</th>
                                    <th className="px-4 py-2.5 text-left font-semibold">Termin</th>
                                    <th className="px-4 py-2.5 text-left font-semibold">Rekening</th>
                                    <th className="px-4 py-2.5 text-left font-semibold">Jadwal</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Nominal</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">DP</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Pelunasan</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Sisa Piutang</th>
                                    <th className="px-4 py-2.5 text-left font-semibold">Status</th>
                                    <th className="w-48 px-4 py-2.5" />
                                </tr>
                            </thead>
                            <tbody>
                                {termins.data.length === 0 ? (
                                    <tr>
                                        <td colSpan={10} className="p-0">
                                            <EmptyState title="Belum ada termin." />
                                        </td>
                                    </tr>
                                ) : (
                                    termins.data.map((termin) => (
                                        <tr key={termin.id} className="border-t border-border transition-colors hover:bg-daiku-gray/60">
                                            <td className="px-4 py-3 font-medium whitespace-nowrap">{termin.project?.name ?? '—'}</td>
                                            <td className="px-4 py-3 text-daiku-muted">
                                                #{termin.termin_number} ({termin.percentage}%)
                                            </td>
                                            <td className="px-4 py-3 whitespace-nowrap text-daiku-muted">{termin.bank_account?.label ?? '—'}</td>
                                            <td className="px-4 py-3 text-daiku-muted">{formatDate(termin.scheduled_date)}</td>
                                            <td className="px-4 py-3 text-right font-medium text-daiku-dark">{formatRupiah(termin.amount)}</td>
                                            <td className="px-4 py-3 text-right text-daiku-muted">{formatRupiah(termin.dp_amount)}</td>
                                            <td className="px-4 py-3 text-right text-daiku-muted">{formatRupiah(termin.pelunasan)}</td>
                                            <td className="px-4 py-3 text-right font-medium text-daiku-dark">
                                                {formatRupiah(termin.sisa_piutang)}
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex flex-wrap items-center gap-1">
                                                    <StatusChip status={termin.status} />
                                                    {isPartiallyPaid(termin) && (
                                                        <StatusChip status="PARTIAL" label="Dibayar Sebagian" />
                                                    )}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-2">
                                                    <Button variant="outline" size="icon-sm" asChild>
                                                        <a href={route('finance.termins.pdf', { termin: termin.id })} target="_blank" rel="noopener noreferrer">
                                                            <FileDown className="size-4" />
                                                        </a>
                                                    </Button>
                                                    {canMarkPaid && termin.status !== 'PAID' && (
                                                        <Button variant="outline" size="sm" onClick={() => setPayingTermin(termin)}>
                                                            Catat Pembayaran
                                                        </Button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </TableCard>
                </TabsContent>

                <TabsContent value="calendar" className="mt-4">
                    <TerminCalendar
                        termins={calendarTermins}
                        month={calendarMonth}
                        canMarkPaid={canMarkPaid}
                        bankAccounts={bankAccounts}
                    />
                </TabsContent>
            </Tabs>

            {canMarkPaid && payingTermin && (
                <TerminPaymentDialog
                    key={payingTermin.id}
                    termin={payingTermin}
                    bankAccounts={bankAccounts}
                    onClose={() => setPayingTermin(null)}
                />
            )}
        </AppLayout>
    );
}
