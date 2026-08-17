import { PageHeader } from '@/Components/shared/PageHeader';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import AppLayout from '@/Layouts/AppLayout';
import { Head } from '@inertiajs/react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Legend,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

interface CashFlowRow {
    month: string;
    label: string;
    income: number;
    expense: number;
}

interface FinanceDashboardProps {
    cashFlow: CashFlowRow[];
}

function formatRupiah(value: number) {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
        notation: 'compact',
    }).format(value);
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

    return (
        <AppLayout breadcrumbs={[{ label: 'Finance', routeName: 'finance.dashboard' }, { label: 'Cash Flow' }]}>
            <Head title="Cash Flow Dashboard" />

            <PageHeader title="Cash Flow" description="Pemasukan vs pengeluaran 6 bulan terakhir." />

            <div className="mb-4 grid gap-4 sm:grid-cols-3">
                <Card>
                    <CardContent className="pt-6">
                        <p className="text-xs text-daiku-muted">Total Pemasukan (6 bulan)</p>
                        <p className="text-lg font-semibold text-success">{formatRupiah(totalIncome)}</p>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="pt-6">
                        <p className="text-xs text-daiku-muted">Total Pengeluaran (6 bulan)</p>
                        <p className="text-lg font-semibold text-error">{formatRupiah(totalExpense)}</p>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="pt-6">
                        <p className="text-xs text-daiku-muted">Saldo Bersih</p>
                        <p className="text-lg font-semibold text-daiku-dark">{formatRupiah(totalIncome - totalExpense)}</p>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle className="text-base">Pemasukan vs Pengeluaran</CardTitle>
                </CardHeader>
                <CardContent>
                    <ResponsiveContainer width="100%" height={340}>
                        <BarChart data={cashFlow} margin={{ left: 8 }}>
                            <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-daiku-border" />
                            <XAxis dataKey="label" tick={{ fontSize: 12 }} />
                            <YAxis tickFormatter={(value) => formatRupiah(value)} tick={{ fontSize: 11 }} width={70} />
                            <Tooltip formatter={(value) => formatRupiah(Number(value))} />
                            <Legend />
                            <Bar
                                dataKey="income"
                                name="Pemasukan"
                                fill="var(--color-success)"
                                radius={[4, 4, 0, 0]}
                                maxBarSize={36}
                            />
                            <Bar
                                dataKey="expense"
                                name="Pengeluaran"
                                fill="var(--color-error)"
                                radius={[4, 4, 0, 0]}
                                maxBarSize={36}
                            />
                        </BarChart>
                    </ResponsiveContainer>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
