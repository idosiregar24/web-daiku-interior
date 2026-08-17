import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { MilestoneCalendar } from '@/Components/modules/projects/MilestoneCalendar';
import { MilestoneFormDialog } from '@/Components/modules/projects/MilestoneFormDialog';
import { MilestoneGanttCalendar } from '@/Components/modules/projects/MilestoneGanttCalendar';
import { ProgressLogFormDialog } from '@/Components/modules/projects/ProgressLogFormDialog';
import { ProgressTimeline } from '@/Components/modules/projects/ProgressTimeline';
import { TaskFormDialog } from '@/Components/modules/projects/TaskFormDialog';
import { TaskStatusDialog } from '@/Components/modules/projects/TaskStatusDialog';
import { TerminFormDialog } from '@/Components/modules/projects/TerminFormDialog';
import AppLayout from '@/Layouts/AppLayout';
import type { BankAccount, Milestone, ProgressLog, Project, Task, Termin, User } from '@/types';
import { Head, router } from '@inertiajs/react';
import { FileDown, Plus } from 'lucide-react';
import { useMemo, useState } from 'react';

interface ProjectShowProps {
    project: Project;
    milestones: Milestone[];
    canViewMilestones: boolean;
    canManageMilestones: boolean;
    canManageTasks: boolean;
    tasks: Task[];
    fieldStaff: Pick<User, 'id' | 'name'>[];
    progressLogs: ProgressLog[];
    canViewProgressLogs: boolean;
    canManageProgressLogs: boolean;
    termins: Termin[];
    canViewTermins: boolean;
    canCreateTermins: boolean;
    canMarkTerminPaid: boolean;
    bankAccounts: Pick<BankAccount, 'id' | 'label'>[];
}

function formatRupiah(value: string | number) {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(Number(value));
}

function formatDate(value: string | null) {
    return value ? new Date(value).toLocaleDateString('id-ID') : '—';
}

function OverviewTab({ project, progressLogs }: { project: Project; progressLogs: ProgressLog[] }) {
    const fields: { label: string; value: string }[] = [
        { label: 'Klien', value: project.lead?.client_name ?? '—' },
        { label: 'Project Manager', value: project.pm?.name ?? '—' },
        { label: 'Tanggal Mulai', value: formatDate(project.start_date) },
        { label: 'Tanggal Selesai', value: formatDate(project.end_date) },
        { label: 'Nilai Kontrak', value: formatRupiah(project.contract_value) },
    ];

    // "Project overview: progress bar dari log terbaru" — progressLogs is
    // already ordered newest-first (Project::progressLogs()).
    const latestPercentage = progressLogs[0]?.percentage ?? 0;

    return (
        <div className="space-y-4">
            <Card>
                <CardContent className="grid gap-4 pt-6 sm:grid-cols-2">
                    {fields.map((field) => (
                        <div key={field.label}>
                            <p className="text-xs text-daiku-muted">{field.label}</p>
                            <p className="text-sm font-medium text-daiku-dark">{field.value}</p>
                        </div>
                    ))}
                </CardContent>
            </Card>

            <Card>
                <CardContent className="pt-6">
                    <div className="mb-2 flex items-center justify-between">
                        <p className="text-sm font-medium text-daiku-dark">Progress Keseluruhan</p>
                        <p className="text-sm font-semibold text-daiku-dark">{latestPercentage}%</p>
                    </div>
                    <div className="h-2.5 w-full overflow-hidden rounded-full bg-daiku-gray">
                        <div
                            className="h-full rounded-full bg-info transition-[width]"
                            style={{ width: `${Math.min(latestPercentage, 100)}%` }}
                        />
                    </div>
                    {progressLogs[0] && (
                        <p className="mt-2 text-xs text-daiku-muted">
                            Update terakhir: {progressLogs[0].description} ({formatDate(progressLogs[0].log_date)})
                        </p>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}

function MilestoneTab({
    project,
    milestones,
    canView,
    canManage,
}: {
    project: Project;
    milestones: Milestone[];
    canView: boolean;
    canManage: boolean;
}) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editing, setEditing] = useState<Milestone | null>(null);

    if (!canView) {
        return (
            <p className="rounded-lg border border-daiku-border py-10 text-center text-sm text-daiku-muted">
                Anda tidak punya akses untuk melihat milestone proyek ini.
            </p>
        );
    }

    function openCreate() {
        setEditing(null);
        setDialogOpen(true);
    }

    function openEdit(milestone: Milestone) {
        setEditing(milestone);
        setDialogOpen(true);
    }

    function onDelete(milestone: Milestone) {
        if (!confirm(`Hapus milestone "${milestone.name}"?`)) return;
        router.delete(route('milestones.destroy', { milestone: milestone.id }));
    }

    function onMarkDone(milestone: Milestone) {
        if (!confirm(`Tandai milestone "${milestone.name}" selesai? QA Form akan dibuat otomatis untuk review.`)) return;
        router.post(route('milestones.markDone', { milestone: milestone.id }));
    }

    return (
        <div>
            {canManage && (
                <div className="mb-4 flex justify-end">
                    <Button size="sm" onClick={openCreate}>
                        <Plus className="size-4" />
                        Tambah Milestone
                    </Button>
                </div>
            )}

            {milestones.length === 0 ? (
                <p className="rounded-lg border border-daiku-border py-10 text-center text-sm text-daiku-muted">
                    Belum ada milestone.
                </p>
            ) : (
                <Tabs defaultValue="gantt">
                    <TabsList>
                        <TabsTrigger value="gantt">Gantt</TabsTrigger>
                        <TabsTrigger value="calendar">Kalender</TabsTrigger>
                    </TabsList>
                    <TabsContent value="gantt" className="mt-4">
                        <MilestoneGanttCalendar
                            milestones={milestones}
                            canManage={canManage}
                            onEdit={openEdit}
                            onDelete={onDelete}
                            onMarkDone={onMarkDone}
                        />
                    </TabsContent>
                    <TabsContent value="calendar" className="mt-4">
                        <MilestoneCalendar
                            milestones={milestones}
                            canManage={canManage}
                            onEdit={openEdit}
                            onDelete={onDelete}
                            onMarkDone={onMarkDone}
                        />
                    </TabsContent>
                </Tabs>
            )}

            {canManage && (
                <MilestoneFormDialog
                    open={dialogOpen}
                    onOpenChange={setDialogOpen}
                    projectId={project.id}
                    editing={editing}
                />
            )}
        </div>
    );
}

/** One person's task table — see TaskTab's docblock for why tasks are grouped this way. */
function TaskAssigneeTable({
    assigneeName,
    tasks,
    canManage,
    onStatusClick,
}: {
    assigneeName: string;
    tasks: Task[];
    canManage: boolean;
    onStatusClick: (task: Task) => void;
}) {
    const doneCount = tasks.filter((t) => t.status === 'DONE').length;

    return (
        <div className="overflow-hidden rounded-lg border border-daiku-border">
            <div className="flex items-center justify-between bg-daiku-yellow-light px-3 py-2">
                <p className="text-sm font-semibold text-daiku-dark">{assigneeName}</p>
                <p className="text-xs text-daiku-muted">
                    {doneCount}/{tasks.length} selesai
                </p>
            </div>
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b border-daiku-border text-daiku-muted">
                        <th className="p-2 text-left font-medium">Judul</th>
                        <th className="p-2 text-left font-medium">Milestone</th>
                        <th className="p-2 text-left font-medium">Status</th>
                        <th className="p-2 text-left font-medium">Prioritas</th>
                        <th className="p-2 text-left font-medium">Jatuh Tempo</th>
                        <th className="w-32 p-2" />
                    </tr>
                </thead>
                <tbody>
                    {tasks.map((task) => (
                        <tr key={task.id} className="border-t border-daiku-border">
                            <td className="p-2 font-medium">{task.title}</td>
                            <td className="p-2 text-daiku-muted">{task.milestone?.name ?? '—'}</td>
                            <td className="p-2">
                                <StatusChip status={task.status} />
                            </td>
                            <td className="p-2 text-daiku-muted">{task.priority}</td>
                            <td className="p-2 text-daiku-muted">{formatDate(task.due_date)}</td>
                            <td className="p-2">
                                {canManage && (
                                    <Button variant="outline" size="sm" onClick={() => onStatusClick(task)}>
                                        Update Status
                                    </Button>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

/**
 * "update agar terpisah pisah tabelnya per orangan" — tasks grouped into
 * one table per assignee instead of a single flat table, so a PM
 * reviewing a specific tukang's workload doesn't have to scan/filter a
 * mixed list. Unassigned tasks (assignee_id null) get their own bucket.
 */
function TaskTab({
    project,
    milestones,
    tasks,
    canManage,
    fieldStaff,
}: {
    project: Project;
    milestones: Milestone[];
    tasks: Task[];
    canManage: boolean;
    fieldStaff: Pick<User, 'id' | 'name'>[];
}) {
    const [assignOpen, setAssignOpen] = useState(false);
    const [statusOpen, setStatusOpen] = useState(false);
    const [activeTask, setActiveTask] = useState<Task | null>(null);

    function openStatus(task: Task) {
        setActiveTask(task);
        setStatusOpen(true);
    }

    const groups = useMemo(() => {
        const byAssignee = new Map<string, { name: string; tasks: Task[] }>();

        for (const task of tasks) {
            const key = task.assignee_id ? String(task.assignee_id) : 'unassigned';
            const name = task.assignee?.name ?? 'Belum Ditugaskan';

            if (!byAssignee.has(key)) {
                byAssignee.set(key, { name, tasks: [] });
            }
            byAssignee.get(key)!.tasks.push(task);
        }

        return [...byAssignee.values()].sort((a, b) => a.name.localeCompare(b.name));
    }, [tasks]);

    return (
        <div>
            {canManage && (
                <div className="mb-4 flex justify-end">
                    <Button size="sm" onClick={() => setAssignOpen(true)}>
                        <Plus className="size-4" />
                        Tambah Task
                    </Button>
                </div>
            )}

            {groups.length === 0 ? (
                <p className="rounded-lg border border-daiku-border py-10 text-center text-sm text-daiku-muted">
                    Belum ada task.
                </p>
            ) : (
                <div className="space-y-4">
                    {groups.map((group) => (
                        <TaskAssigneeTable
                            key={group.name}
                            assigneeName={group.name}
                            tasks={group.tasks}
                            canManage={canManage}
                            onStatusClick={openStatus}
                        />
                    ))}
                </div>
            )}

            {canManage && (
                <TaskFormDialog
                    open={assignOpen}
                    onOpenChange={setAssignOpen}
                    projectId={project.id}
                    milestones={milestones}
                    fieldStaff={fieldStaff}
                />
            )}
            <TaskStatusDialog open={statusOpen} onOpenChange={setStatusOpen} task={activeTask} />
        </div>
    );
}

function ProgressTab({
    project,
    progressLogs,
    canView,
    canManage,
}: {
    project: Project;
    progressLogs: ProgressLog[];
    canView: boolean;
    canManage: boolean;
}) {
    const [dialogOpen, setDialogOpen] = useState(false);

    if (!canView) {
        return (
            <p className="rounded-lg border border-daiku-border py-10 text-center text-sm text-daiku-muted">
                Anda tidak punya akses untuk melihat progress log proyek ini.
            </p>
        );
    }

    return (
        <div>
            {canManage && (
                <div className="mb-4 flex justify-end">
                    <Button size="sm" onClick={() => setDialogOpen(true)}>
                        <Plus className="size-4" />
                        Tambah Progress Log
                    </Button>
                </div>
            )}

            <ProgressTimeline logs={progressLogs} />

            {canManage && (
                <ProgressLogFormDialog open={dialogOpen} onOpenChange={setDialogOpen} projectId={project.id} />
            )}
        </div>
    );
}

function FinanceTab({
    project,
    milestones,
    termins,
    canView,
    canCreate,
    canMarkPaid,
    bankAccounts,
}: {
    project: Project;
    milestones: Milestone[];
    termins: Termin[];
    canView: boolean;
    canCreate: boolean;
    canMarkPaid: boolean;
    bankAccounts: Pick<BankAccount, 'id' | 'label'>[];
}) {
    const [dialogOpen, setDialogOpen] = useState(false);

    if (!canView) {
        return (
            <p className="rounded-lg border border-daiku-border py-10 text-center text-sm text-daiku-muted">
                Anda tidak punya akses untuk melihat termin proyek ini.
            </p>
        );
    }

    function onMarkPaid(termin: Termin) {
        if (!confirm(`Tandai termin #${termin.termin_number} sudah dibayar?`)) return;
        router.post(route('finance.termins.markPaid', { termin: termin.id }));
    }

    return (
        <div>
            {canCreate && (
                <div className="mb-4 flex justify-end">
                    <Button size="sm" onClick={() => setDialogOpen(true)}>
                        <Plus className="size-4" />
                        Jadwalkan Termin
                    </Button>
                </div>
            )}

            {termins.length === 0 ? (
                <p className="rounded-lg border border-daiku-border py-10 text-center text-sm text-daiku-muted">
                    Belum ada termin dijadwalkan.
                </p>
            ) : (
                <div className="overflow-hidden rounded-lg border border-daiku-border">
                    <table className="w-full text-sm">
                        <thead className="bg-daiku-yellow-light">
                            <tr>
                                <th className="p-2 text-left font-medium">Termin</th>
                                <th className="p-2 text-left font-medium">Milestone</th>
                                <th className="p-2 text-left font-medium">Jadwal (Sabtu)</th>
                                <th className="p-2 text-right font-medium">Persentase</th>
                                <th className="p-2 text-right font-medium">Nominal</th>
                                <th className="p-2 text-left font-medium">Status</th>
                                <th className="w-40 p-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {termins.map((termin) => (
                                <tr key={termin.id} className="border-t border-daiku-border">
                                    <td className="p-2 font-medium">#{termin.termin_number}</td>
                                    <td className="p-2 text-daiku-muted">{termin.milestone?.name ?? '—'}</td>
                                    <td className="p-2 text-daiku-muted">{formatDate(termin.scheduled_date)}</td>
                                    <td className="p-2 text-right text-daiku-muted">{termin.percentage}%</td>
                                    <td className="p-2 text-right font-medium text-daiku-dark">{formatRupiah(termin.amount)}</td>
                                    <td className="p-2">
                                        <StatusChip status={termin.status} />
                                    </td>
                                    <td className="p-2">
                                        <div className="flex items-center gap-2">
                                            <Button variant="outline" size="icon-sm" asChild>
                                                <a href={route('finance.termins.pdf', { termin: termin.id })} target="_blank" rel="noopener noreferrer">
                                                    <FileDown className="size-4" />
                                                </a>
                                            </Button>
                                            {canMarkPaid && termin.status !== 'PAID' && (
                                                <Button variant="outline" size="sm" onClick={() => onMarkPaid(termin)}>
                                                    Tandai Dibayar
                                                </Button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {canCreate && (
                <TerminFormDialog
                    open={dialogOpen}
                    onOpenChange={setDialogOpen}
                    projectId={project.id}
                    milestones={milestones}
                    bankAccounts={bankAccounts}
                />
            )}
        </div>
    );
}

export default function ProjectShow({
    project,
    milestones,
    canViewMilestones,
    canManageMilestones,
    canManageTasks,
    tasks,
    fieldStaff,
    progressLogs,
    canViewProgressLogs,
    canManageProgressLogs,
    termins,
    canViewTermins,
    canCreateTermins,
    canMarkTerminPaid,
    bankAccounts,
}: ProjectShowProps) {
    return (
        <AppLayout
            breadcrumbs={[
                { label: 'Proyek', routeName: 'projects.index' },
                { label: project.name },
            ]}
        >
            <Head title={project.name} />

            <PageHeader
                title={project.name}
                description={project.lead?.client_name ? `Klien: ${project.lead.client_name}` : undefined}
                actions={<StatusChip status={project.status} />}
            />

            <Tabs defaultValue="overview">
                <TabsList>
                    <TabsTrigger value="overview">Overview</TabsTrigger>
                    <TabsTrigger value="milestone">Milestone</TabsTrigger>
                    <TabsTrigger value="task">Task</TabsTrigger>
                    <TabsTrigger value="progress">Progress</TabsTrigger>
                    <TabsTrigger value="finance">Finance</TabsTrigger>
                </TabsList>
                <TabsContent value="overview" className="mt-4">
                    <OverviewTab project={project} progressLogs={progressLogs} />
                </TabsContent>
                <TabsContent value="milestone" className="mt-4">
                    <MilestoneTab
                        project={project}
                        milestones={milestones}
                        canView={canViewMilestones}
                        canManage={canManageMilestones}
                    />
                </TabsContent>
                <TabsContent value="task" className="mt-4">
                    <TaskTab
                        project={project}
                        milestones={milestones}
                        tasks={tasks}
                        canManage={canManageTasks}
                        fieldStaff={fieldStaff}
                    />
                </TabsContent>
                <TabsContent value="progress" className="mt-4">
                    <ProgressTab
                        project={project}
                        progressLogs={progressLogs}
                        canView={canViewProgressLogs}
                        canManage={canManageProgressLogs}
                    />
                </TabsContent>
                <TabsContent value="finance" className="mt-4">
                    <FinanceTab
                        project={project}
                        milestones={milestones}
                        termins={termins}
                        canView={canViewTermins}
                        canCreate={canCreateTermins}
                        canMarkPaid={canMarkTerminPaid}
                        bankAccounts={bankAccounts}
                    />
                </TabsContent>
            </Tabs>
        </AppLayout>
    );
}
