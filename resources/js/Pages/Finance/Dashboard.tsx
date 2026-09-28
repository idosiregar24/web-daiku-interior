import { MoneyTrendChart } from '@/Components/modules/analytics/MoneyTrendChart';
import { PageHeader } from '@/Components/shared/PageHeader';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatCard, type StatDelta } from '@/Components/shared/StatCard';
import AppLayout from '@/Layouts/AppLayout';
import { formatRupiah, formatRupiahCompact } from '@/lib/format';
import { Head } from '@inertiajs/react';
import { ArrowDownLeft, ArrowUpRight, BarChart3, Scale, Wallet } from 'lucide-react';

interface CashFlowRow {
    month: string;
    label: string;
    income: number;
    expense: number;
}

interface FinanceDashboardProps {
    cashFlow: CashFlowRow[];
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

/**
 * "Cash flow dashboard: chart pemasukan vs pengeluaran 6 bulan"
 * (.claude/plan/sprint-04.md Jonathan Week 8, Recharts bar chart) — a
 * "Analytics – Per Divisi" partial view (PRD §7.1), same framing as
 * CRM/Dashboard.tsx.
 */
export default function FinanceDashboard({ cashFlow }: FinanceDashboardProps) {
    const totalIncome = cashFlow.reduce((sum, row) => sum + row.income, 0);
    const totalExpense = cashFlow.reduce((sum, row) => sum + row.expense, 0);
    const income = cashFlow.map((row) => row.income);
    const expense = cashFlow.map((row) => row.expense);
    const net = cashFlow.map((row) => row.income - row.expense);

    return (
        <AppLayout breadcrumbs={[{ label: 'Finance', routeName: 'finance.dashboard' }, { label: 'Cash Flow' }]}>
            <Head title="Cash Flow Dashboard" />

            <PageHeader title="Cash Flow" icon={Wallet} description="Pemasukan vs pengeluaran 6 bulan terakhir." />

            <div className="mb-6 grid gap-4 sm:grid-cols-3">
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
                    label="Saldo Bersih"
                    value={formatRupiahCompact(totalIncome - totalExpense)}
                    icon={Scale}
                    trend={net}
                    hint={formatRupiah(totalIncome - totalExpense)}
                />
            </div>

            <SectionCard
                title="Pemasukan vs Pengeluaran"
                icon={BarChart3}
                description="Total transaksi per bulan — arahkan kursor ke batang untuk nilai lengkap."
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
        </AppLayout>
    );
}
