import { FundExpenseDialog } from '@/Components/modules/finance/FundExpenseDialog';
import { EmptyState } from '@/Components/shared/EmptyState';
import { Notice } from '@/Components/shared/Notice';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StatCard } from '@/Components/shared/StatCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { Button } from '@/Components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate, formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { BankAccount, FamilyFundSummary, FamilyGatheringFund, PaginatedData } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { AlertOctagon, ArrowUpRight, CheckCircle2, Hourglass, PiggyBank, Plus, Wallet } from 'lucide-react';
import { useState } from 'react';

type FundEntryType = FamilyGatheringFund['type'];

interface FamilyFundIndexProps {
    entries: PaginatedData<FamilyGatheringFund>;
    filters: { type?: FundEntryType };
    summary: FamilyFundSummary;
    canRecordExpense: boolean;
    bankAccounts: Pick<BankAccount, 'id' | 'label'>[];
}

/**
 * "FamilyGatheringFund page Finance: total dana + riwayat"
 * (.claude/plan/sprint-03.md Week 6). PRD §4.7: "Dana penalti tidak bisa
 * dicairkan tanpa record Penggunaan Dana" — the "Catat Penggunaan Dana"
 * action is that record. Sprint 9 decision #10: only penalties the tukang
 * already paid are spendable; usage leaves a bank account.
 */
export default function FamilyFundIndex({ entries, filters, summary, canRecordExpense, bankAccounts }: FamilyFundIndexProps) {
    const [dialogOpen, setDialogOpen] = useState(false);

    function applyFilter(type: FundEntryType | undefined) {
        router.get(route('family-fund.index'), { type }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout>
            <Head title="Dana Family Gathering" />

            <PageHeader
                title="Dana Family Gathering"
                icon={PiggyBank}
                description="Akumulasi penalti form harian (PRD §4.7) — Rp 50.000 per pelanggaran, bisa dipakai setelah tukang membayar."
                actions={
                    <>
                        <Button variant="outline" size="sm" asChild>
                            <Link href={route('penalties.index')}>
                                <AlertOctagon className="size-4" />
                                Penalti
                            </Link>
                        </Button>
                        {canRecordExpense && (
                            <Button onClick={() => setDialogOpen(true)}>
                                <Plus className="size-4" />
                                Catat Penggunaan Dana
                            </Button>
                        )}
                    </>
                }
            />

            <div className="mb-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <StatCard label="Penalti Tercatat" value={formatRupiah(summary.penaltyTotal)} icon={AlertOctagon} />
                <StatCard label="Sudah Dibayar" value={formatRupiah(summary.collected)} icon={CheckCircle2} tone="success" />
                <StatCard
                    label="Belum Dibayar"
                    value={formatRupiah(summary.outstanding)}
                    icon={Hourglass}
                    tone={summary.outstanding > 0 ? 'warning' : 'default'}
                    hint="Belum bisa dipakai"
                />
                <StatCard label="Total Penggunaan" value={formatRupiah(summary.totalExpense)} icon={ArrowUpRight} tone="error" />
                <StatCard
                    label="Saldo Tersedia"
                    value={formatRupiah(summary.spendable)}
                    icon={Wallet}
                    hint={summary.otherIncome > 0 ? `Termasuk pemasukan lain ${formatRupiah(summary.otherIncome)}` : 'Sudah dibayar − penggunaan'}
                />
            </div>

            {summary.outstanding > 0 && (
                <Notice tone="info" className="mb-6">
                    {formatRupiah(summary.outstanding)} penalti belum dibayar tukang — baru masuk saldo tersedia setelah
                    Finance mencatat pembayarannya di halaman Penalti.
                </Notice>
            )}

            <TableCard
                pagination={entries}
                toolbar={
                    <Select value={filters.type ?? 'all'} onValueChange={(value) => applyFilter(value === 'all' ? undefined : (value as FundEntryType))}>
                        <SelectTrigger className="sm:w-52" aria-label="Filter jenis">
                            <SelectValue placeholder="Semua riwayat" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Semua riwayat</SelectItem>
                            <SelectItem value="INCOME">Pemasukan (penalti)</SelectItem>
                            <SelectItem value="EXPENSE">Penggunaan dana</SelectItem>
                        </SelectContent>
                    </Select>
                }
            >
                <table className="w-full text-sm">
                    <thead className={TABLE_HEAD_CLASS}>
                        <tr>
                            <th className="px-4 py-2.5 text-left font-semibold">Tanggal</th>
                            <th className="px-4 py-2.5 text-left font-semibold">Jenis</th>
                            <th className="px-4 py-2.5 text-left font-semibold">Keterangan</th>
                            <th className="px-4 py-2.5 text-left font-semibold">Status / Rekening</th>
                            <th className="px-4 py-2.5 text-left font-semibold">Dicatat Oleh</th>
                            <th className="px-4 py-2.5 text-right font-semibold">Nominal</th>
                        </tr>
                    </thead>
                    <tbody>
                        {entries.data.length === 0 ? (
                            <tr>
                                <td colSpan={6} className="p-0">
                                    <EmptyState title="Belum ada riwayat." />
                                </td>
                            </tr>
                        ) : (
                            entries.data.map((entry) => {
                                const isIncome = entry.type === 'INCOME';

                                return (
                                    <tr key={entry.id} className="border-t border-border transition-colors hover:bg-daiku-gray/60">
                                        <td className="px-4 py-3 text-daiku-muted">
                                            {formatDate(entry.finance_transaction?.date ?? entry.created_at)}
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusChip
                                                status={entry.type}
                                                tone={isIncome ? 'success' : 'error'}
                                                label={isIncome ? 'Pemasukan' : 'Penggunaan'}
                                            />
                                        </td>
                                        <td className="px-4 py-3">{entry.description ?? '—'}</td>
                                        <td className="px-4 py-3">
                                            {isIncome ? (
                                                entry.source_penalty ? (
                                                    <StatusChip
                                                        status={entry.source_penalty.is_deducted ? 'LUNAS' : 'BELUM_DIBAYAR'}
                                                        label={entry.source_penalty.is_deducted ? 'Lunas' : 'Belum Dibayar'}
                                                    />
                                                ) : (
                                                    <span className="text-daiku-muted">—</span>
                                                )
                                            ) : (
                                                <span className="text-daiku-muted">{entry.finance_transaction?.bank_account?.label ?? '—'}</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-daiku-muted">{entry.recorder?.name ?? '—'}</td>
                                        <td
                                            className={cn(
                                                'px-4 py-3 text-right font-medium tabular-nums',
                                                isIncome ? 'text-success-ink' : 'text-error-ink',
                                            )}
                                        >
                                            {isIncome ? '+' : '-'}
                                            {formatRupiah(entry.amount)}
                                        </td>
                                    </tr>
                                );
                            })
                        )}
                    </tbody>
                </table>
            </TableCard>

            {canRecordExpense && (
                <FundExpenseDialog
                    open={dialogOpen}
                    onOpenChange={setDialogOpen}
                    spendable={summary.spendable}
                    bankAccounts={bankAccounts}
                />
            )}
        </AppLayout>
    );
}
