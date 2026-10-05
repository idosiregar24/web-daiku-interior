import { FLUSH_TABLE_CLASS } from '@/Components/modules/dashboards/dashboardFormat';
import { DataTable } from '@/Components/shared/DataTable';
import { Notice } from '@/Components/shared/Notice';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatCard } from '@/Components/shared/StatCard';
import { Button } from '@/Components/ui/button';
import { formatDate, formatRupiah } from '@/lib/format';
import type { Employee, SalaryPayment } from '@/types';
import { type ColumnDef } from '@tanstack/react-table';
import { format } from 'date-fns';
import { id } from 'date-fns/locale';
import { BadgeDollarSign, Check, HandCoins, History, Plus, Wallet, WalletCards, X } from 'lucide-react';
import { useState } from 'react';
import { salaryChangeColumns } from './SalaryChangeColumns';
import { SalaryChangeDialog } from './SalaryChangeDialog';
import { salaryDelta, type SalaryChangeRow } from './SalaryCommon';
import { type SalaryDecision, SalaryDecisionDialog } from './SalaryDecisionDialog';

type PaymentRow = Pick<SalaryPayment, 'id' | 'period' | 'base_salary' | 'allowance' | 'deduction' | 'amount' | 'paid_at'>;

/**
 * Data shape returned by SalaryChangeService::forEmployee() (passed straight
 * through by HR\EmployeeController::show() and the "Milik Saya" page).
 */
export interface EmployeeSalaryData {
    base_salary: string;
    /** Waiting for the CEO (never sent to the employee's own view). */
    pending: SalaryChangeRow | null;
    /** Approved, waiting for its effective date. */
    scheduled: SalaryChangeRow | null;
    /** Newest first; approved ones only on the employee's own view. */
    changes: SalaryChangeRow[];
    /** Last 24 salaries paid, newest first. */
    payments: PaymentRow[];
    paid_this_year: number;
    year: number;
    /** Remaining staff loans of the linked account; null without an account. */
    loan_remaining: number | null;
}

export interface EmployeeSalaryTabProps {
    employee: Pick<Employee, 'id' | 'name' | 'is_active'>;
    data: EmployeeSalaryData;
    /** HR (or SUPERADMIN) may act; false on the CEO's view and on "Milik Saya". */
    canManage: boolean;
    /** The employee's own read-only view ("Milik Saya"). */
    selfView?: boolean;
    /** CEO (or SUPERADMIN) may approve/reject salary-change requests. */
    canDecide: boolean;
}

function periodLabel(period: string): string {
    const [year, month] = period.split('-').map(Number);

    return format(new Date(year, month - 1, 1), 'MMMM yyyy', { locale: id });
}

/** Gaji tab of the employee profile (and of "Milik Saya", read-only). */
export function EmployeeSalaryTab({ employee, data, canManage, selfView = false, canDecide }: EmployeeSalaryTabProps) {
    const [requestOpen, setRequestOpen] = useState(false);
    const [deciding, setDeciding] = useState<{ change: SalaryChangeRow; decision: SalaryDecision } | null>(null);
    const manage = canManage && !selfView;
    const decide = canDecide && !selfView;
    const pending = selfView ? null : data.pending;

    const changeColumns = salaryChangeColumns({
        selfView,
        onDecide: decide ? (change, decision) => setDeciding({ change, decision }) : undefined,
    });

    const paymentColumns: ColumnDef<PaymentRow>[] = [
        {
            accessorKey: 'period',
            header: 'Periode',
            cell: ({ row }) => <span className="font-medium text-daiku-dark capitalize">{periodLabel(row.original.period)}</span>,
        },
        {
            accessorKey: 'base_salary',
            header: () => <div className="text-right">Gaji Pokok</div>,
            cell: ({ row }) => <div className="text-right tabular-nums">{formatRupiah(row.original.base_salary)}</div>,
        },
        {
            id: 'adjustments',
            header: () => <div className="text-right">Tunjangan / Potongan</div>,
            enableSorting: false,
            cell: ({ row }) => (
                <div className="text-right text-xs tabular-nums">
                    <p className="text-success-ink">+ {formatRupiah(row.original.allowance)}</p>
                    <p className="text-error-ink">− {formatRupiah(row.original.deduction)}</p>
                </div>
            ),
        },
        {
            accessorKey: 'amount',
            header: () => <div className="text-right">Dibayar</div>,
            cell: ({ row }) => (
                <div className="text-right">
                    <p className="font-semibold text-daiku-dark tabular-nums">{formatRupiah(row.original.amount)}</p>
                    <p className="text-xs text-daiku-muted">{formatDate(row.original.paid_at)}</p>
                </div>
            ),
        },
    ];

    return (
        <div className="space-y-6">
            <div className="grid gap-4 sm:grid-cols-3">
                <StatCard label="Gaji Pokok Saat Ini" value={formatRupiah(data.base_salary)} icon={BadgeDollarSign} />
                <StatCard label={`Total Dibayar ${data.year ?? ''}`} value={formatRupiah(data.paid_this_year ?? 0)} icon={Wallet} />
                <StatCard
                    label="Sisa Pinjaman"
                    value={data.loan_remaining === null ? '—' : formatRupiah(data.loan_remaining)}
                    icon={HandCoins}
                    tone={data.loan_remaining ? 'warning' : 'default'}
                    hint={data.loan_remaining === null ? 'Tidak punya akun sistem' : undefined}
                />
            </div>

            {pending && (
                <Notice tone="warning">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <span>
                            Pengajuan perubahan gaji pokok menjadi <strong>{formatRupiah(pending.new_salary)}</strong> (
                            {salaryDelta(pending.old_salary, pending.new_salary)}), berlaku {formatDate(pending.effective_date)}, menunggu
                            keputusan CEO.
                        </span>
                        {decide && (
                            <span className="flex shrink-0 gap-1.5">
                                <Button size="sm" onClick={() => setDeciding({ change: pending, decision: 'approve' })}>
                                    <Check className="size-4" />
                                    Setujui Perubahan Gaji
                                </Button>
                                <Button size="sm" variant="outline" onClick={() => setDeciding({ change: pending, decision: 'reject' })}>
                                    <X className="size-4" />
                                    Tolak Perubahan Gaji
                                </Button>
                            </span>
                        )}
                    </div>
                </Notice>
            )}

            {data.scheduled && (
                <Notice tone="info">
                    Gaji pokok {selfView ? 'Anda ' : ''}berubah menjadi <strong>{formatRupiah(data.scheduled.new_salary)}</strong> mulai{' '}
                    {formatDate(data.scheduled.effective_date)} (sudah disetujui CEO).
                </Notice>
            )}

            <SectionCard
                title="Riwayat Perubahan Gaji Pokok"
                description={selfView ? 'Perubahan gaji pokok yang sudah disetujui.' : 'Diajukan SDM, diputuskan CEO.'}
                icon={History}
                flush
                action={
                    manage &&
                    employee.is_active &&
                    !data.pending &&
                    !data.scheduled && (
                        <Button size="sm" onClick={() => setRequestOpen(true)}>
                            <Plus className="size-4" />
                            Ajukan Perubahan Gaji
                        </Button>
                    )
                }
            >
                <DataTable
                    columns={changeColumns}
                    data={data.changes ?? []}
                    emptyMessage="Belum ada perubahan gaji pokok."
                    className={FLUSH_TABLE_CLASS}
                />
            </SectionCard>

            <SectionCard title="Riwayat Gaji Dibayar" description="24 bulan terakhir, dibayar oleh Finance." icon={WalletCards} flush>
                <DataTable columns={paymentColumns} data={data.payments ?? []} emptyMessage="Belum ada gaji yang dibayarkan." className={FLUSH_TABLE_CLASS} />
            </SectionCard>

            {manage && (
                <SalaryChangeDialog
                    open={requestOpen}
                    onOpenChange={setRequestOpen}
                    employeeId={employee.id}
                    employees={[{ id: employee.id, name: employee.name, base_salary: data.base_salary, has_open_request: false }]}
                />
            )}
            {decide && (
                <SalaryDecisionDialog
                    change={deciding?.change ?? null}
                    decision={deciding?.decision ?? 'approve'}
                    employeeName={employee.name}
                    onOpenChange={(open) => !open && setDeciding(null)}
                />
            )}
        </div>
    );
}
