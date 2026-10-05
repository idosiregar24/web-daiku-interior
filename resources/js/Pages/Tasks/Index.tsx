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
import { Tabs, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { ProjectLink } from '@/Components/modules/projects/ProjectLink';
import { TaskDeleteDialog } from '@/Components/modules/projects/TaskDeleteDialog';
import { TaskFormDialog } from '@/Components/modules/projects/TaskFormDialog';
import { TaskRowMenu } from '@/Components/modules/projects/TaskRowMenu';
import { TaskStatusDialog } from '@/Components/modules/projects/TaskStatusDialog';
import { TaskActionSheet } from '@/Components/modules/tasks/TaskActionSheet';
import { type FieldTask, TaskCard } from '@/Components/modules/tasks/TaskCard';
import { AT_LEAST_LG, useMediaQuery } from '@/hooks/useMediaQuery';
import { cn } from '@/lib/utils';
import AppLayout from '@/Layouts/AppLayout';
import { MaterialRequestDialog } from '@/Components/modules/logistics/MaterialRequestDialog';
import type { Milestone, PageProps, PaginatedData, Project, Task, TaskDueFilter, TaskStatus, User } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { ListChecks, PackagePlus } from 'lucide-react';
import { useState } from 'react';

interface TasksIndexProps {
    tasks: PaginatedData<Task>;
    filters: {
        status?: string;
        assignee_id?: string;
        milestone_id?: string;
        due?: TaskDueFilter;
    };
    fieldStaff: Pick<User, 'id' | 'name' | 'is_active'>[];
    milestones: (Pick<Milestone, 'id' | 'name' | 'project_id' | 'status'> & {
        project?: Milestone['project'];
    })[];
    canAssign: boolean;
    /** Sprint 11 Sub 4 — projects a Tukang may request goods for (their tasks' projects). */
    materialRequestProjects: Pick<Project, 'id' | 'name'>[];
    catalogHints: { id: number; code: string; name: string }[];
    /** Sprint 13 H3 — Tukang, working day, before the cutoff: a card's Simpan also sends today's form. */
    canSubmitDailyForm?: boolean;
}

/** Sprint 13 H4/H9 — the Tukang's status chips, in plain words. */
const FIELD_STATUS_LABEL: Record<TaskStatus, string> = {
    PENDING: 'Belum mulai',
    ONPROGRESS: 'Dikerjakan',
    PENGECEKAN: 'Minta dicek',
    DONE: 'Selesai',
    OVER: 'Terlambat',
};

const STATUS_OPTIONS: TaskStatus[] = ['PENDING', 'ONPROGRESS', 'PENGECEKAN', 'DONE', 'OVER'];

/** PRD §4.5 "Task List … (hari ini & minggu ini)" — `due` query param, see Task::scopeByDue(). */
const DUE_TABS: { value: TaskDueFilter | 'all'; label: string }[] = [
    { value: 'all', label: 'Semua' },
    { value: 'today', label: 'Hari ini' },
    { value: 'week', label: 'Minggu ini' },
    { value: 'overdue', label: 'Terlambat' },
];

function formatDate(value: string | null) {
    return value ? new Date(value).toLocaleDateString('id-ID') : '—';
}

/**
 * "Task list PM: filter by milestone/assignee/status" (.claude/plan/sprint-02.md
 * Week 4) — for PM/CEO this is every task across every project; Field
 * Staff only ever see their own (scoped server-side, TaskController::index()).
 * Task *creation* stays on Projects/Show.tsx's Task tab (project context
 * required); PM can edit/delete from here (Sprint 9), everyone else only
 * reads + updates status.
 */
export default function TasksIndex({
    tasks,
    filters,
    fieldStaff,
    milestones,
    canAssign,
    materialRequestProjects,
    catalogHints,
    canSubmitDailyForm = false,
}: TasksIndexProps) {
    const [sheetTask, setSheetTask] = useState<FieldTask | null>(null);
    // Sprint 13 H4/H8 — a Tukang below `lg` gets cards; the grid (and its
    // TanStack chunk) is only mounted where it's actually shown.
    const wide = useMediaQuery(AT_LEAST_LG);
    const [requestOpen, setRequestOpen] = useState(false);
    const { auth } = usePage<PageProps>().props;
    const role = auth.user?.role;
    const isFieldStaff = role === 'FIELD_STAFF';
    // tasks.updateStatus is PM|FIELD_STAFF — CEO reads only.
    const canUpdateStatus = canAssign || isFieldStaff;

    const [statusOpen, setStatusOpen] = useState(false);
    const [formOpen, setFormOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
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

    function openEdit(task: Task) {
        setActiveTask(task);
        setFormOpen(true);
    }

    function openDelete(task: Task) {
        setActiveTask(task);
        setDeleteOpen(true);
    }

    const columns: ColumnDef<Task>[] = [
        {
            accessorKey: 'title',
            header: 'Judul',
            cell: ({ row }) => (
                <Link
                    href={route('projects.show', {
                        project: row.original.project_id,
                        tab: 'task',
                    })}
                    className="font-medium hover:underline"
                >
                    {row.original.title}
                </Link>
            ),
        },
        {
            id: 'project',
            header: 'Proyek',
            cell: ({ row }) => <ProjectLink project={row.original.project} tab="task" />,
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
                    <span className={isOverdue ? 'font-medium text-error-ink' : ''}>
                        {formatDate(row.original.due_date)}
                    </span>
                );
            },
        },
        {
            id: 'actions',
            header: '',
            cell: ({ row }) => {
                const task = row.original;
                const projectClosed = task.project?.status === 'COMPLETED' || task.project?.status === 'CANCELLED';

                return (
                    <div className="flex items-center justify-end gap-1">
                        {canUpdateStatus && (
                            <Button variant="outline" size="sm" onClick={() => openStatus(task)}>
                                {isFieldStaff ? 'Ubah Status' : 'Ubah Status Task'}
                            </Button>
                        )}
                        {canAssign && !projectClosed && <TaskRowMenu task={task} onEdit={openEdit} onDelete={openDelete} />}
                    </div>
                );
            },
        },
    ];

    return (
        <AppLayout>
            <Head title={isFieldStaff ? 'Tugas' : 'Task'} />

            <PageHeader
                title={isFieldStaff ? 'Tugas Saya' : 'Task'}
                icon={ListChecks}
                description={
                    isFieldStaff
                        ? 'Daftar tugas yang diberikan kepada Anda.'
                        : 'Semua task di seluruh proyek — filter berdasarkan jatuh tempo, milestone, tukang, dan status.'
                }
                actions={
                    isFieldStaff &&
                    materialRequestProjects.length > 0 && (
                        <Button size="sm" variant="outline" onClick={() => setRequestOpen(true)}>
                            <PackagePlus className="size-4" />
                            Ajukan Barang
                        </Button>
                    )
                }
            />

            <MaterialRequestDialog
                open={requestOpen}
                onOpenChange={setRequestOpen}
                projects={materialRequestProjects}
                minimal
                units={[]}
                vendors={[]}
                catalogHints={catalogHints}
            />

            {/* Sprint 13 H4 — a Tukang on a phone gets cards + status chips, not the table. */}
            {isFieldStaff && !wide && (
                <div>
                    <div className="scrollbar-thin -mx-4 mb-4 flex gap-2 overflow-x-auto px-4 pb-1">
                        {[undefined, ...STATUS_OPTIONS].map((status) => {
                            const selected = (filters.status ?? undefined) === status;

                            return (
                                <button
                                    key={status ?? 'all'}
                                    type="button"
                                    onClick={() => applyFilter({ status })}
                                    className={cn(
                                        'h-9 shrink-0 rounded-full px-4 text-sm font-medium ring-1 transition-colors',
                                        selected ? 'bg-daiku-dark text-background ring-daiku-dark' : 'bg-background text-foreground ring-border',
                                    )}
                                >
                                    {status ? FIELD_STATUS_LABEL[status] : 'Semua'}
                                </button>
                            );
                        })}
                    </div>

                    {tasks.data.length === 0 ? (
                        <p className="rounded-xl border border-dashed border-border px-4 py-10 text-center text-sm text-muted-foreground">
                            Belum ada tugas.
                        </p>
                    ) : (
                        <div className="flex flex-col gap-3">
                            {tasks.data.map((task) => (
                                <TaskCard key={task.id} task={task as FieldTask} onOpen={setSheetTask} />
                            ))}
                        </div>
                    )}

                    {tasks.last_page > 1 && (
                        <div className="mt-4 flex items-center justify-between gap-2">
                            <Button variant="outline" disabled={!tasks.links[0]?.url} asChild={Boolean(tasks.links[0]?.url)}>
                                {tasks.links[0]?.url ? (
                                    <Link href={tasks.links[0].url} preserveState>
                                        Sebelumnya
                                    </Link>
                                ) : (
                                    <span>Sebelumnya</span>
                                )}
                            </Button>
                            <span className="text-sm text-muted-foreground">
                                {tasks.current_page} / {tasks.last_page}
                            </span>
                            <Button variant="outline" disabled={!tasks.links.at(-1)?.url} asChild={Boolean(tasks.links.at(-1)?.url)}>
                                {tasks.links.at(-1)?.url ? (
                                    <Link href={tasks.links.at(-1)!.url!} preserveState>
                                        Berikutnya
                                    </Link>
                                ) : (
                                    <span>Berikutnya</span>
                                )}
                            </Button>
                        </div>
                    )}

                    <TaskActionSheet task={sheetTask} canSubmitDailyForm={canSubmitDailyForm} onOpenChange={(open) => !open && setSheetTask(null)} />
                </div>
            )}

            {(!isFieldStaff || wide) && (
                <DataTable
                    columns={columns}
                    data={tasks.data}
                    emptyMessage={isFieldStaff ? 'Belum ada tugas.' : 'Belum ada task.'}
                    pagination={tasks}
                    toolbar={
                        <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                            <Tabs
                                value={filters.due ?? 'all'}
                                onValueChange={(value) =>
                                    applyFilter({
                                        due: value === 'all' ? undefined : (value as TaskDueFilter),
                                    })
                                }
                            >
                                <TabsList>
                                    {DUE_TABS.map((tab) => (
                                        <TabsTrigger key={tab.value} value={tab.value}>
                                            {tab.label}
                                        </TabsTrigger>
                                    ))}
                                </TabsList>
                            </Tabs>

                            <Select
                                value={filters.status ?? 'all'}
                                onValueChange={(value) =>
                                    applyFilter({
                                        status: value === 'all' ? undefined : value,
                                    })
                                }
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
                                        onValueChange={(value) =>
                                            applyFilter({
                                                assignee_id: value === 'all' ? undefined : value,
                                            })
                                        }
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
                                        onValueChange={(value) =>
                                            applyFilter({
                                                milestone_id: value === 'all' ? undefined : value,
                                            })
                                        }
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
                    }
                />
            )}

            <TaskStatusDialog open={statusOpen} onOpenChange={setStatusOpen} task={activeTask} />
            {canAssign && (
                <>
                    <TaskFormDialog open={formOpen} onOpenChange={setFormOpen} editing={activeTask} milestones={milestones} fieldStaff={fieldStaff} />
                    <TaskDeleteDialog open={deleteOpen} onOpenChange={setDeleteOpen} task={activeTask} />
                </>
            )}
        </AppLayout>
    );
}
