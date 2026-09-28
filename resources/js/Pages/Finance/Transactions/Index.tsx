import { formatRupiah } from '@/lib/format';
import { PageHeader } from '@/Components/shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { DatePicker } from '@/Components/shared/DatePicker';
import { StatCard } from '@/Components/shared/StatCard';
import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { EmptyState } from '@/Components/shared/EmptyState';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { TransactionFormDialog } from '@/Components/modules/finance/TransactionFormDialog';
import AppLayout from '@/Layouts/AppLayout';
import type { BankAccount, FinanceTransaction, PageProps, PaginatedData, Project } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { format } from 'date-fns';
import { ArrowDownLeft, ArrowUpRight, Download, Plus, Scale, Wallet } from 'lucide-react';
import { useState } from 'react';

interface TransactionIndexProps {
    transactions: PaginatedData<FinanceTransaction>;
    filters: { type?: string; project_id?: string; from?: string; to?: string };
    totalIncome: number;
    totalExpense: number;
    balance: number;
    projects: Pick<Project, 'id' | 'name'>[];
    bankAccounts: Pick<BankAccount, 'id' | 'label'>[];
}

/**
 * "Transaksi list: filter by type/tanggal/proyek + total summary"
 * (.claude/plan/sprint-04.md Jonathan Week 8) — PRD §7.1 "Finance –
 * Transaction" row (CEO/PM read, Finance CRUD).
 */
export default function TransactionIndex({
    transactions,
    filters,
    totalIncome,
    totalExpense,
    balance,
    projects,
    bankAccounts,
}: TransactionIndexProps) {
    const { auth } = usePage<PageProps>().props;
    const canManage = auth.user.role === 'FINANCE' || auth.user.role === 'SUPERADMIN';
    const [createOpen, setCreateOpen] = useState(false);

    function applyFilter(next: Partial<typeof filters>) {
        router.get(route('finance.transactions.index'), { ...filters, ...next }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout breadcrumbs={[{ label: 'Finance', routeName: 'finance.dashboard' }, { label: 'Transaksi' }]}>
            <Head title="Transaksi Finance" />

            <PageHeader
                title="Transaksi"
                icon={Wallet}
                description="Seluruh pemasukan dan pengeluaran perusahaan."
                actions={
                    <div className="flex items-center gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <a href={route('finance.transactions.export')}>
                                <Download className="size-4" />
                                Export Excel
                            </a>
                        </Button>
                        {canManage && (
                            <Button size="sm" onClick={() => setCreateOpen(true)}>
                                <Plus className="size-4" />
                                Catat Transaksi
                            </Button>
                        )}
                    </div>
                }
            />

            <div className="mb-6 grid gap-4 sm:grid-cols-3">
                <StatCard label="Total Pemasukan" value={formatRupiah(totalIncome)} icon={ArrowDownLeft} tone="success" />
                <StatCard label="Total Pengeluaran" value={formatRupiah(totalExpense)} icon={ArrowUpRight} tone="error" />
                <StatCard label="Saldo" value={formatRupiah(balance)} icon={Scale} />
            </div>

            <TableCard
                pagination={transactions}
                toolbar={
                    <div className="flex flex-wrap items-center gap-2">
                        <Select
                            value={filters.type ?? 'all'}
                            onValueChange={(value) => applyFilter({ type: value === 'all' ? undefined : value })}
                        >
                            <SelectTrigger className="w-48">
                                <SelectValue placeholder="Semua jenis" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua jenis</SelectItem>
                                <SelectItem value="PEMASUKAN">Pemasukan</SelectItem>
                                <SelectItem value="PENGELUARAN">Pengeluaran</SelectItem>
                            </SelectContent>
                        </Select>

                        <Select
                            value={filters.project_id ?? 'all'}
                            onValueChange={(value) => applyFilter({ project_id: value === 'all' ? undefined : value })}
                        >
                            <SelectTrigger className="w-56">
                                <SelectValue placeholder="Semua proyek" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua proyek</SelectItem>
                                {projects.map((project) => (
                                    <SelectItem key={project.id} value={String(project.id)}>
                                        {project.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <DatePicker
                            value={filters.from ? new Date(filters.from) : undefined}
                            onChange={(date) => applyFilter({ from: date ? format(date, 'yyyy-MM-dd') : undefined })}
                            placeholder="Dari tanggal"
                            className="w-44"
                        />
                        <DatePicker
                            value={filters.to ? new Date(filters.to) : undefined}
                            onChange={(date) => applyFilter({ to: date ? format(date, 'yyyy-MM-dd') : undefined })}
                            placeholder="Sampai tanggal"
                            className="w-44"
                        />
                    </div>
                }
            >
                <table className="w-full text-sm">
                    <thead className={TABLE_HEAD_CLASS}>
                        <tr>
                            <th className="px-4 py-2.5 text-left font-semibold">Tanggal</th>
                            <th className="px-4 py-2.5 text-left font-semibold">Proyek</th>
                            <th className="px-4 py-2.5 text-left font-semibold">Kategori</th>
                            <th className="px-4 py-2.5 text-left font-semibold">Deskripsi</th>
                            <th className="px-4 py-2.5 text-left font-semibold">Rekening</th>
                            <th className="px-4 py-2.5 text-right font-semibold">Nominal</th>
                        </tr>
                    </thead>
                    <tbody>
                        {transactions.data.length === 0 ? (
                            <tr>
                                <td colSpan={6} className="p-0">
                                    <EmptyState title="Belum ada transaksi." />
                                </td>
                            </tr>
                        ) : (
                            transactions.data.map((transaction) => (
                                <tr key={transaction.id} className="border-t border-border transition-colors hover:bg-daiku-gray/60">
                                    <td className="px-4 py-3 text-daiku-muted">
                                        {new Date(transaction.date).toLocaleDateString('id-ID')}
                                    </td>
                                    <td className="px-4 py-3 text-daiku-muted">{transaction.project?.name ?? '—'}</td>
                                    <td className="px-4 py-3 text-daiku-muted">
                                        {transaction.kategori?.replace(/_/g, ' ') ?? '—'}
                                    </td>
                                    <td className="px-4 py-3 font-medium">{transaction.description}</td>
                                    <td className="px-4 py-3 text-daiku-muted">{transaction.bank_account?.label ?? '—'}</td>
                                    <td
                                        className={`px-4 py-3 text-right font-medium tabular-nums ${
                                            transaction.type === 'PEMASUKAN' ? 'text-success-ink' : 'text-error-ink'
                                        }`}
                                    >
                                        {transaction.type === 'PEMASUKAN' ? '+' : '-'}
                                        {formatRupiah(transaction.amount)}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </TableCard>

            {canManage && (
                <TransactionFormDialog
                    open={createOpen}
                    onOpenChange={setCreateOpen}
                    projects={projects}
                    bankAccounts={bankAccounts}
                />
            )}
        </AppLayout>
    );
}
