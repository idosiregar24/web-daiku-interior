import { EmployeeFormDialog } from '@/Components/modules/finance/EmployeeFormDialog';
import { SalaryPaymentDialog } from '@/Components/modules/finance/SalaryPaymentDialog';
import { DataTable } from '@/Components/shared/DataTable';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StatCard } from '@/Components/shared/StatCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { UnderlineTabsList } from '@/Components/shared/UnderlineTabsList';
import { Button } from '@/Components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Tabs, TabsContent, TabsTrigger } from '@/Components/ui/tabs';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate, formatRupiah } from '@/lib/format';
import type { BankAccount, Employee, PayrollRow, User } from '@/types';
import { Head, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { format, subMonths } from 'date-fns';
import { id } from 'date-fns/locale';
import { CheckCircle2, Clock, Pencil, UserPlus, Users, Wallet, WalletCards } from 'lucide-react';
import { useMemo, useState } from 'react';

interface PayrollIndexProps {
    /** Selected month, `YYYY-MM`. */
    period: string;
    periodLabel: string;
    currentPeriod: string;
    rows: PayrollRow[];
    summary: { totalPaid: number; paidCount: number; unpaidCount: number; unpaidBaseTotal: number };
    employees: Employee[];
    canManage: boolean;
    bankAccounts: Pick<BankAccount, 'id' | 'label'>[];
    linkableUsers: Pick<User, 'id' | 'name'>[];
}

const TAB_LABEL: Record<string, string> = { payroll: 'Gaji Bulanan', employees: 'Karyawan' };

/** How many months back the month picker offers (arrears are paid from here). */
const MONTHS_BACK = 12;

/** "2026-09" → Date on the 1st (no day overflow). */
function periodToDate(period: string): Date {
    const [year, month] = period.split('-').map(Number);

    return new Date(year, month - 1, 1);
}

/**
 * PRD §4.7 "Gaji Karyawan Tetap" — monthly salaries of permanent staff.
 * Confidential: CEO reads, Finance pays and manages employees (server
 * enforced); one salary per employee per month.
 */
export default function PayrollIndex({
    period,
    periodLabel,
    currentPeriod,
    rows,
    summary,
    employees,
    canManage,
    bankAccounts,
    linkableUsers,
}: PayrollIndexProps) {
    const [tab, setTab] = useState('payroll');
    const [paying, setPaying] = useState<PayrollRow | null>(null);
    const [employeeDialogOpen, setEmployeeDialogOpen] = useState(false);
    const [editingEmployee, setEditingEmployee] = useState<Employee | null>(null);

    const monthOptions = useMemo(() => {
        const latest = periodToDate(currentPeriod);
        const options = Array.from({ length: MONTHS_BACK }, (_, i) => subMonths(latest, i));

        // Keep a deep-linked older month selectable too.
        if (!options.some((date) => format(date, 'yyyy-MM') === period)) {
            options.push(periodToDate(period));
        }

        return options.map((date) => ({ value: format(date, 'yyyy-MM'), label: format(date, 'MMMM yyyy', { locale: id }) }));
    }, [currentPeriod, period]);

    function selectPeriod(value: string) {
        router.get(route('finance.payroll.index'), { period: value }, { preserveState: true, preserveScroll: true, replace: true });
    }

    function openEmployeeDialog(employee: Employee | null) {
        setEditingEmployee(employee);
        setEmployeeDialogOpen(true);
    }

    const payrollColumns: ColumnDef<PayrollRow>[] = [
        {
            id: 'employee',
            accessorFn: (row) => row.employee.name,
            header: 'Karyawan',
            cell: ({ row }) => (
                <div>
                    <p className="font-medium text-daiku-dark">
                        {row.original.employee.name}
                        {!row.original.employee.is_active && (
                            <StatusChip status="INACTIVE_EMPLOYEE" tone="neutral" label="Nonaktif" className="ml-2" />
                        )}
                    </p>
                    <p className="text-xs text-daiku-muted">{row.original.employee.position}</p>
                </div>
            ),
        },
        {
            id: 'base_salary',
            accessorFn: (row) => Number(row.employee.base_salary),
            header: () => <div className="text-right">Gaji Pokok</div>,
            cell: ({ row }) => <div className="text-right tabular-nums">{formatRupiah(row.original.employee.base_salary)}</div>,
        },
        {
            id: 'status',
            header: 'Status',
            enableSorting: false,
            cell: ({ row }) =>
                row.original.payment ? (
                    <StatusChip status="PAID" label="Dibayar" />
                ) : (
                    <StatusChip status="PENDING" label="Belum dibayar" />
                ),
        },
        {
            id: 'adjustments',
            header: 'Tunjangan / Potongan',
            enableSorting: false,
            cell: ({ row }) => {
                const payment = row.original.payment;

                if (!payment) return <span className="text-daiku-muted">—</span>;

                return (
                    <div className="text-xs tabular-nums">
                        <p className="text-success-ink">+ {formatRupiah(payment.allowance)}</p>
                        <p className="text-error-ink">− {formatRupiah(payment.deduction)}</p>
                    </div>
                );
            },
        },
        {
            id: 'paid',
            accessorFn: (row) => Number(row.payment?.amount ?? 0),
            header: () => <div className="text-right">Dibayar</div>,
            cell: ({ row }) => {
                const payment = row.original.payment;

                if (!payment) return <div className="text-right text-daiku-muted">—</div>;

                return (
                    <div className="text-right">
                        <p className="font-semibold text-daiku-dark tabular-nums">{formatRupiah(payment.amount)}</p>
                        <p className="text-xs text-daiku-muted">
                            {formatDate(payment.paid_at)} · {payment.bank_account?.label ?? '—'}
                        </p>
                    </div>
                );
            },
        },
        ...(canManage
            ? [
                  {
                      id: 'actions',
                      header: '',
                      enableSorting: false,
                      cell: ({ row }) =>
                          !row.original.payment && row.original.employee.is_active ? (
                              <div className="flex justify-end">
                                  <Button variant="outline" size="sm" onClick={() => setPaying(row.original)}>
                                      <Wallet className="size-4" />
                                      Bayar
                                  </Button>
                              </div>
                          ) : null,
                  } satisfies ColumnDef<PayrollRow>,
              ]
            : []),
    ];

    const employeeColumns: ColumnDef<Employee>[] = [
        {
            accessorKey: 'name',
            header: 'Karyawan',
            cell: ({ row }) => (
                <div>
                    <p className="font-medium text-daiku-dark">{row.original.name}</p>
                    <p className="text-xs text-daiku-muted">{row.original.position}</p>
                </div>
            ),
        },
        {
            accessorKey: 'base_salary',
            header: () => <div className="text-right">Gaji Pokok</div>,
            cell: ({ row }) => <div className="text-right tabular-nums">{formatRupiah(row.original.base_salary)}</div>,
        },
        {
            id: 'bank',
            header: 'Rekening',
            enableSorting: false,
            cell: ({ row }) =>
                row.original.account_no ? (
                    <span className="text-daiku-muted">
                        {row.original.bank_name ?? '—'} {row.original.account_no}
                    </span>
                ) : (
                    <span className="text-daiku-muted">—</span>
                ),
        },
        {
            id: 'user',
            header: 'Akun Sistem',
            enableSorting: false,
            cell: ({ row }) => <span className="text-daiku-muted">{row.original.user?.name ?? 'Tidak ditautkan'}</span>,
        },
        {
            accessorKey: 'join_date',
            header: 'Bergabung',
            cell: ({ row }) => <span className="text-daiku-muted">{formatDate(row.original.join_date)}</span>,
        },
        {
            accessorKey: 'is_active',
            header: 'Status',
            cell: ({ row }) => (
                <StatusChip
                    status={row.original.is_active ? 'ACTIVE_EMPLOYEE' : 'INACTIVE_EMPLOYEE'}
                    tone={row.original.is_active ? 'success' : 'neutral'}
                    label={row.original.is_active ? 'Aktif' : 'Nonaktif'}
                />
            ),
        },
        ...(canManage
            ? [
                  {
                      id: 'actions',
                      header: '',
                      enableSorting: false,
                      cell: ({ row }) => (
                          <div className="flex justify-end">
                              <Button
                                  variant="ghost"
                                  size="icon-sm"
                                  aria-label={`Edit ${row.original.name}`}
                                  onClick={() => openEmployeeDialog(row.original)}
                              >
                                  <Pencil className="size-4" />
                              </Button>
                          </div>
                      ),
                  } satisfies ColumnDef<Employee>,
              ]
            : []),
    ];

    return (
        <AppLayout breadcrumbs={[{ label: TAB_LABEL[tab] }]}>
            <Head title="Penggajian" />

            <PageHeader
                title="Penggajian"
                icon={WalletCards}
                description="Gaji bulanan karyawan tetap — satu pembayaran per karyawan per bulan. Data rahasia: hanya CEO dan Finance."
                actions={
                    tab === 'payroll' ? (
                        <Select value={period} onValueChange={selectPeriod}>
                            <SelectTrigger className="w-44" aria-label="Pilih periode gaji">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {monthOptions.map((option) => (
                                    <SelectItem key={option.value} value={option.value}>
                                        {option.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    ) : (
                        canManage && (
                            <Button size="sm" onClick={() => openEmployeeDialog(null)}>
                                <UserPlus className="size-4" />
                                Tambah Karyawan
                            </Button>
                        )
                    )
                }
            />

            <Tabs value={tab} onValueChange={setTab}>
                <UnderlineTabsList>
                    <TabsTrigger value="payroll">
                        <WalletCards />
                        Gaji Bulanan
                    </TabsTrigger>
                    <TabsTrigger value="employees">
                        <Users />
                        Karyawan
                    </TabsTrigger>
                </UnderlineTabsList>

                <TabsContent value="payroll" className="mt-6">
                    <div className="mb-6 grid gap-4 sm:grid-cols-3">
                        <StatCard
                            label={`Total Dibayar · ${periodLabel}`}
                            value={formatRupiah(summary.totalPaid)}
                            icon={Wallet}
                        />
                        <StatCard
                            label="Sudah Dibayar"
                            value={`${summary.paidCount} karyawan`}
                            icon={CheckCircle2}
                            tone={summary.paidCount > 0 && summary.unpaidCount === 0 ? 'success' : 'default'}
                        />
                        <StatCard
                            label="Belum Dibayar"
                            value={`${summary.unpaidCount} karyawan`}
                            icon={Clock}
                            tone={summary.unpaidCount > 0 ? 'warning' : 'default'}
                            hint={summary.unpaidCount > 0 ? `Gaji pokok ${formatRupiah(summary.unpaidBaseTotal)}` : 'Semua gaji periode ini sudah dibayar'}
                        />
                    </div>

                    <DataTable
                        columns={payrollColumns}
                        data={rows}
                        emptyMessage={
                            employees.length === 0
                                ? 'Belum ada karyawan — tambahkan di tab Karyawan.'
                                : `Tidak ada karyawan yang digaji untuk ${periodLabel}.`
                        }
                    />
                </TabsContent>

                <TabsContent value="employees" className="mt-6">
                    <DataTable columns={employeeColumns} data={employees} emptyMessage="Belum ada karyawan tetap." />
                </TabsContent>
            </Tabs>

            {canManage && (
                <>
                    <SalaryPaymentDialog
                        row={paying}
                        period={period}
                        periodLabel={periodLabel}
                        bankAccounts={bankAccounts}
                        onOpenChange={(open) => !open && setPaying(null)}
                    />
                    <EmployeeFormDialog
                        open={employeeDialogOpen}
                        onOpenChange={setEmployeeDialogOpen}
                        employee={editingEmployee}
                        employees={employees}
                        linkableUsers={linkableUsers}
                    />
                </>
            )}
        </AppLayout>
    );
}
