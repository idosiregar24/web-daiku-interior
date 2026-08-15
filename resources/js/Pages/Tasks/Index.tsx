import { DataTable } from '@/Components/shared/DataTable';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { TaskStatusDialog } from '@/Components/modules/projects/TaskStatusDialog';
import AppLayout from '@/Layouts/AppLayout';
import type { Milestone, PageProps, PaginatedData, Task, TaskStatus, User } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { useState } from 'react';

interface TasksIndexProps {
    tasks: PaginatedData<Task>;
    filters: { status?: string; assignee_id?: string; milestone_id?: string };
    fieldStaff: Pick<User, 'id' | 'name'>[];
    milestones: Milestone[];
    canAssign: boolean;
}

const STATUS_OPTIONS: TaskStatus[] = ['PENDING', 'ONPROGRESS', 'PENGECEKAN', 'DONE', 'OVER'];

function formatDate(value: string | null) {
    return value ? new Date(value).toLocaleDateString('id-ID') : '—';
}

/**
 * "Task list PM: filter by milestone/assignee/status" (.claude/plan/sprint-02.md
 * Week 4) — for PM/CEO this is every task across every project; Field
 * Staff only ever see their own (scoped server-side, TaskController::index()).
 * Task *creation* stays on Projects/Show.tsx's Task tab (project context
 * required); this page is read + status-update only.
 */
export default function TasksIndex({ tasks, filters, fieldStaff, milestones, canAssign }: TasksIndexProps) {
    const { auth } = usePage<PageProps>().props;
    const role = auth.user?.role;
    const isFieldStaff = role === 'FIELD_STAFF';

    const [statusOpen, setStatusOpen] = useState(false);
    const [activeTask, setActiveTask] = useState<Task | null>(null);

    function applyFilter(next: Partial<typeof filters>) {
        router.get(
            route('tasks.index'),
            { ...filters, ...next },
            { preserveState: true, replace: true },
        );
    }

    function openStatus(task: Task) {
        setActiveTask(task);
        setStatusOpen(true);
    }

    const columns: ColumnDef<Task>[] = [
        {
            accessorKey: 'title',
            header: 'Judul',
            cell: ({ row }) => (
                <Link href={route('projects.show', { project: row.original.project_id })} className="font-medium hover:underline">
                    {row.original.title}
                </Link>
            ),
        },
        {
            id: 'project',
            header: 'Proyek',
            cell: ({ row }) => row.original.project?.name ?? '—',
        },
        {
            id: 'milestone',
            header: 'Milestone',
            cell: ({ row }) => row.original.milestone?.name ?? '—',
        },
        {
            id: 'assignee',
            header: 'Tukang',
            cell: ({ row }) => row.original.assignee?.name ?? '—',
        },
        {
            accessorKey: 'priority',
            header: 'Prioritas',
        },
        {
            accessorKey: 'status',
            header: 'Status',
            cell: ({ row }) => <StatusChip status={row.original.status} />,
        },
        {
            accessorKey: 'due_date',
            header: 'Jatuh Tempo',
            cell: ({ row }) => {
                const isOverdue = row.original.status === 'OVER';

                return (
                    <span className={isOverdue ? 'font-medium text-error' : ''}>
                        {formatDate(row.original.due_date)}
                    </span>
                );
            },
        },
        {
            id: 'actions',
            header: '',
            cell: ({ row }) => (
                <Button variant="outline" size="sm" onClick={() => openStatus(row.original)}>
                    Update Status
                </Button>
            ),
        },
    ];

    return (
        <AppLayout breadcrumbs={[{ label: 'Task' }]}>
            <Head title="Task" />

            <PageHeader
                title="Task"
                description={
                    isFieldStaff
                        ? 'Daftar task yang di-assign ke Anda.'
                        : 'Semua task di seluruh proyek — filter berdasarkan milestone, tukang, dan status.'
                }
            />

            <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
                <Select
                    value={filters.status ?? 'all'}
                    onValueChange={(value) => applyFilter({ status: value === 'all' ? undefined : value })}
                >
                    <SelectTrigger className="sm:w-44">
                        <SelectValue placeholder="Semua status" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">Semua status</SelectItem>
                        {STATUS_OPTIONS.map((status) => (
                            <SelectItem key={status} value={status}>
                                {status}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>

                {canAssign && (
                    <>
                        <Select
                            value={filters.assignee_id ?? 'all'}
                            onValueChange={(value) => applyFilter({ assignee_id: value === 'all' ? undefined : value })}
                        >
                            <SelectTrigger className="sm:w-52">
                                <SelectValue placeholder="Semua tukang" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua tukang</SelectItem>
                                {fieldStaff.map((staff) => (
                                    <SelectItem key={staff.id} value={String(staff.id)}>
                                        {staff.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>

                        <Select
                            value={filters.milestone_id ?? 'all'}
                            onValueChange={(value) => applyFilter({ milestone_id: value === 'all' ? undefined : value })}
                        >
                            <SelectTrigger className="sm:w-64">
                                <SelectValue placeholder="Semua milestone" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua milestone</SelectItem>
                                {milestones.map((milestone) => (
                                    <SelectItem key={milestone.id} value={String(milestone.id)}>
                                        {milestone.project?.name} — {milestone.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </>
                )}
            </div>

            <DataTable
                columns={columns}
                data={tasks.data}
                emptyMessage="Belum ada task."
            />

            <TaskStatusDialog open={statusOpen} onOpenChange={setStatusOpen} task={activeTask} />
        </AppLayout>
    );
}
