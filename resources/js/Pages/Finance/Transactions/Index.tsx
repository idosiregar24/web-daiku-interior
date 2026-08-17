import { PageHeader } from '@/Components/shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { DatePicker } from '@/Components/shared/DatePicker';
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
import { Download, Plus } from 'lucide-react';
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

function formatRupiah(value: string | number) {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(Number(value));
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

            <div className="mb-4 grid gap-4 sm:grid-cols-3">
                <Card>
                    <CardContent className="pt-6">
                        <p className="text-xs text-daiku-muted">Total Pemasukan</p>
                        <p className="text-lg font-semibold text-success">{formatRupiah(totalIncome)}</p>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="pt-6">
                        <p className="text-xs text-daiku-muted">Total Pengeluaran</p>
                        <p className="text-lg font-semibold text-error">{formatRupiah(totalExpense)}</p>
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="pt-6">
                        <p className="text-xs text-daiku-muted">Saldo</p>
                        <p className="text-lg font-semibold text-daiku-dark">{formatRupiah(balance)}</p>
                    </CardContent>
                </Card>
            </div>

            <div className="mb-4 flex flex-wrap items-center gap-2">
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

            <div className="overflow-hidden rounded-lg border border-daiku-border">
                <table className="w-full text-sm">
                    <thead className="bg-daiku-yellow-light">
                        <tr>
                            <th className="p-2 text-left font-medium">Tanggal</th>
                            <th className="p-2 text-left font-medium">Proyek</th>
                            <th className="p-2 text-left font-medium">Kategori</th>
                            <th className="p-2 text-left font-medium">Deskripsi</th>
                            <th className="p-2 text-left font-medium">Rekening</th>
                            <th className="p-2 text-right font-medium">Nominal</th>
                        </tr>
                    </thead>
                    <tbody>
                        {transactions.data.length === 0 ? (
                            <tr>
                                <td colSpan={6} className="p-6 text-center text-daiku-muted">
                                    Belum ada transaksi.
                                </td>
                            </tr>
                        ) : (
                            transactions.data.map((transaction) => (
                                <tr key={transaction.id} className="border-t border-daiku-border">
                                    <td className="p-2 text-daiku-muted">
                                        {new Date(transaction.date).toLocaleDateString('id-ID')}
                                    </td>
                                    <td className="p-2 text-daiku-muted">{transaction.project?.name ?? '—'}</td>
                                    <td className="p-2 text-daiku-muted">
                                        {transaction.kategori?.replace(/_/g, ' ') ?? '—'}
                                    </td>
                                    <td className="p-2 font-medium">{transaction.description}</td>
                                    <td className="p-2 text-daiku-muted">{transaction.bankAccount?.label ?? '—'}</td>
                                    <td
                                        className={`p-2 text-right font-medium ${
                                            transaction.type === 'PEMASUKAN' ? 'text-success' : 'text-error'
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
            </div>

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
