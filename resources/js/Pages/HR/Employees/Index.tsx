import { EmployeeFormDialog } from '@/Components/modules/hr/EmployeeFormDialog';
import { StructureFilter } from '@/Components/modules/hr/StructureFilter';
import { DataTable } from '@/Components/shared/DataTable';
import { PageHeader } from '@/Components/shared/PageHeader';
import { SearchInput } from '@/Components/shared/SearchInput';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import { formatDate } from '@/lib/format';
import type { Division, Employee, User } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { IdCard, Pencil, UserPlus } from 'lucide-react';
import { useState } from 'react';

type StatusFilter = 'all' | 'active' | 'inactive';

interface EmployeesIndexProps {
    employees: Employee[];
    filters: { division: number | null; position: number | null; status: StatusFilter; search: string };
    structure: Division[];
    canManage: boolean;
    linkableUsers: Pick<User, 'id' | 'name'>[];
}

/**
 * SDM (Sprint 10) — permanent employees, managed by HR, read by the CEO.
 * Field staff never appear here (decision #11, enforced server-side).
 */
export default function EmployeesIndex({ employees, filters, structure, canManage, linkableUsers }: EmployeesIndexProps) {
    const [search, setSearch] = useState(filters.search);
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editing, setEditing] = useState<Employee | null>(null);

    function applyFilter(next: Partial<EmployeesIndexProps['filters']>) {
        const merged = { ...filters, search, ...next };

        router.get(
            route('hr.employees.index'),
            {
                division: merged.division ?? undefined,
                position: merged.position ?? undefined,
                status: merged.status === 'all' ? undefined : merged.status,
                search: merged.search || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function openDialog(employee: Employee | null) {
        setEditing(employee);
        setDialogOpen(true);
    }

    const columns: ColumnDef<Employee>[] = [
        {
            accessorKey: 'name',
            header: 'Karyawan',
            cell: ({ row }) => (
                <Link
                    href={route('hr.employees.show', { employee: row.original.id })}
                    className="font-medium text-daiku-dark underline-offset-4 hover:underline decoration-daiku-yellow"
                >
                    {row.original.name}
                </Link>
            ),
        },
        {
            id: 'position',
            accessorFn: (row) => row.position?.name ?? '',
            header: 'Jabatan',
            cell: ({ row }) => (
                <div>
                    <p>{row.original.position?.name ?? '—'}</p>
                    <p className="text-xs text-daiku-muted">{row.original.position?.division?.name ?? ''}</p>
                </div>
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
                                  onClick={() => openDialog(row.original)}
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
        <AppLayout>
            <Head title="Karyawan" />

            <PageHeader
                title="Karyawan"
                icon={IdCard}
                description="Karyawan tetap Daiku — jabatan mengikuti master Divisi & Jabatan. Tukang tidak termasuk di modul SDM."
                actions={
                    canManage && (
                        <Button onClick={() => openDialog(null)}>
                            <UserPlus className="size-4" />
                            Tambah Karyawan
                        </Button>
                    )
                }
            />

            <DataTable
                columns={columns}
                data={employees}
                emptyMessage="Tidak ada karyawan yang cocok dengan filter."
                toolbar={
                    <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                        <SearchInput
                            placeholder="Cari nama karyawan..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') applyFilter({ search });
                            }}
                            onBlur={() => search !== filters.search && applyFilter({ search })}
                            className="sm:max-w-xs"
                        />
                        <StructureFilter
                            structure={structure}
                            division={filters.division}
                            position={filters.position}
                            onChange={(value) => applyFilter(value)}
                        />
                        <Select value={filters.status} onValueChange={(value) => applyFilter({ status: value as StatusFilter })}>
                            <SelectTrigger className="sm:w-40" aria-label="Filter status">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua status</SelectItem>
                                <SelectItem value="active">Aktif</SelectItem>
                                <SelectItem value="inactive">Nonaktif</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                }
            />

            {canManage && (
                <EmployeeFormDialog
                    open={dialogOpen}
                    onOpenChange={setDialogOpen}
                    employee={editing}
                    employees={employees}
                    linkableUsers={linkableUsers}
                    structure={structure}
                />
            )}
        </AppLayout>
    );
}
