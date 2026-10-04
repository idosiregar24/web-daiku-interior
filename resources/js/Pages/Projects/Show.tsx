import { formatDateTime, formatRupiah } from '@/lib/format';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import { EmptyState } from '@/Components/shared/EmptyState';
import { DetailItem, DetailList } from '@/Components/shared/DetailList';
import { Notice } from '@/Components/shared/Notice';
import { ProgressBar } from '@/Components/shared/ProgressBar';
import { SectionCard } from '@/Components/shared/SectionCard';
import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { UnderlineTabsList } from '@/Components/shared/UnderlineTabsList';
import { Button } from '@/Components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { MilestoneCalendar } from '@/Components/modules/projects/MilestoneCalendar';
import { MilestoneFormDialog } from '@/Components/modules/projects/MilestoneFormDialog';
import { MilestoneGanttCalendar } from '@/Components/modules/projects/MilestoneGanttCalendar';
import { ProgressLogFormDialog } from '@/Components/modules/projects/ProgressLogFormDialog';
import { ProgressTimeline } from '@/Components/modules/projects/ProgressTimeline';
import { ProjectFormDialog } from '@/Components/modules/projects/ProjectFormDialog';
import { TaskDeleteDialog } from '@/Components/modules/projects/TaskDeleteDialog';
import { TaskFormDialog } from '@/Components/modules/projects/TaskFormDialog';
import { TaskKanbanBoard } from '@/Components/modules/projects/TaskKanbanBoard';
import { TaskRowMenu } from '@/Components/modules/projects/TaskRowMenu';
import { TaskStatusDialog } from '@/Components/modules/projects/TaskStatusDialog';
import { TerminFormDialog } from '@/Components/modules/projects/TerminFormDialog';
import { type MaterialPermissions, ProjectMaterialsPanel } from '@/Components/modules/projects/ProjectMaterialsPanel';
import AppLayout from '@/Layouts/AppLayout';
import type {
    BankAccount,
    FinanceAllocationLine,
    Material,
    MaterialCategory,
    Milestone,
    ProgressLog,
    Project,
    ProjectMaterial,
    ProjectStatusNote,
    SupplierDebt,
    Task,
    Termin,
    User,
    UnitOption,
    VendorOption,
} from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    FileDown,
    Flag,
    FolderKanban,
    Info,
    LayoutDashboard,
    ListChecks,
    Package,
    PenLine,
    PieChart,
    Plus,
    Receipt,
    Wallet,
} from 'lucide-react';
import { useMemo, useState } from 'react';

interface ProjectShowProps {
    project: Project;
    /** ProjectPolicy::update() and not COMPLETED/CANCELLED. */
    canEditProject: boolean;
    /** CEO only — PRD §4.4 "PM di-assign oleh CEO". */
    canChangePm: boolean;
    projectManagers: Pick<User, 'id' | 'name'>[];
    hasTerminPayments: boolean;
    statusNote: ProjectStatusNote | null;
    milestones: Milestone[];
    canViewMilestones: boolean;
    canManageMilestones: boolean;
    canManageTasks: boolean;
    canViewTasks: boolean;
    tasks: Task[];
    fieldStaff: Pick<User, 'id' | 'name' | 'is_active'>[];
    progressLogs: ProgressLog[];
    canViewProgressLogs: boolean;
    canManageProgressLogs: boolean;
    termins: Termin[];
    canViewTermins: boolean;
    canCreateTermins: boolean;
    canMarkTerminPaid: boolean;
    bankAccounts: Pick<BankAccount, 'id' | 'label'>[];
    allocationBreakdown: FinanceAllocationLine[];
    supplierDebts: SupplierDebt[];
    projectMaterials: ProjectMaterial[];
    canViewMaterials: boolean;
    materialPermissions: MaterialPermissions;
    materialOptions: Pick<Material, 'id' | 'code' | 'name' | 'unit_id' | 'unit' | 'stock' | 'cost_price'>[];
    catalogOptions: Pick<Material, 'id' | 'name' | 'unit_id'>[];
    vendors: VendorOption[];
    units: UnitOption[];
    materialCategories: Pick<MaterialCategory, 'id' | 'name' | 'code_prefix'>[];
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
        <div className="grid gap-6 lg:grid-cols-3">
            <SectionCard title="Informasi Proyek" icon={Info} className="lg:col-span-2">
                <DetailList>
                    {fields.map((field) => (
                        <DetailItem key={field.label} label={field.label} valueClassName="font-medium">
                            {field.value}
                        </DetailItem>
                    ))}
                </DetailList>
            </SectionCard>

            <SectionCard title="Progress Keseluruhan" icon={Activity}>
                <p className="text-4xl leading-none font-semibold tracking-tight text-foreground">{latestPercentage}%</p>
                <ProgressBar value={latestPercentage} label="Progress keseluruhan proyek" className="mt-4" />
                {progressLogs[0] && (
                    <p className="mt-3 text-xs text-daiku-muted">
                        Update terakhir: {progressLogs[0].description} ({formatDate(progressLogs[0].log_date)})
                    </p>
                )}
            </SectionCard>
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
            <EmptyState className="rounded-xl border border-dashed border-border" title="Anda tidak punya akses untuk melihat milestone proyek ini." />
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
                <EmptyState className="rounded-xl border border-dashed border-border" title="Belum ada milestone." />
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
    canEdit,
    onStatusClick,
    onEditClick,
    onDeleteClick,
}: {
    assigneeName: string;
    tasks: Task[];
    canManage: boolean;
    /** Edit/delete — PM on a project that isn't COMPLETED/CANCELLED. */
    canEdit: boolean;
    onStatusClick: (task: Task) => void;
    onEditClick: (task: Task) => void;
    onDeleteClick: (task: Task) => void;
}) {
    const doneCount = tasks.filter((t) => t.status === 'DONE').length;

    return (
        <TableCard>
            <div className="flex items-center justify-between gap-4 border-b border-border px-4 py-3">
                <p className="flex items-center gap-2.5 text-sm font-semibold text-foreground">
                    <span className="flex size-7 items-center justify-center rounded-full bg-daiku-yellow-light text-xs font-semibold text-daiku-yellow-dark">
                        {assigneeName.slice(0, 1).toUpperCase()}
                    </span>
                    {assigneeName}
                </p>
                <div className="flex w-40 items-center gap-2">
                    <ProgressBar
                        value={tasks.length ? Math.round((doneCount / tasks.length) * 100) : 0}
                        label={`Task selesai ${assigneeName}`}
                        tone="success"
                        className="flex-1"
                    />
                    <p className="shrink-0 text-xs text-daiku-muted tabular-nums">
                        {doneCount}/{tasks.length} selesai
                    </p>
                </div>
            </div>
            <table className="w-full text-sm">
                <thead className={TABLE_HEAD_CLASS}>
                    <tr>
                        <th className="px-4 py-2.5 text-left font-semibold">Judul</th>
                        <th className="px-4 py-2.5 text-left font-semibold">Milestone</th>
                        <th className="px-4 py-2.5 text-left font-semibold">Status</th>
                        <th className="px-4 py-2.5 text-left font-semibold">Prioritas</th>
                        <th className="px-4 py-2.5 text-left font-semibold">Jatuh Tempo</th>
                        <th className="w-44 px-4 py-2.5" />
                    </tr>
                </thead>
                <tbody>
                    {tasks.map((task) => (
                        <tr key={task.id} className="border-t border-border transition-colors hover:bg-daiku-gray/60">
                            <td className="px-4 py-3 font-medium">{task.title}</td>
                            <td className="px-4 py-3 text-daiku-muted">{task.milestone?.name ?? '—'}</td>
                            <td className="px-4 py-3">
                                <div className="flex flex-wrap gap-1">
                                    <StatusChip status={task.status} />
                                    {task.status === 'DONE' && task.is_wage_paid && (
                                        <StatusChip status="PAID" label="Upah dibayar" />
                                    )}
                                </div>
                            </td>
                            <td className="px-4 py-3 text-daiku-muted">{task.priority}</td>
                            <td className="px-4 py-3 text-daiku-muted">{formatDate(task.due_date)}</td>
                            <td className="px-4 py-3">
                                {canManage && (
                                    <div className="flex items-center justify-end gap-1">
                                        <Button variant="outline" size="sm" onClick={() => onStatusClick(task)}>
                                            Update Status
                                        </Button>
                                        {canEdit && (
                                            <TaskRowMenu task={task} onEdit={onEditClick} onDelete={onDeleteClick} />
                                        )}
                                    </div>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </TableCard>
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
    isClosed,
    fieldStaff,
}: {
    project: Project;
    milestones: Milestone[];
    tasks: Task[];
    canManage: boolean;
    /** COMPLETED/CANCELLED — the task plan is final (TaskService refuses create/edit/delete). */
    isClosed: boolean;
    fieldStaff: Pick<User, 'id' | 'name' | 'is_active'>[];
}) {
    const [formOpen, setFormOpen] = useState(false);
    const [editingTask, setEditingTask] = useState<Task | null>(null);
    const [statusOpen, setStatusOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [activeTask, setActiveTask] = useState<Task | null>(null);
    const canEdit = canManage && !isClosed;

    function openStatus(task: Task) {
        setActiveTask(task);
        setStatusOpen(true);
    }

    function openCreate() {
        setEditingTask(null);
        setFormOpen(true);
    }

    function openEdit(task: Task) {
        setEditingTask(task);
        setFormOpen(true);
    }

    function openDelete(task: Task) {
        setActiveTask(task);
        setDeleteOpen(true);
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
            {canEdit && (
                <div className="mb-4 flex justify-end">
                    <Button size="sm" onClick={openCreate}>
                        <Plus className="size-4" />
                        Tambah Task
                    </Button>
                </div>
            )}

            {groups.length === 0 ? (
                <EmptyState className="rounded-xl border border-dashed border-border" title="Belum ada task." />
            ) : (
                <Tabs defaultValue="assignee">
                    <TabsList>
                        <TabsTrigger value="assignee">Per Tukang</TabsTrigger>
                        <TabsTrigger value="board">Board</TabsTrigger>
                    </TabsList>
                    <TabsContent value="assignee" className="mt-4 space-y-4">
                        {groups.map((group) => (
                            <TaskAssigneeTable
                                key={group.name}
                                assigneeName={group.name}
                                tasks={group.tasks}
                                canManage={canManage}
                                canEdit={canEdit}
                                onStatusClick={openStatus}
                                onEditClick={openEdit}
                                onDeleteClick={openDelete}
                            />
                        ))}
                    </TabsContent>
                    <TabsContent value="board" className="mt-4">
                        <TaskKanbanBoard
                            tasks={tasks}
                            milestones={milestones}
                            onCardClick={canManage ? openStatus : undefined}
                        />
                    </TabsContent>
                </Tabs>
            )}

            {canEdit && (
                <>
                    <TaskFormDialog
                        open={formOpen}
                        onOpenChange={setFormOpen}
                        projectId={project.id}
                        editing={editingTask}
                        milestones={milestones}
                        fieldStaff={fieldStaff}
                    />
                    <TaskDeleteDialog open={deleteOpen} onOpenChange={setDeleteOpen} task={activeTask} />
                </>
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
            <EmptyState className="rounded-xl border border-dashed border-border" title="Anda tidak punya akses untuk melihat progress log proyek ini." />
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
    allocationBreakdown,
    supplierDebts,
}: {
    project: Project;
    milestones: Milestone[];
    termins: Termin[];
    canView: boolean;
    canCreate: boolean;
    canMarkPaid: boolean;
    bankAccounts: Pick<BankAccount, 'id' | 'label'>[];
    allocationBreakdown: FinanceAllocationLine[];
    supplierDebts: SupplierDebt[];
}) {
    const [dialogOpen, setDialogOpen] = useState(false);

    if (!canView) {
        return (
            <EmptyState className="rounded-xl border border-dashed border-border" title="Anda tidak punya akses untuk melihat termin proyek ini." />
        );
    }

    function onMarkPaid(termin: Termin) {
        if (!confirm(`Tandai termin #${termin.termin_number} sudah dibayar?`)) return;
        router.post(route('finance.termins.markPaid', { termin: termin.id }));
    }

    return (
        <div className="space-y-6">
            <div className="grid gap-6 lg:grid-cols-2">
                <AllocationCard project={project} lines={allocationBreakdown} />
                <SupplierDebtCard debts={supplierDebts} />
            </div>

            <div>
            <div className="mb-3 flex items-center justify-between gap-4">
                <h2 className="flex items-center gap-2 text-sm font-semibold text-foreground">
                    <Wallet className="size-4 text-muted-foreground" />
                    Termin
                </h2>
                {canCreate && (
                    <Button size="sm" onClick={() => setDialogOpen(true)}>
                        <Plus className="size-4" />
                        Jadwalkan Termin
                    </Button>
                )}
            </div>

            {termins.length === 0 ? (
                <EmptyState className="rounded-xl border border-dashed border-border" title="Belum ada termin dijadwalkan." />
            ) : (
                <TableCard>
                    <table className="w-full text-sm">
                        <thead className={TABLE_HEAD_CLASS}>
                            <tr>
                                <th className="px-4 py-2.5 text-left font-semibold">Termin</th>
                                <th className="px-4 py-2.5 text-left font-semibold">Milestone</th>
                                <th className="px-4 py-2.5 text-left font-semibold">Jadwal (Sabtu)</th>
                                <th className="px-4 py-2.5 text-right font-semibold">Persentase</th>
                                <th className="px-4 py-2.5 text-right font-semibold">Nominal</th>
                                <th className="px-4 py-2.5 text-right font-semibold">DP</th>
                                <th className="px-4 py-2.5 text-right font-semibold">Pelunasan</th>
                                <th className="px-4 py-2.5 text-right font-semibold">Sisa Piutang</th>
                                <th className="px-4 py-2.5 text-left font-semibold">Status</th>
                                <th className="w-40 px-4 py-2.5" />
                            </tr>
                        </thead>
                        <tbody>
                            {termins.map((termin) => (
                                <tr key={termin.id} className="border-t border-border transition-colors hover:bg-daiku-gray/60">
                                    <td className="px-4 py-3 font-medium">#{termin.termin_number}</td>
                                    <td className="px-4 py-3 text-daiku-muted">{termin.milestone?.name ?? '—'}</td>
                                    <td className="px-4 py-3 text-daiku-muted">{formatDate(termin.scheduled_date)}</td>
                                    <td className="px-4 py-3 text-right text-daiku-muted">{termin.percentage}%</td>
                                    <td className="px-4 py-3 text-right font-medium text-daiku-dark">{formatRupiah(termin.amount)}</td>
                                    <td className="px-4 py-3 text-right text-daiku-muted">{formatRupiah(termin.dp_amount)}</td>
                                    <td className="px-4 py-3 text-right text-daiku-muted">{formatRupiah(termin.pelunasan)}</td>
                                    <td className="px-4 py-3 text-right font-medium">{formatRupiah(termin.sisa_piutang)}</td>
                                    <td className="px-4 py-3">
                                        <div className="flex flex-wrap gap-1">
                                            <StatusChip status={termin.status} />
                                            {Number(termin.dp_amount) + Number(termin.pelunasan) > 0 &&
                                                Number(termin.sisa_piutang) > 0 && (
                                                    <StatusChip status="PARTIAL" label="Dibayar Sebagian" />
                                                )}
                                        </div>
                                    </td>
                                    <td className="px-4 py-3">
                                        <div className="flex items-center gap-2">
                                            <Button variant="outline" size="icon-sm" asChild>
                                                <a href={route('finance.termins.pdf', { termin: termin.id })} target="_blank" rel="noopener noreferrer">
                                                    <FileDown className="size-4" />
                                                </a>
                                            </Button>
                                            {canMarkPaid && termin.status !== 'PAID' && termin.bank_account_id !== null && (
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
                </TableCard>
            )}

            </div>

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

/** PRD §4.7 "Alokasi Persentase Otomatis" — budget per pos from the contract value; informational, writes nothing. */
function AllocationCard({ project, lines }: { project: Project; lines: FinanceAllocationLine[] }) {
    const total = lines.reduce((sum, line) => sum + line.amount, 0);
    const totalPercentage = lines.reduce((sum, line) => sum + line.percentage, 0);

    return (
        <SectionCard
            title="Alokasi Anggaran"
            icon={PieChart}
            description={`Dari nilai kontrak ${formatRupiah(project.contract_value)}.`}
        >
            {lines.length === 0 ? (
                <p className="text-sm text-daiku-muted">Belum ada konfigurasi alokasi aktif.</p>
            ) : (
                <dl className="divide-y divide-border text-sm">
                    {lines.map((line) => (
                        <div key={line.label} className="flex justify-between gap-4 py-2 first:pt-0">
                            <dt className="text-daiku-muted">
                                {line.label} ({line.percentage.toLocaleString('id-ID')}%)
                            </dt>
                            <dd className="tabular-nums">{formatRupiah(line.amount)}</dd>
                        </div>
                    ))}
                    <div className="flex justify-between gap-4 pt-2 font-semibold">
                        <dt>Total ({totalPercentage.toLocaleString('id-ID')}%)</dt>
                        <dd className="tabular-nums">{formatRupiah(total)}</dd>
                    </div>
                </dl>
            )}
    </SectionCard>
    );
}

/** PRD §4.7 "Hutang Supplier" — outstanding debts tied to this project. */
function SupplierDebtCard({ debts }: { debts: SupplierDebt[] }) {
    const total = debts.reduce((sum, debt) => sum + Number(debt.remaining), 0);

    return (
        <SectionCard title="Hutang Supplier Belum Lunas" icon={Receipt} description={`Total sisa ${formatRupiah(total)}.`}>
            {debts.length === 0 ? (
                <p className="text-sm text-daiku-muted">Tidak ada hutang supplier untuk proyek ini.</p>
            ) : (
                <ul className="divide-y divide-border text-sm">
                    {debts.map((debt) => (
                        <li key={debt.id} className="flex items-center justify-between gap-4 py-2.5 first:pt-0 last:pb-0">
                            <div>
                                <Link
                                    href={route('finance.supplierDebts.show', { supplierDebt: debt.id })}
                                    className="font-medium text-daiku-dark hover:underline"
                                >
                                    {debt.vendor?.name}
                                </Link>
                                <p className="text-xs text-daiku-muted">
                                    Jatuh tempo {formatDate(debt.due_date)}
                                </p>
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="font-medium">{formatRupiah(debt.remaining)}</span>
                                {debt.status && <StatusChip status={debt.status} label={SUPPLIER_DEBT_LABEL[debt.status]} />}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
    </SectionCard>
    );
}

/** Top-level tab → breadcrumb label. */
const PROJECT_TAB_LABEL: Record<string, string> = {
    overview: 'Overview',
    milestone: 'Milestone',
    task: 'Task',
    progress: 'Progress',
    finance: 'Finance',
    material: 'Material',
};

const SUPPLIER_DEBT_LABEL: Record<NonNullable<SupplierDebt['status']>, string> = {
    BERJALAN: 'Berjalan',
    JATUH_TEMPO: 'Jatuh Tempo',
    LUNAS: 'Lunas',
};

/** Why a non-ACTIVE project looks the way it does — status + (from the audit trail) who/why. */
function ProjectStatusNotice({ project, statusNote }: { project: Project; statusNote: ProjectStatusNote | null }) {
    const reason = statusNote?.note ? ` Alasan: ${statusNote.note}` : '';
    const byline = statusNote ? ` (${statusNote.by ?? 'Sistem'}, ${formatDateTime(statusNote.at)})` : '';

    switch (project.status) {
        case 'ON_HOLD':
            return (
                <Notice tone="warning" className="mb-6">
                    Proyek sedang ditahan (ON HOLD){byline} — penalti form harian dan penanda overdue otomatis dijeda.
                    {reason}
                </Notice>
            );
        case 'CANCELLED':
            return (
                <Notice tone="error" className="mb-6">
                    Proyek dibatalkan{byline} dan bersifat read-only.{reason}
                </Notice>
            );
        case 'COMPLETED':
            return (
                <Notice tone="success" className="mb-6">
                    Proyek selesai — semua milestone lolos QA. Data proyek tidak bisa diubah lagi.
                </Notice>
            );
        default:
            return null;
    }
}

export default function ProjectShow({
    project,
    canEditProject,
    canChangePm,
    projectManagers,
    hasTerminPayments,
    statusNote,
    milestones,
    canViewMilestones,
    canManageMilestones,
    canManageTasks,
    canViewTasks,
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
    allocationBreakdown,
    supplierDebts,
    projectMaterials,
    canViewMaterials,
    materialPermissions,
    materialOptions,
    catalogOptions,
    vendors,
    units,
    materialCategories,
}: ProjectShowProps) {
    const [tab, setTab] = useState('overview');
    const [editOpen, setEditOpen] = useState(false);
    const isClosed = project.status === 'COMPLETED' || project.status === 'CANCELLED';

    return (
        <AppLayout
            breadcrumbs={[{ label: project.name }, { label: PROJECT_TAB_LABEL[tab] }]}
        >
            <Head title={project.name} />

            <PageHeader
                title={project.name}
                icon={FolderKanban}
                description={project.lead?.client_name ? `Klien: ${project.lead.client_name}` : undefined}
                actions={
                    <>
                        <StatusChip status={project.status} />
                        {canEditProject && (
                            <Button variant="outline" onClick={() => setEditOpen(true)}>
                                <PenLine className="size-4" />
                                Edit Proyek
                            </Button>
                        )}
                    </>
                }
            />

            <ProjectStatusNotice project={project} statusNote={statusNote} />

            {canEditProject && (
                <ProjectFormDialog
                    open={editOpen}
                    onOpenChange={setEditOpen}
                    project={project}
                    canChangePm={canChangePm}
                    projectManagers={projectManagers}
                    hasTerminPayments={hasTerminPayments}
                />
            )}

            <Tabs value={tab} onValueChange={setTab}>
                <UnderlineTabsList>
                    <TabsTrigger value="overview">
                        <LayoutDashboard />
                        Overview
                    </TabsTrigger>
                    <TabsTrigger value="milestone">
                        <Flag />
                        Milestone
                    </TabsTrigger>
                    {canViewTasks && (
                        <TabsTrigger value="task">
                            <ListChecks />
                            Task
                        </TabsTrigger>
                    )}
                    <TabsTrigger value="progress">
                        <Activity />
                        Progress
                    </TabsTrigger>
                    <TabsTrigger value="finance">
                        <Wallet />
                        Finance
                    </TabsTrigger>
                    {canViewMaterials && (
                        <TabsTrigger value="material">
                            <Package />
                            Material
                        </TabsTrigger>
                    )}
                </UnderlineTabsList>
                <TabsContent value="overview" className="mt-6">
                    <OverviewTab project={project} progressLogs={progressLogs} />
                </TabsContent>
                <TabsContent value="milestone" className="mt-6">
                    <MilestoneTab
                        project={project}
                        milestones={milestones}
                        canView={canViewMilestones}
                        canManage={canManageMilestones && !isClosed}
                    />
                </TabsContent>
                <TabsContent value="task" className="mt-6">
                    <TaskTab
                        project={project}
                        milestones={milestones}
                        tasks={tasks}
                        canManage={canManageTasks}
                        isClosed={isClosed}
                        fieldStaff={fieldStaff}
                    />
                </TabsContent>
                <TabsContent value="progress" className="mt-6">
                    <ProgressTab
                        project={project}
                        progressLogs={progressLogs}
                        canView={canViewProgressLogs}
                        canManage={canManageProgressLogs}
                    />
                </TabsContent>
                <TabsContent value="finance" className="mt-6">
                    <FinanceTab
                        project={project}
                        milestones={milestones}
                        termins={termins}
                        canView={canViewTermins}
                        canCreate={canCreateTermins}
                        canMarkPaid={canMarkTerminPaid}
                        bankAccounts={bankAccounts}
                        allocationBreakdown={allocationBreakdown}
                        supplierDebts={supplierDebts}
                    />
                </TabsContent>
                {canViewMaterials && (
                    <TabsContent value="material" className="mt-6">
                        <ProjectMaterialsPanel
                            project={project}
                            items={projectMaterials}
                            canView={canViewMaterials}
                            permissions={materialPermissions}
                            materialOptions={materialOptions}
                            catalogOptions={catalogOptions}
                            vendors={vendors}
                            units={units}
                            materialCategories={materialCategories}
                        />
                    </TabsContent>
                )}
            </Tabs>
        </AppLayout>
    );
}
