import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { DataTable } from '@/Components/shared/DataTable';
import { ModuleTabs } from '@/Components/shared/ModuleTabs';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import AppLayout, { ROLE_LABEL } from '@/Layouts/AppLayout';
import type { PaginatedData, Role, User } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { Pencil, Plus, UserCog } from 'lucide-react';

interface UserWithRoles extends Omit<User, 'roles'> {
    roles: { id: number; name: Role }[];
    /** Stacked role (Kepala Desain) over its base role — see User::assignableRoleName(). */
    assignable_role: Role | null;
}

interface UsersIndexProps {
    users: PaginatedData<UserWithRoles>;
}

const columns: ColumnDef<UserWithRoles>[] = [
    {
        accessorKey: 'name',
        header: 'Nama',
        cell: ({ row }) => (
            <span className="flex items-center gap-2.5">
                <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-daiku-yellow-light text-[11px] font-semibold text-daiku-yellow-dark">
                    {row.original.name
                        .split(' ')
                        .map((part) => part[0])
                        .slice(0, 2)
                        .join('')
                        .toUpperCase()}
                </span>
                <span className="font-medium text-foreground">{row.original.name}</span>
            </span>
        ),
    },
    { accessorKey: 'email', header: 'Email' },
    {
        id: 'role',
        header: 'Role',
        cell: ({ row }) => (
            <Badge variant="outline" className="rounded-md font-mono text-[11px]">
                {row.original.assignable_role ? ROLE_LABEL[row.original.assignable_role] : '—'}
            </Badge>
        ),
    },
    {
        id: 'is_active',
        header: 'Status',
        cell: ({ row }) => (
            <StatusChip
                status={row.original.is_active ? 'ACTIVE_USER' : 'INACTIVE_USER'}
                tone={row.original.is_active ? 'success' : 'error'}
                label={row.original.is_active ? 'Aktif' : 'Nonaktif'}
            />
        ),
    },
    {
        id: 'actions',
        header: '',
        cell: ({ row }) => (
            <Button variant="ghost" size="icon-sm" asChild>
                <Link href={route('users.edit', row.original.id)}>
                    <Pencil className="size-4" />
                </Link>
            </Button>
        ),
    },
];

export default function UsersIndex({ users }: UsersIndexProps) {
    return (
        <AppLayout>
            <Head title="Pengguna" />

            <PageHeader
                title="Pengguna"
                icon={UserCog}
                description="Kelola akun pengguna dan role RBAC (khusus CEO)."
                actions={
                    <Button asChild>
                        <Link href={route('users.create')}>
                            <Plus className="size-4" />
                            Tambah User
                        </Link>
                    </Button>
                }
            />

            <ModuleTabs />

            <DataTable
                columns={columns}
                data={users.data}
                emptyMessage="Belum ada user selain akun awal."
                pagination={users}
            />
        </AppLayout>
    );
}
