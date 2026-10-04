import { DisciplinaryRecordDialog } from '@/Components/modules/hr/DisciplinaryRecordDialog';
import { DISCIPLINE_TYPE_LABEL, type DisciplineEmployeeOption, type DisciplineRecordRow } from '@/Components/modules/hr/DisciplineCommon';
import { DisciplineDescriptionCell, DisciplineTypeCell } from '@/Components/modules/hr/DisciplineRecordCells';
import { DisciplineVoidDialog } from '@/Components/modules/hr/DisciplineVoidDialog';
import { StructureFilter } from '@/Components/modules/hr/StructureFilter';
import { DataTable } from '@/Components/shared/DataTable';
import { DatePicker } from '@/Components/shared/DatePicker';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StatCard } from '@/Components/shared/StatCard';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Switch } from '@/Components/ui/switch';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate } from '@/lib/format';
import type { DisciplinaryType, Division, PaginatedData } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { format, parseISO } from 'date-fns';
import { Ban, CalendarClock, Download, FileWarning, Gavel, MessageSquareWarning, Plus, Users, X } from 'lucide-react';
import { useState } from 'react';

interface DisciplineFilters {
    from: string | null;
    to: string | null;
    division: number | null;
    position: number | null;
    type: DisciplinaryType | null;
    active_only: boolean;
}

interface DisciplineIndexProps {
    records: PaginatedData<DisciplineRecordRow>;
    filters: DisciplineFilters;
    stats: {
        active_sp_count: number;
        employees_with_active_sp: number;
        teguran_this_month: number;
        sp_this_month: number;
        expiring_soon: number;
    };
    structure: Division[];
    canManage: boolean;
    employees: DisciplineEmployeeOption[];
}

const ALL = 'all';
const FILTER_TYPES: DisciplinaryType[] = ['TEGURAN_LISAN', 'SP1', 'SP2', 'SP3', 'CATATAN', 'PEMBATALAN'];

/**
 * SDM (Sprint 10, §3.1) — Kedisiplinan recap: reprimands and warning
 * letters (SP1 → SP2 → SP3, decision #12). CEO reads; HR records and
 * cancels. Append-only — a wrong entry is cancelled, never edited.
 */
export default function DisciplineIndex({ records, filters, stats, structure, canManage, employees }: DisciplineIndexProps) {
    const [issueOpen, setIssueOpen] = useState(false);
    const [voiding, setVoiding] = useState<DisciplineRecordRow | null>(null);

    const query = (f: DisciplineFilters) => ({
        from: f.from ?? undefined,
        to: f.to ?? undefined,
        division: f.division ?? undefined,
        position: f.position ?? undefined,
        type: f.type ?? undefined,
        active_only: f.active_only ? 1 : undefined,
    });

    function applyFilter(next: Partial<DisciplineFilters>) {
        router.get(route('hr.discipline.index'), query({ ...filters, ...next }), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    const hasFilter = Boolean(filters.from || filters.to || filters.division || filters.position || filters.type || filters.active_only);

    const columns: ColumnDef<DisciplineRecordRow>[] = [
        {
            accessorKey: 'issued_on',
            header: 'Tanggal',
            cell: ({ row }) => <span className="whitespace-nowrap tabular-nums">{formatDate(row.original.issued_on)}</span>,
        },
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
                            href={route('hr.employees.show', { employee: employee.id, tab: 'discipline' })}
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
        {
            id: 'recorder',
            header: 'Dicatat Oleh',
            enableSorting: false,
            cell: ({ row }) => <span className="text-daiku-muted">{row.original.recorder?.name ?? '—'}</span>,
        },
        ...(canManage
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
                                      Batalkan
                                  </Button>
                              </div>
                          ) : null,
                  } satisfies ColumnDef<DisciplineRecordRow>,
              ]
            : []),
    ];

    const toolbar = (
        <div className="flex flex-col gap-2 lg:flex-row lg:flex-wrap lg:items-center">
            <div className="flex items-center gap-2">
                <DatePicker
                    value={filters.from ? parseISO(filters.from) : undefined}
                    onChange={(date) => applyFilter({ from: date ? format(date, 'yyyy-MM-dd') : null })}
                    placeholder="Dari tanggal"
                    className="sm:w-40"
                />
                <span className="text-daiku-muted">–</span>
                <DatePicker
                    value={filters.to ? parseISO(filters.to) : undefined}
                    onChange={(date) => applyFilter({ to: date ? format(date, 'yyyy-MM-dd') : null })}
                    placeholder="Sampai tanggal"
                    className="sm:w-40"
                />
            </div>
            <div className="flex flex-col gap-2 sm:flex-row">
                <StructureFilter
                    structure={structure}
                    division={filters.division}
                    position={filters.position}
                    onChange={(value) => applyFilter(value)}
                />
                <Select value={filters.type ?? ALL} onValueChange={(value) => applyFilter({ type: value === ALL ? null : (value as DisciplinaryType) })}>
                    <SelectTrigger className="sm:w-40" aria-label="Filter jenis">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>Semua jenis</SelectItem>
                        {FILTER_TYPES.map((type) => (
                            <SelectItem key={type} value={type}>
                                {DISCIPLINE_TYPE_LABEL[type]}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
            <div className="flex items-center gap-2 lg:ml-2">
                <Switch id="active-only" checked={filters.active_only} onCheckedChange={(checked) => applyFilter({ active_only: checked })} />
                <Label htmlFor="active-only" className="text-sm font-normal">
                    Hanya SP aktif
                </Label>
            </div>
            {hasFilter && (
                <Button
                    variant="ghost"
                    size="sm"
                    className="lg:ml-auto"
                    onClick={() => applyFilter({ from: null, to: null, division: null, position: null, type: null, active_only: false })}
                >
                    <X className="size-4" />
                    Reset
                </Button>
            )}
        </div>
    );

    return (
        <AppLayout>
            <Head title="Kedisiplinan" />

            <PageHeader
                title="Kedisiplinan"
                icon={Gavel}
                description="Teguran dan surat peringatan karyawan tetap. SP bertingkat: SP2 hanya selama SP1 berlaku, SP3 hanya selama SP2 berlaku."
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <a href={route('hr.discipline.export', query(filters))}>
                                <Download className="size-4" />
                                Export Excel
                            </a>
                        </Button>
                        {canManage && (
                            <Button onClick={() => setIssueOpen(true)} disabled={employees.length === 0}>
                                <Plus className="size-4" />
                                Catat
                            </Button>
                        )}
                    </>
                }
            />

            <div className="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label="SP Berlaku"
                    value={stats.active_sp_count}
                    icon={FileWarning}
                    tone={stats.active_sp_count > 0 ? 'warning' : 'default'}
                    hint={`${stats.employees_with_active_sp} karyawan`}
                />
                <StatCard label="SP Terbit Bulan Ini" value={stats.sp_this_month} icon={Users} />
                <StatCard label="Teguran Bulan Ini" value={stats.teguran_this_month} icon={MessageSquareWarning} />
                <StatCard
                    label="SP Berakhir ≤ 30 Hari"
                    value={stats.expiring_soon}
                    icon={CalendarClock}
                    hint="Setelah berakhir, tingkat SP kembali ke SP1"
                />
            </div>

            <DataTable
                columns={columns}
                data={records.data}
                toolbar={toolbar}
                pagination={records}
                emptyMessage={hasFilter ? 'Tidak ada catatan yang cocok dengan filter.' : 'Belum ada catatan kedisiplinan.'}
            />

            {canManage && (
                <>
                    <DisciplinaryRecordDialog open={issueOpen} onOpenChange={setIssueOpen} employees={employees} />
                    <DisciplineVoidDialog
                        record={voiding}
                        employeeName={voiding?.employee?.name}
                        onOpenChange={(open) => !open && setVoiding(null)}
                    />
                </>
            )}
        </AppLayout>
    );
}
