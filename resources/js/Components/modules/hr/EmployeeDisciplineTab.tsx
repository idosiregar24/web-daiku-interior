import { FLUSH_TABLE_CLASS } from '@/Components/modules/dashboards/dashboardFormat';
import { DataTable } from '@/Components/shared/DataTable';
import { Notice } from '@/Components/shared/Notice';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatCard } from '@/Components/shared/StatCard';
import { Button } from '@/Components/ui/button';
import { formatDate } from '@/lib/format';
import type { DisciplinaryType, Employee } from '@/types';
import { type ColumnDef } from '@tanstack/react-table';
import { Ban, FileWarning, Gavel, MessageSquareWarning, NotebookPen, Plus } from 'lucide-react';
import { useState } from 'react';
import { DisciplinaryRecordDialog } from './DisciplinaryRecordDialog';
import type { DisciplineRecordRow } from './DisciplineCommon';
import { DisciplineDescriptionCell, DisciplineTypeCell } from './DisciplineRecordCells';
import { DisciplineVoidDialog } from './DisciplineVoidDialog';

/**
 * Data shape returned by DisciplineService::forEmployee() (passed straight
 * through by HR\EmployeeController::show() and the "Milik Saya" page).
 */
export interface EmployeeDisciplineData {
    /** Newest first, PEMBATALAN rows included. */
    records: DisciplineRecordRow[];
    /** Highest SP in force today. */
    active_sp: { id: number; type: DisciplinaryType; issued_on: string; valid_until: string } | null;
    /** The only SP level allowed next; null = SP3 in force (last level). */
    next_sp: DisciplinaryType | null;
    counts: { teguran: number; sp: number; catatan: number; voided: number };
}

export interface EmployeeDisciplineTabProps {
    employee: Pick<Employee, 'id' | 'name' | 'is_active'>;
    data: EmployeeDisciplineData;
    /** HR (or SUPERADMIN) may act; false on the CEO's view and on "Milik Saya". */
    canManage: boolean;
    /** The employee's own read-only view ("Milik Saya"). */
    selfView?: boolean;
}

/** Kedisiplinan tab of the employee profile (and of "Milik Saya", read-only). */
export function EmployeeDisciplineTab({ employee, data, canManage, selfView = false }: EmployeeDisciplineTabProps) {
    const [issueOpen, setIssueOpen] = useState(false);
    const [voiding, setVoiding] = useState<DisciplineRecordRow | null>(null);
    const manage = canManage && !selfView;
    const records = data.records ?? [];

    const columns: ColumnDef<DisciplineRecordRow>[] = [
        {
            accessorKey: 'issued_on',
            header: 'Tanggal',
            cell: ({ row }) => <span className="whitespace-nowrap tabular-nums">{formatDate(row.original.issued_on)}</span>,
        },
        {
            accessorKey: 'type',
            header: 'Jenis',
            cell: ({ row }) => <DisciplineTypeCell record={row.original} />,
        },
        {
            accessorKey: 'valid_until',
            header: 'Berlaku Sampai',
            cell: ({ row }) => <span className="whitespace-nowrap text-daiku-muted">{formatDate(row.original.valid_until)}</span>,
        },
        {
            id: 'description',
            header: 'Uraian',
            enableSorting: false,
            cell: ({ row }) => <DisciplineDescriptionCell record={row.original} />,
        },
        ...(!selfView
            ? [
                  {
                      id: 'recorder',
                      header: 'Dicatat Oleh',
                      enableSorting: false,
                      cell: ({ row }) => <span className="text-daiku-muted">{row.original.recorder?.name ?? '—'}</span>,
                  } satisfies ColumnDef<DisciplineRecordRow>,
              ]
            : []),
        ...(manage
            ? [
                  {
                      id: 'actions',
                      header: '',
                      enableSorting: false,
                      cell: ({ row }) =>
                          !row.original.is_voided && row.original.type !== 'PEMBATALAN' ? (
                              <div className="flex justify-end">
                                  <Button variant="ghost" size="sm" onClick={() => setVoiding(row.original)}>
                                      <Ban className="size-4" />
                                      Batalkan Catatan
                                  </Button>
                              </div>
                          ) : null,
                  } satisfies ColumnDef<DisciplineRecordRow>,
              ]
            : []),
    ];

    return (
        <div className="space-y-6">
            {data.active_sp ? (
                <Notice tone={data.active_sp.type === 'SP1' ? 'warning' : 'error'}>
                    {selfView ? 'Anda memiliki ' : `${employee.name} memiliki `}
                    <strong>{data.active_sp.type}</strong> yang berlaku sejak {formatDate(data.active_sp.issued_on)} sampai{' '}
                    {formatDate(data.active_sp.valid_until)}.
                    {!selfView &&
                        (data.next_sp
                            ? ` Tingkat berikutnya: ${data.next_sp}.`
                            : ' SP3 adalah tingkat terakhir — langkah selanjutnya dilakukan di luar sistem.')}
                </Notice>
            ) : (
                <Notice tone="success">{selfView ? 'Tidak ada SP yang berlaku saat ini.' : `${employee.name} tidak memiliki SP yang berlaku.`}</Notice>
            )}

            <div className="grid gap-4 sm:grid-cols-3">
                <StatCard label="Teguran Lisan" value={data.counts?.teguran ?? 0} icon={MessageSquareWarning} />
                <StatCard label="Surat Peringatan" value={data.counts?.sp ?? 0} icon={FileWarning} tone={data.active_sp ? 'warning' : 'default'} />
                <StatCard label="Catatan" value={data.counts?.catatan ?? 0} icon={NotebookPen} hint={data.counts?.voided ? `${data.counts.voided} pembatalan` : undefined} />
            </div>

            <SectionCard
                title="Riwayat Kedisiplinan"
                description="Catatan bersifat permanen; koreksi dicatat sebagai pembatalan."
                icon={Gavel}
                flush
                action={
                    manage &&
                    employee.is_active && (
                        <Button size="sm" onClick={() => setIssueOpen(true)}>
                            <Plus className="size-4" />
                            Catat Kedisiplinan
                        </Button>
                    )
                }
            >
                <DataTable columns={columns} data={records} emptyMessage="Belum ada catatan kedisiplinan." className={FLUSH_TABLE_CLASS} />
            </SectionCard>

            {manage && (
                <>
                    <DisciplinaryRecordDialog
                        open={issueOpen}
                        onOpenChange={setIssueOpen}
                        employeeId={employee.id}
                        employees={[
                            {
                                id: employee.id,
                                name: employee.name,
                                active_sp: data.active_sp ? { type: data.active_sp.type, valid_until: data.active_sp.valid_until } : null,
                                next_sp: data.next_sp,
                            },
                        ]}
                    />
                    <DisciplineVoidDialog record={voiding} employeeName={employee.name} onOpenChange={(open) => !open && setVoiding(null)} />
                </>
            )}
        </div>
    );
}
