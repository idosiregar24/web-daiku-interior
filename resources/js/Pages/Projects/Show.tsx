import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { MilestoneFormDialog } from '@/Components/modules/projects/MilestoneFormDialog';
import AppLayout from '@/Layouts/AppLayout';
import type { Milestone, Project, Task } from '@/types';
import { Head, router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface ProjectShowProps {
    project: Project;
    milestones: Milestone[];
    canViewMilestones: boolean;
    canManageMilestones: boolean;
    tasks: Task[];
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

function OverviewTab({ project }: { project: Project }) {
    const fields: { label: string; value: string }[] = [
        { label: 'Klien', value: project.lead?.client_name ?? '—' },
        { label: 'Project Manager', value: project.pm?.name ?? '—' },
        { label: 'Tanggal Mulai', value: formatDate(project.start_date) },
        { label: 'Tanggal Selesai', value: formatDate(project.end_date) },
        { label: 'Nilai Kontrak', value: formatRupiah(project.contract_value) },
    ];

    return (
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
                <ol className="space-y-2">
                    {milestones.map((milestone, index) => (
                        <li
                            key={milestone.id}
                            className="flex items-center justify-between gap-4 rounded-lg border border-daiku-border p-3"
                        >
                            <div className="flex items-center gap-3">
                                <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-daiku-yellow-light text-xs font-semibold text-daiku-dark">
                                    {index + 1}
                                </span>
                                <div>
                                    <p className="text-sm font-medium text-daiku-dark">{milestone.name}</p>
                                    <p className="text-xs text-daiku-muted">
                                        Target: {formatDate(milestone.target_date)}
                                    </p>
                                </div>
                            </div>
                            <div className="flex shrink-0 items-center gap-2">
                                <StatusChip status={milestone.status} />
                                {canManage && (
                                    <>
                                        <Button variant="ghost" size="icon-sm" onClick={() => openEdit(milestone)}>
                                            <Pencil className="size-4" />
                                        </Button>
                                        <Button variant="ghost" size="icon-sm" onClick={() => onDelete(milestone)}>
                                            <Trash2 className="size-4 text-error" />
                                        </Button>
                                    </>
                                )}
                            </div>
                        </li>
                    ))}
                </ol>
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

function TaskTab({ tasks }: { tasks: Task[] }) {
    if (tasks.length === 0) {
        return (
            <p className="rounded-lg border border-daiku-border py-10 text-center text-sm text-daiku-muted">
                Belum ada task. Penugasan task ke tukang dibangun pada Sprint 2 Week 4.
            </p>
        );
    }

    return (
        <div className="overflow-hidden rounded-lg border border-daiku-border">
            <table className="w-full text-sm">
                <thead className="bg-daiku-yellow-light">
                    <tr>
                        <th className="p-2 text-left font-medium">Judul</th>
                        <th className="p-2 text-left font-medium">Assignee</th>
                        <th className="p-2 text-left font-medium">Status</th>
                        <th className="p-2 text-left font-medium">Prioritas</th>
                        <th className="p-2 text-left font-medium">Jatuh Tempo</th>
                    </tr>
                </thead>
                <tbody>
                    {tasks.map((task) => (
                        <tr key={task.id} className="border-t border-daiku-border">
                            <td className="p-2 font-medium">{task.title}</td>
                            <td className="p-2 text-daiku-muted">{task.assignee?.name ?? '—'}</td>
                            <td className="p-2">
                                <StatusChip status={task.status} />
                            </td>
                            <td className="p-2 text-daiku-muted">{task.priority}</td>
                            <td className="p-2 text-daiku-muted">{formatDate(task.due_date)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function FinanceTab() {
    return (
        <p className="rounded-lg border border-daiku-border py-10 text-center text-sm text-daiku-muted">
            Modul Finance belum tersedia — akan dibangun pada sprint berikutnya (PRD §4.7).
        </p>
    );
}

export default function ProjectShow({ project, milestones, canViewMilestones, canManageMilestones, tasks }: ProjectShowProps) {
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
                    <TabsTrigger value="finance">Finance</TabsTrigger>
                </TabsList>
                <TabsContent value="overview" className="mt-4">
                    <OverviewTab project={project} />
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
                    <TaskTab tasks={tasks} />
                </TabsContent>
                <TabsContent value="finance" className="mt-4">
                    <FinanceTab />
                </TabsContent>
            </Tabs>
        </AppLayout>
    );
}
