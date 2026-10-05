import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { formatDate, formatDateTime, formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { Check, X } from 'lucide-react';
import { salaryChangeChip, type SalaryChangeRow, salaryDelta } from './SalaryCommon';
import type { SalaryDecision } from './SalaryDecisionDialog';

interface SalaryChangeColumnsOptions {
    /** Adds the employee column (recap page; not on the profile tab). */
    showEmployee?: boolean;
    /** Hides requester/decider names (employee's own view). */
    selfView?: boolean;
    /** CEO approve/reject buttons on PENDING rows. */
    onDecide?: (change: SalaryChangeRow, decision: SalaryDecision) => void;
}

/** Columns of a salary-change table — the Gaji page and the profile tab. */
export function salaryChangeColumns({ showEmployee = false, selfView = false, onDecide }: SalaryChangeColumnsOptions): ColumnDef<SalaryChangeRow>[] {
    return [
        ...(showEmployee
            ? [
                  {
                      id: 'employee',
                      accessorFn: (row) => row.employee?.name ?? '',
                      header: 'Karyawan',
                      cell: ({ row }) => {
                          const employee = row.original.employee;

                          if (!employee) return '—';

                          return (
                              <div>
                                  <Link
                                      href={route('hr.employees.show', { employee: employee.id, tab: 'salary' })}
                                      className="font-medium text-daiku-dark decoration-daiku-yellow underline-offset-4 hover:underline"
                                  >
                                      {employee.name}
                                  </Link>
                                  <p className="text-xs text-daiku-muted">
                                      {employee.position?.name ?? '—'}
                                      {employee.position?.division && ` · ${employee.position.division.name}`}
                                  </p>
                              </div>
                          );
                      },
                  } satisfies ColumnDef<SalaryChangeRow>,
              ]
            : []),
        {
            id: 'salary',
            accessorFn: (row) => Number(row.new_salary),
            header: () => <div className="text-right">Gaji Pokok</div>,
            cell: ({ row }) => {
                const up = Number(row.original.new_salary) > Number(row.original.old_salary);

                return (
                    <div className="text-right tabular-nums">
                        <p className="text-xs text-daiku-muted line-through">{formatRupiah(row.original.old_salary)}</p>
                        <p className="font-semibold text-daiku-dark">
                            {formatRupiah(row.original.new_salary)}
                            <span className={cn('ml-1.5 text-xs font-normal', up ? 'text-success-ink' : 'text-error-ink')}>
                                {salaryDelta(row.original.old_salary, row.original.new_salary)}
                            </span>
                        </p>
                    </div>
                );
            },
        },
        {
            accessorKey: 'effective_date',
            header: 'Berlaku',
            cell: ({ row }) => <span className="whitespace-nowrap">{formatDate(row.original.effective_date)}</span>,
        },
        {
            id: 'reason',
            header: 'Alasan',
            enableSorting: false,
            cell: ({ row }) => (
                <div className="max-w-xs space-y-1 text-sm">
                    <p className="whitespace-pre-line">{row.original.reason}</p>
                    {row.original.reject_note && <p className="text-xs text-error-ink">Ditolak: {row.original.reject_note}</p>}
                </div>
            ),
        },
        {
            accessorKey: 'status',
            header: 'Status',
            cell: ({ row }) => {
                const chip = salaryChangeChip(row.original);

                return (
                    <div className="space-y-1">
                        <StatusChip status={chip.status} label={chip.label} tone={chip.tone} />
                        {!selfView && (
                            <p className="text-xs text-daiku-muted">
                                {row.original.decided_at
                                    ? `${row.original.decider?.name ?? '—'} · ${formatDateTime(row.original.decided_at)}`
                                    : `Diajukan ${row.original.requester?.name ?? '—'}`}
                            </p>
                        )}
                    </div>
                );
            },
        },
        ...(onDecide
            ? [
                  {
                      id: 'actions',
                      header: '',
                      enableSorting: false,
                      cell: ({ row }) =>
                          row.original.status === 'PENDING' ? (
                              <div className="flex justify-end gap-1.5">
                                  <Button size="sm" onClick={() => onDecide(row.original, 'approve')}>
                                      <Check className="size-4" />
                                      Setujui Perubahan Gaji
                                  </Button>
                                  <Button size="sm" variant="outline" onClick={() => onDecide(row.original, 'reject')}>
                                      <X className="size-4" />
                                      Tolak Perubahan Gaji
                                  </Button>
                              </div>
                          ) : null,
                  } satisfies ColumnDef<SalaryChangeRow>,
              ]
            : []),
    ];
}
