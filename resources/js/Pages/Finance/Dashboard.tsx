import { MoneyTrendChart } from '@/Components/modules/analytics/MoneyTrendChart';
import { EmptyState } from '@/Components/shared/EmptyState';
import { PageHeader } from '@/Components/shared/PageHeader';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatCard, type StatDelta } from '@/Components/shared/StatCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { Button } from '@/Components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import { formatRupiah, formatRupiahCompact } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { BankAccountSummary } from '@/types';
import { Head, router } from '@inertiajs/react';
import { format, startOfMonth, subMonths } from 'date-fns';
import { id } from 'date-fns/locale';
import { ArrowDownLeft, ArrowUpRight, BarChart3, Download, Landmark, Scale, Wallet } from 'lucide-react';
import { useMemo } from 'react';

interface CashFlowRow {
    month: string;
    label: string;
    income: number;
    expense: number;
}

interface FinanceDashboardProps {
    /** Company-level (Pindah Dana left out) — FinanceTransactionService::monthlyCashFlow(). */
    cashFlow: CashFlowRow[];
    accountSummary: BankAccountSummary;
}

/** Month-over-month change of the last two rows, or undefined when there's no base. */
function monthDelta(values: number[], goodWhen: StatDelta['goodWhen']): StatDelta | undefined {
    const current = values[values.length - 1];
    const previous = values[values.length - 2];

    if (current === undefined || !previous) {
        return undefined;
    }

    return { value: ((current - previous) / Math.abs(previous)) * 100, label: 'vs bulan lalu', goodWhen };
}

/** Last 12 months for the per-account month picker (plus the selected one, if it's older). */
function monthOptions(selected: string) {
    const options = Array.from({ length: 12 }, (_, i) => subMonths(startOfMonth(new Date()), i)).map((date) => ({
        value: format(date, 'yyyy-MM'),
        label: format(date, 'MMMM yyyy', { locale: id }),
    }));

    if (!options.some((option) => option.value === selected)) {
        options.push({ value: selected, label: format(new Date(`${selected}-01T00:00:00`), 'MMMM yyyy', { locale: id }) });
    }

    return options;
}

const NUM = 'px-4 py-3 text-right tabular-nums whitespace-nowrap';

/**
 * "Cash flow dashboard: chart pemasukan vs pengeluaran 6 bulan"
 * (.claude/plan/sprint-04.md Jonathan Week 8, Recharts bar chart) + PRD
 * §4.7 "Ringkasan pemasukan dan pengeluaran per rekening dan
 * keseluruhan" (Sprint 9) — a "Analytics – Per Divisi" partial view
 * (PRD §7.1), same framing as CRM/Dashboard.tsx. Account balances are
 * derived (saldo awal + masuk − keluar), never typed in.
 */
export default function FinanceDashboard({ cashFlow, accountSummary }: FinanceDashboardProps) {
    const totalIncome = cashFlow.reduce((sum, row) => sum + row.income, 0);
    const totalExpense = cashFlow.reduce((sum, row) => sum + row.expense, 0);
    const income = cashFlow.map((row) => row.income);
    const expense = cashFlow.map((row) => row.expense);
    const net = cashFlow.map((row) => row.income - row.expense);
    const months = useMemo(() => monthOptions(accountSummary.month), [accountSummary.month]);
    const { accounts, total } = accountSummary;

    function selectMonth(month: string) {
        router.get(
            route('finance.dashboard'),
            { month },
            { preserveState: true, preserveScroll: true, replace: true, only: ['accountSummary'] },
        );
    }

    return (
        <AppLayout>
            <Head title="Cash Flow Dashboard" />

            <PageHeader
                title="Cash Flow"
                icon={Wallet}
                description="Pemasukan vs pengeluaran 6 bulan terakhir dan saldo tiap rekening."
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <a href={route('finance.transactions.export')} title="Laporan 6 bulan terakhir: transaksi, per bulan, per proyek, per rekening.">
                            <Download className="size-4" />
                            Export Excel
                        </a>
                    </Button>
                }
            />

            <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <StatCard
                    label="Total Pemasukan (6 bulan)"
                    value={formatRupiahCompact(totalIncome)}
                    icon={ArrowDownLeft}
                    tone="success"
                    trend={income}
                    delta={monthDelta(income, 'up')}
                />
                <StatCard
                    label="Total Pengeluaran (6 bulan)"
                    value={formatRupiahCompact(totalExpense)}
                    icon={ArrowUpRight}
                    tone="error"
                    trend={expense}
                    delta={monthDelta(expense, 'down')}
                />
                <StatCard
                    label="Selisih (6 bulan)"
                    value={formatRupiahCompact(totalIncome - totalExpense)}
                    icon={Scale}
                    trend={net}
                    hint={formatRupiah(totalIncome - totalExpense)}
                />
                <StatCard
                    label="Saldo Semua Rekening"
                    value={formatRupiahCompact(total.current_balance)}
                    icon={Landmark}
                    hint={formatRupiah(total.current_balance)}
                />
            </div>

            <SectionCard
                className="mb-6"
                title="Pemasukan vs Pengeluaran"
                icon={BarChart3}
                description="Total transaksi per bulan, tanpa Pindah Dana antar rekening — arahkan kursor ke batang untuk nilai lengkap."
            >
                <MoneyTrendChart
                    data={cashFlow}
                    series={[
                        { key: 'income', name: 'Pemasukan', kind: 'bar' },
                        { key: 'expense', name: 'Pengeluaran', kind: 'bar' },
                    ]}
                    ariaLabel="Grafik pemasukan dibanding pengeluaran per bulan"
                />
            </SectionCard>

            <SectionCard
                title="Saldo & Arus Kas per Rekening"
                icon={Landmark}
                description="Saldo saat ini = saldo awal + total masuk − total keluar."
                flush
                action={
                    <Select value={accountSummary.month} onValueChange={selectMonth}>
                        <SelectTrigger className="w-44" aria-label="Bulan arus kas">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {months.map((option) => (
                                <SelectItem key={option.value} value={option.value}>
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                }
                footer="Pindah Dana ikut dihitung di tiap rekening, tetapi tidak di masuk/keluar baris Keseluruhan (uang tetap di perusahaan). Rekening nonaktif hanya tampil bila masih bersaldo atau bertransaksi di bulan terpilih."
            >
                {accounts.length === 0 ? (
                    <EmptyState title="Belum ada rekening aktif." />
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className={TABLE_HEAD_CLASS}>
                                <tr>
                                    <th className="px-4 py-2.5 text-left font-semibold">Rekening</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Saldo Awal</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Total Masuk</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Total Keluar</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Saldo Saat Ini</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Masuk {accountSummary.label}</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Keluar {accountSummary.label}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {accounts.map((account) => (
                                    <tr key={account.id} className="border-t border-border transition-colors hover:bg-daiku-gray/60">
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2">
                                                <span className="font-medium whitespace-nowrap">{account.label}</span>
                                                {!account.is_active && <StatusChip status="INACTIVE" label="Nonaktif" tone="neutral" />}
                                            </div>
                                            <p className="text-xs text-daiku-muted">
                                                {account.bank_name} · {account.account_no}
                                            </p>
                                        </td>
                                        <td className={cn(NUM, 'text-daiku-muted')}>{formatRupiah(account.opening_balance)}</td>
                                        <td className={cn(NUM, 'text-success-ink')}>{formatRupiah(account.total_income)}</td>
                                        <td className={cn(NUM, 'text-error-ink')}>{formatRupiah(account.total_expense)}</td>
                                        <td className={cn(NUM, 'font-semibold', account.current_balance < 0 && 'text-error-ink')}>
                                            {formatRupiah(account.current_balance)}
                                        </td>
                                        <td className={cn(NUM, 'text-success-ink')}>{formatRupiah(account.month_income)}</td>
                                        <td className={cn(NUM, 'text-error-ink')}>{formatRupiah(account.month_expense)}</td>
                                    </tr>
                                ))}
                            </tbody>
                            <tfoot>
                                <tr className="border-t-2 border-border bg-daiku-gray/60 font-semibold">
                                    <td className="px-4 py-3">Keseluruhan</td>
                                    <td className={NUM}>{formatRupiah(total.opening_balance)}</td>
                                    <td className={cn(NUM, 'text-success-ink')}>{formatRupiah(total.total_income)}</td>
                                    <td className={cn(NUM, 'text-error-ink')}>{formatRupiah(total.total_expense)}</td>
                                    <td className={cn(NUM, total.current_balance < 0 && 'text-error-ink')}>
                                        {formatRupiah(total.current_balance)}
                                    </td>
                                    <td className={cn(NUM, 'text-success-ink')}>{formatRupiah(total.month_income)}</td>
                                    <td className={cn(NUM, 'text-error-ink')}>{formatRupiah(total.month_expense)}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                )}
            </SectionCard>
        </AppLayout>
    );
}
