import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { DataTable } from '@/Components/shared/DataTable';
import { PageHeader } from '@/Components/shared/PageHeader';
import AppLayout from '@/Layouts/AppLayout';
import type { PaginatedData, Role, User } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { Pencil } from 'lucide-react';

interface UserWithRoles extends User {
    roles: { id: number; name: Role }[];
}

interface UsersIndexProps {
    users: PaginatedData<UserWithRoles>;
}

const columns: ColumnDef<UserWithRoles>[] = [
    { accessorKey: 'name', header: 'Nama' },
    { accessorKey: 'email', header: 'Email' },
    {
        id: 'role',
        header: 'Role',
        cell: ({ row }) => (
            <Badge variant="secondary">{row.original.roles[0]?.name ?? '—'}</Badge>
        ),
    },
    {
        id: 'is_active',
        header: 'Status',
        cell: ({ row }) => (
            <Badge
                variant="secondary"
                className={
                    row.original.is_active
                        ? 'bg-success/10 text-success'
                        : 'bg-error/10 text-error'
                }
            >
                {row.original.is_active ? 'Aktif' : 'Nonaktif'}
            </Badge>
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
        <AppLayout breadcrumbs={[{ label: 'User Management' }]}>
            <Head title="User Management" />

            <PageHeader
                title="User Management"
                description="Kelola akun pengguna dan role RBAC (khusus CEO)."
                actions={
                    <Button asChild>
                        <Link href={route('users.create')}>Tambah User</Link>
                    </Button>
                }
            />

            <DataTable
                columns={columns}
                data={users.data}
                emptyMessage="Belum ada user selain akun awal."
            />
        </AppLayout>
    );
}
