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
import { FundTransferDialog } from '@/Components/modules/finance/FundTransferDialog';
import { CATEGORY_LABELS, TransactionFormDialog } from '@/Components/modules/finance/TransactionFormDialog';
import AppLayout from '@/Layouts/AppLayout';
import type { BankAccount, FinanceCategory, FinanceTransaction, PageProps, PaginatedData, Project } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { format } from 'date-fns';
import { ArrowDownLeft, ArrowLeftRight, ArrowUpRight, Download, Plus, Scale, Wallet, X } from 'lucide-react';
import { useMemo, useState } from 'react';

/** FinanceTransactionFilterRequest — query-string values as sent back by the server (empty ones dropped). */
interface TransactionFilters {
    type?: string;
    project_id?: string | number;
    bank_account_id?: string | number;
    kategori?: string;
    from?: string;
    to?: string;
}

type AccountOption = Pick<BankAccount, 'id' | 'label' | 'opening_balance' | 'is_active' | 'current_balance'>;

interface TransactionIndexProps {
    transactions: PaginatedData<FinanceTransaction>;
    filters: TransactionFilters;
    /** FinanceTransactionService::summarize() — Pindah Dana left out unless scoped to one account. */
    summary: { income: number; expense: number; net: number; excludesTransfers: boolean };
    projects: Pick<Project, 'id' | 'name'>[];
    /** Every account (inactive ones for the filter only), with its derived balance. */
    bankAccounts: AccountOption[];
    systemManagedCategories: FinanceCategory[];
}

const CATEGORY_FILTER_OPTIONS = (Object.entries(CATEGORY_LABELS) as [FinanceCategory, string][]).sort(([, a], [, b]) =>
    a.localeCompare(b, 'id'),
);

/**
 * "Transaksi list: filter by type/tanggal/proyek + total summary"
 * (.claude/plan/sprint-04.md Jonathan Week 8) + rekening/kategori filters,
 * export with the same filters and "Pindah Dana" (Sprint 9) — PRD §7.1
 * "Finance – Transaction" row (CEO/PM read, Finance CRUD).
 */
export default function TransactionIndex({
    transactions,
    filters,
    summary,
    projects,
    bankAccounts,
    systemManagedCategories,
}: TransactionIndexProps) {
    const { auth } = usePage<PageProps>().props;
    const canManage = auth.user.role === 'FINANCE' || auth.user.role === 'SUPERADMIN';
    const [createOpen, setCreateOpen] = useState(false);
    const [transferOpen, setTransferOpen] = useState(false);

    const activeAccounts = useMemo(() => bankAccounts.filter((account) => account.is_active), [bankAccounts]);
    const filteredAccount = filters.bank_account_id
        ? bankAccounts.find((account) => String(account.id) === String(filters.bank_account_id))
        : undefined;
    const hasFilters = Object.keys(filters).length > 0;

    function applyFilter(next: Partial<TransactionFilters>) {
        router.get(route('finance.transactions.index'), { ...filters, ...next }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout>
            <Head title="Transaksi Finance" />

            <PageHeader
                title="Transaksi"
                icon={Wallet}
                description="Seluruh pemasukan dan pengeluaran perusahaan."
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <a
                                href={route('finance.transactions.export', { ...filters })}
                                title="Mengikuti filter di halaman ini. Tanpa filter tanggal, export mencakup 6 bulan terakhir."
                            >
                                <Download className="size-4" />
                                Unduh Excel
                            </a>
                        </Button>
                        {canManage && (
                            <>
                                <Button variant="outline" size="sm" onClick={() => setTransferOpen(true)}>
                                    <ArrowLeftRight className="size-4" />
                                    Pindah Dana
                                </Button>
                                <Button size="sm" onClick={() => setCreateOpen(true)}>
                                    <Plus className="size-4" />
                                    Catat Transaksi
                                </Button>
                            </>
                        )}
                    </div>
                }
            />

            <div className="mb-6 grid gap-4 sm:grid-cols-3">
                <StatCard
                    label="Total Pemasukan"
                    value={formatRupiah(summary.income)}
                    icon={ArrowDownLeft}
                    tone="success"
                    hint={summary.excludesTransfers ? 'Tanpa Pindah Dana antar rekening' : undefined}
                />
                <StatCard
                    label="Total Pengeluaran"
                    value={formatRupiah(summary.expense)}
                    icon={ArrowUpRight}
                    tone="error"
                    hint={summary.excludesTransfers ? 'Tanpa Pindah Dana antar rekening' : undefined}
                />
                <StatCard
                    label="Selisih (Masuk − Keluar)"
                    value={formatRupiah(summary.net)}
                    icon={Scale}
                    hint={
                        filteredAccount?.current_balance !== undefined
                            ? `Saldo ${filteredAccount.label} saat ini ${formatRupiah(filteredAccount.current_balance)}`
                            : undefined
                    }
                />
            </div>

            <TableCard
                pagination={transactions}
                toolbar={
                    <div className="flex flex-wrap items-center gap-2">
                        <Select
                            value={filters.type ?? 'all'}
                            onValueChange={(value) => applyFilter({ type: value === 'all' ? undefined : value })}
                        >
                            <SelectTrigger className="w-40">
                                <SelectValue placeholder="Semua jenis" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua jenis</SelectItem>
                                <SelectItem value="PEMASUKAN">Pemasukan</SelectItem>
                                <SelectItem value="PENGELUARAN">Pengeluaran</SelectItem>
                            </SelectContent>
                        </Select>

                        <Select
                            value={filters.bank_account_id ? String(filters.bank_account_id) : 'all'}
                            onValueChange={(value) => applyFilter({ bank_account_id: value === 'all' ? undefined : value })}
                        >
                            <SelectTrigger className="w-48">
                                <SelectValue placeholder="Semua rekening" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua rekening</SelectItem>
                                {bankAccounts.map((account) => (
                                    <SelectItem key={account.id} value={String(account.id)}>
                                        {account.label}
                                        {!account.is_active && ' (nonaktif)'}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select
                            value={filters.kategori ?? 'all'}
                            onValueChange={(value) => applyFilter({ kategori: value === 'all' ? undefined : value })}
                        >
                            <SelectTrigger className="w-48">
                                <SelectValue placeholder="Semua kategori" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua kategori</SelectItem>
                                {CATEGORY_FILTER_OPTIONS.map(([value, label]) => (
                                    <SelectItem key={value} value={value}>
                                        {label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select
                            value={filters.project_id ? String(filters.project_id) : 'all'}
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
                            value={filters.from ? new Date(`${filters.from}T00:00:00`) : undefined}
                            onChange={(date) => applyFilter({ from: date ? format(date, 'yyyy-MM-dd') : undefined })}
                            placeholder="Dari tanggal"
                            className="w-44"
                        />
                        <DatePicker
                            value={filters.to ? new Date(`${filters.to}T00:00:00`) : undefined}
                            onChange={(date) => applyFilter({ to: date ? format(date, 'yyyy-MM-dd') : undefined })}
                            placeholder="Sampai tanggal"
                            className="w-44"
                        />

                        {hasFilters && (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => router.get(route('finance.transactions.index'), {}, { replace: true })}
                            >
                                <X className="size-4" />
                                Reset
                            </Button>
                        )}
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
                                    <td className="px-4 py-3 whitespace-nowrap text-daiku-muted">
                                        {new Date(transaction.date).toLocaleDateString('id-ID')}
                                    </td>
                                    <td className="px-4 py-3 text-daiku-muted">{transaction.project?.name ?? '—'}</td>
                                    <td className="px-4 py-3 whitespace-nowrap text-daiku-muted">
                                        {transaction.kategori ? CATEGORY_LABELS[transaction.kategori] : '—'}
                                    </td>
                                    <td className="px-4 py-3 font-medium">{transaction.description}</td>
                                    <td className="px-4 py-3 whitespace-nowrap text-daiku-muted">{transaction.bank_account?.label ?? '—'}</td>
                                    <td
                                        className={`px-4 py-3 text-right font-medium whitespace-nowrap tabular-nums ${
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
                <>
                    <TransactionFormDialog
                        open={createOpen}
                        onOpenChange={setCreateOpen}
                        projects={projects}
                        bankAccounts={activeAccounts}
                        systemManagedCategories={systemManagedCategories}
                    />
                    <FundTransferDialog open={transferOpen} onOpenChange={setTransferOpen} bankAccounts={activeAccounts} />
                </>
            )}
        </AppLayout>
    );
}
