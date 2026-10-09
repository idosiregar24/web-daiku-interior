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
import { BudgetAllocationTab } from '@/Components/modules/projects/BudgetAllocationTab';
import { RequestAddendumDialog } from '@/Components/modules/projects/RequestAddendumDialog';
import { MilestoneCalendar } from '@/Components/modules/projects/MilestoneCalendar';
import { MilestoneFormDialog } from '@/Components/modules/projects/MilestoneFormDialog';
import { MilestoneGanttCalendar } from '@/Components/modules/projects/MilestoneGanttCalendar';
import { MilestoneList } from '@/Components/modules/projects/MilestoneList';
import { ProgressLogFormDialog } from '@/Components/modules/projects/ProgressLogFormDialog';
import { ProgressTimeline } from '@/Components/modules/projects/ProgressTimeline';
import { ProjectFormDialog } from '@/Components/modules/projects/ProjectFormDialog';
import { TaskDeleteDialog } from '@/Components/modules/projects/TaskDeleteDialog';
import { TaskFormDialog } from '@/Components/modules/projects/TaskFormDialog';
import { TaskKanbanBoard } from '@/Components/modules/projects/TaskKanbanBoard';
import { TaskRowMenu } from '@/Components/modules/projects/TaskRowMenu';
import { TaskStatusDialog } from '@/Components/modules/projects/TaskStatusDialog';
import { INVOICE_TYPE_LABEL, InvoiceProofButton, IssueInvoiceDialog } from '@/Components/modules/finance/InvoiceDialogs';
import { type MaterialPermissions, ProjectMaterialsPanel } from '@/Components/modules/projects/ProjectMaterialsPanel';
import { ProjectOvertimeTab } from '@/Components/modules/projects/ProjectOvertimeTab';
import { ProjectQaTab } from '@/Components/modules/projects/ProjectQaTab';
import { BELOW_MD, useMediaQuery } from '@/hooks/useMediaQuery';
import { useQueryTab } from '@/hooks/useQueryTab';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import type {
    FinanceAllocationLine,
    Invoice,
    Material,
    MaterialCategory,
    Milestone,
    OvertimeRequest,
    ProgressLog,
    Project,
    ProjectBudget,
    ProjectMaterial,
    ProjectStatusNote,
    QaForm,
    Quotation,
    SupplierDebt,
    Task,
    Termin,
    User,
    UnitOption,
    VendorOption,
    PageProps,
} from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    ChevronRight,
    Clock,
    FileDown,
    Hourglass,
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
    FileText,
    ShieldCheck,
    Wallet,
    Layers,
    FilePlus2,
    GalleryHorizontalEnd,
} from 'lucide-react';
import { useMemo, useState } from 'react';

interface ProjectShowProps {
    project: Project;
    /** ProjectPolicy::update() and not COMPLETED/CANCELLED. */
    canEditProject: boolean;
    /** CEO only — PRD §4.4 "PM di-assign oleh CEO". */
    canChangePm: boolean;
    projectManagers: Pick<User, 'id' | 'name'>[];
    /** Sprint 12 D2 — Asisten PM choices for Edit Proyek. */
    assistantPms: Pick<User, 'id' | 'name'>[];
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
    /** Sprint 13 #3 — Tab QA: milestone QA forms (CEO/PM/Asisten PM/QA). */
    qaForms: QaForm[];
    canViewQa: boolean;
    /** qa-forms.show is role:CEO|PM|QA — the Asisten PM reads the tab only. */
    canOpenQaForm: boolean;
    /** Sprint 13 #3 — Tab Lembur (CEO/PM/Asisten PM/Finance). */
    overtimeRequests: OvertimeRequest[];
    canViewOvertime: boolean;
    canOpenOvertimeList: boolean;
    termins: Termin[];
    canViewTermins: boolean;
    canViewFinanceSummary: boolean;
    canIssueTerminInvoices: boolean;
    canMarkTerminPaid: boolean;
    /** Sprint 17 Sub 07 — "Tandai Klien Sudah Bayar" on invoice rows (Marketing / Finance). */
    canSubmitInvoiceProof: boolean;
    /** Sprint 12 #21 — RAB Fix + invoices (null without access). */
    documents: ProjectDocuments | null;
    /** Sprint 12 #23–#26 — Alokasi Dana Proyek (CEO / Finance / PM; null otherwise). */
    budget: ProjectBudget | null;
    /** The project's own PM, project not closed. */
    canManageBudget: boolean;
    /** Sprint 12 #29 — Marketing or the project's PM, project running with a RAB Fix. */
    canRequestAddendum: boolean;
    /** Sprint 12 #28 — CEO decides held realisations. */
    canDecideOverrun: boolean;
    budgetVendors: VendorOption[];
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

interface ProjectDocuments {
    quotation: { id: number; version: number; total_amount: string; client_approved_at: string | null } | null;
    /** Sprint 12 #29 — RAB Tambahan of this project, any status. */
    addenda: Pick<Quotation, 'id' | 'version' | 'status' | 'total_amount' | 'request_note' | 'client_approved_at' | 'created_at'>[];
    invoices: Pick<Invoice, 'id' | 'number' | 'type' | 'amount' | 'due_date' | 'status' | 'paid_date' | 'reject_reason'>[];
}

function formatDate(value: string | null) {
    return value ? new Date(value).toLocaleDateString('id-ID') : '—';
}

/** One line of the Overview's "Menunggu di proyek ini" — opens the tab where it's handled. */
interface WaitingRow {
    key: string;
    label: string;
    detail: string;
    tab: string;
    tone: 'warning' | 'error' | 'info';
}

const WAITING_TONE: Record<WaitingRow['tone'], string> = {
    warning: 'bg-warning/10 text-warning-ink',
    error: 'bg-error/10 text-error-ink',
    info: 'bg-info/10 text-info-ink',
};

function OverviewTab({
    project,
    progressLogs,
    waiting,
    onOpenTab,
}: {
    project: Project;
    progressLogs: ProgressLog[];
    waiting: WaitingRow[];
    onOpenTab: (tab: string) => void;
}) {
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

            <SectionCard
                title="Menunggu di Proyek Ini"
                description="Hal yang belum selesai diproses — klik untuk membuka tabnya."
                icon={Hourglass}
                flush
                className="lg:col-span-2"
            >
                {waiting.length === 0 ? (
                    <EmptyState title="Tidak ada yang menunggu." />
                ) : (
                    <ul className="divide-y divide-border">
                        {waiting.map((row) => (
                            <li key={row.key}>
                                <button
                                    type="button"
                                    onClick={() => onOpenTab(row.tab)}
                                    className="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-daiku-yellow-light/60 sm:px-5"
                                >
                                    <span className={cn('rounded-md px-1.5 py-0.5 text-xs font-semibold tabular-nums', WAITING_TONE[row.tone])}>
                                        {row.label}
                                    </span>
                                    <span className="min-w-0 flex-1 truncate text-sm text-foreground">{row.detail}</span>
                                    <ChevronRight className="size-4 shrink-0 text-muted-foreground/60" />
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </SectionCard>

            {/* Beside the project info; "Menunggu" goes under it. */}
            <SectionCard title="Progress Keseluruhan" icon={Activity} className="self-start lg:col-start-3 lg:row-start-1">

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
    // Sprint 13 P2 — Gantt/calendar need width; a phone gets the plain list.
    const compact = useMediaQuery(BELOW_MD);

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
            ) : compact ? (
                <MilestoneList milestones={milestones} canManage={canManage} onEdit={openEdit} onDelete={onDelete} onMarkDone={onMarkDone} />
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
                                            Ubah Status Task
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

/** Sprint 12 #12 — when a scheme termin falls due (App\Enums\PaymentTermTrigger). */
function terminTrigger(termin: Termin): string {
    switch (termin.trigger) {
        case 'DI_MUKA':
            return 'Di muka';
        case 'TANGGAL':
            return `Tanggal ${formatDate(termin.scheduled_date)}`;
        case 'MILESTONE':
            return `Milestone: ${termin.milestone_name ?? termin.milestone?.name ?? '—'}`;
        case 'PROYEK_SELESAI':
            return 'Proyek selesai';
        default:
            // Pre-Sprint-12 manual termin — always a Saturday (PRD §6.4).
            return `${termin.milestone?.name ? `${termin.milestone.name} · ` : ''}Sabtu ${formatDate(termin.scheduled_date)}`;
    }
}

function FinanceTab({
    project,
    termins,
    canView,
    canViewSummary,
    canIssueInvoices,
    canMarkPaid,
    canSubmitProof,
    allocationBreakdown,
    supplierDebts,
}: {
    project: Project;
    termins: Termin[];
    canView: boolean;
    /** Allocation / supplier debts — never Marketing (Sprint 12 #30). */
    canViewSummary: boolean;
    /** Marketing — "Terbitkan Invoice" per termin (Sprint 12 #20). */
    canIssueInvoices: boolean;
    canMarkPaid: boolean;
    canSubmitProof: boolean;
    allocationBreakdown: FinanceAllocationLine[];
    supplierDebts: SupplierDebt[];
}) {
    const [invoicing, setInvoicing] = useState<Termin | null>(null);

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
            {canViewSummary && (
                <div className="grid gap-6 lg:grid-cols-2">
                    <AllocationCard project={project} lines={allocationBreakdown} />
                    <SupplierDebtCard debts={supplierDebts} />
                </div>
            )}

            <div>
                <div className="mb-3 flex items-center justify-between gap-4">
                    <h2 className="flex items-center gap-2 text-sm font-semibold text-foreground">
                        <Wallet className="size-4 text-muted-foreground" />
                        Termin
                    </h2>
                    {project.quotation_id && <p className="text-xs text-daiku-muted">Dari skema pembayaran RAB yang disetujui klien.</p>}
                </div>

                {termins.length === 0 ? (
                    <EmptyState className="rounded-xl border border-dashed border-border" title="Belum ada termin." />
                ) : (
                    <TableCard>
                        <table className="w-full min-w-[56rem] text-sm">
                            <thead className={TABLE_HEAD_CLASS}>
                                <tr>
                                    <th className="px-4 py-2.5 text-left font-semibold">Termin</th>
                                    <th className="px-4 py-2.5 text-left font-semibold">Pemicu</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Persentase</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Nominal</th>
                                    <th className="px-4 py-2.5 text-right font-semibold">Sisa</th>
                                    <th className="px-4 py-2.5 text-left font-semibold">Status</th>
                                    <th className="w-52 px-4 py-2.5" />
                                </tr>
                            </thead>
                            <tbody>
                                {termins.map((termin) => (
                                    <tr key={termin.id} className="border-t border-border transition-colors hover:bg-daiku-gray/60">
                                        <td className="px-4 py-3 font-medium">
                                            #{termin.termin_number}
                                            {termin.quotation_id && project.quotation_id && termin.quotation_id !== project.quotation_id && (
                                                <span className="ml-1.5 text-xs font-normal text-daiku-muted">Tambahan</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-daiku-muted">{terminTrigger(termin)}</td>
                                        <td className="px-4 py-3 text-right text-daiku-muted">{Number(termin.percentage).toLocaleString('id-ID')}%</td>
                                        <td className="px-4 py-3 text-right font-medium text-daiku-dark">{formatRupiah(termin.amount)}</td>
                                        <td className="px-4 py-3 text-right font-medium">{formatRupiah(termin.sisa_piutang)}</td>
                                        <td className="px-4 py-3">
                                            <div className="flex flex-wrap gap-1">
                                                <StatusChip status={termin.status} />
                                                {Number(termin.dp_amount) + Number(termin.pelunasan) > 0 && Number(termin.sisa_piutang) > 0 && (
                                                    <StatusChip status="PARTIAL" label="Dibayar Sebagian" />
                                                )}
                                            </div>
                                            {termin.invoice && (
                                                <p className="mt-1 text-xs text-daiku-muted">
                                                    {termin.invoice.number} · <StatusChip status={termin.invoice.status} className="align-middle" />
                                                </p>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <div className="flex flex-wrap items-center justify-end gap-2">
                                                {termin.invoice && (
                                                    <InvoiceProofButton
                                                        invoice={{ ...termin.invoice, amount: termin.invoice.amount ?? termin.amount }}
                                                        canSubmit={canSubmitProof}
                                                    />
                                                )}
                                                {termin.invoice_id ? (
                                                    <Button variant="outline" size="icon-sm" asChild>
                                                        <a href={route('finance.invoices.pdf', { invoice: termin.invoice_id })} target="_blank" rel="noopener noreferrer" aria-label="PDF invoice">
                                                            <FileDown className="size-4" />
                                                        </a>
                                                    </Button>
                                                ) : (
                                                    <Button variant="outline" size="icon-sm" asChild>
                                                        <a href={route('finance.termins.pdf', { termin: termin.id })} target="_blank" rel="noopener noreferrer" aria-label="PDF termin">
                                                            <FileDown className="size-4" />
                                                        </a>
                                                    </Button>
                                                )}
                                                {canIssueInvoices && !termin.invoice_id && termin.status !== 'PAID' && (
                                                    <Button size="sm" onClick={() => setInvoicing(termin)}>
                                                        <Receipt className="size-4" />
                                                        Terbitkan Invoice
                                                    </Button>
                                                )}
                                                {canMarkPaid && !termin.invoice_id && termin.status !== 'PAID' && termin.bank_account_id !== null && (
                                                    <Button variant="outline" size="sm" onClick={() => onMarkPaid(termin)}>
                                                        Konfirmasi Pembayaran
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

            {invoicing && (
                <IssueInvoiceDialog
                    open
                    onOpenChange={(open) => !open && setInvoicing(null)}
                    action={route('finance.termins.invoices.store', { termin: invoicing.id })}
                    label={`Termin ${invoicing.termin_number}`}
                    amount={invoicing.sisa_piutang}
                />
            )}
        </div>
    );
}

/** Sprint 12 #21 — "Finance menerima RAB Fix + invoice final": the approved RAB and every invoice of the deal. */
function DocumentsTab({
    project,
    documents,
    canRequestAddendum,
    canSubmitProof,
}: {
    project: Project;
    documents: ProjectDocuments | null;
    canRequestAddendum: boolean;
    canSubmitProof: boolean;
}) {
    const [requestOpen, setRequestOpen] = useState(false);

    if (!documents) {
        return <EmptyState className="rounded-xl border border-dashed border-border" title="Anda tidak punya akses ke dokumen proyek ini." />;
    }

    const { quotation, invoices, addenda } = documents;
    const approvedAddenda = addenda.filter((addendum) => addendum.status === 'CLIENT_APPROVED');
    const addendaTotal = approvedAddenda.reduce((sum, addendum) => sum + Number(addendum.total_amount), 0);

    return (
        <div className="grid gap-6 lg:grid-cols-2">
            <SectionCard
                title="RAB Tambahan"
                icon={FilePlus2}
                description="Pekerjaan tambah setelah deal — alurnya sama: Estimator → PM → CEO → link klien."
                className="lg:col-span-2"
                flush
                action={
                    canRequestAddendum ? (
                        <Button size="sm" variant="outline" onClick={() => setRequestOpen(true)}>
                            <Plus className="size-4" />
                            Minta RAB Tambahan
                        </Button>
                    ) : undefined
                }
                footer={
                    quotation ? (
                        <p className="text-sm">
                            Nilai kontrak: RAB Fix {formatRupiah(quotation.total_amount)} + Tambahan disetujui {formatRupiah(addendaTotal)} ={' '}
                            <span className="font-semibold">{formatRupiah(Number(quotation.total_amount) + addendaTotal)}</span>
                        </p>
                    ) : undefined
                }
            >
                {addenda.length === 0 ? (
                    <p className="px-4 py-3 text-sm text-daiku-muted sm:px-5">Belum ada RAB Tambahan.</p>
                ) : (
                    <ul className="divide-y divide-border">
                        {addenda.map((addendum, index) => (
                            <li key={addendum.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm sm:px-5">
                                <span className="min-w-0">
                                    <Link
                                        href={route('quotations.show', { quotation: addendum.id })}
                                        className="font-medium text-daiku-dark underline decoration-daiku-yellow underline-offset-2"
                                    >
                                        Tambahan #{index + 1}
                                    </Link>{' '}
                                    <span className="text-daiku-muted">
                                        · {Number(addendum.total_amount) > 0 ? formatRupiah(addendum.total_amount) : 'belum dihitung'}
                                        {addendum.request_note ? ` · ${addendum.request_note}` : ''}
                                    </span>
                                </span>
                                <StatusChip status={addendum.status} />
                            </li>
                        ))}
                    </ul>
                )}
            </SectionCard>
            {canRequestAddendum && (
                <RequestAddendumDialog open={requestOpen} onOpenChange={setRequestOpen} projectId={project.id} projectName={project.name} />
            )}
            <SectionCard title="RAB Fix" icon={FileText} description="Versi RAB Proyek yang disetujui klien.">
                {quotation ? (
                    <div className="space-y-3 text-sm">
                        <p>
                            <span className="font-medium text-daiku-dark">QUO-{String(quotation.id).padStart(5, '0')}</span> versi {quotation.version} ·{' '}
                            {formatRupiah(quotation.total_amount)}
                        </p>
                        {quotation.client_approved_at && (
                            <p className="text-daiku-muted">Disetujui klien {formatDateTime(quotation.client_approved_at)}.</p>
                        )}
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" size="sm" asChild>
                                <a href={route('quotations.pdf', { quotation: quotation.id })} target="_blank" rel="noopener noreferrer">
                                    <FileDown className="size-4" />
                                    Unduh PDF
                                </a>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <a href={route('quotations.excel', { quotation: quotation.id })}>
                                    <FileDown className="size-4" />
                                    Unduh Excel
                                </a>
                            </Button>
                        </div>
                    </div>
                ) : (
                    <p className="text-sm text-daiku-muted">RAB Fix belum tertaut — proyek ini dibuat sebelum Buka Proyek dari RAB.</p>
                )}
            </SectionCard>

            <SectionCard title="Invoice" icon={Receipt} description="Semua invoice klien ini — jasa, DP, dan termin." flush>
                {invoices.length === 0 ? (
                    <EmptyState title="Belum ada invoice." />
                ) : (
                    <ul className="divide-y divide-border">
                        {invoices.map((invoice) => (
                            <li key={invoice.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm sm:px-5">
                                <span>
                                    <span className="font-medium text-daiku-dark">{invoice.number}</span>{' '}
                                    <span className="text-daiku-muted">
                                        {INVOICE_TYPE_LABEL[invoice.type]} · {formatRupiah(invoice.amount)}
                                    </span>
                                </span>
                                <span className="flex flex-wrap items-center gap-2">
                                    <StatusChip status={invoice.status} />
                                    <InvoiceProofButton invoice={invoice} canSubmit={canSubmitProof} />
                                    <a
                                        href={route('finance.invoices.pdf', { invoice: invoice.id })}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="text-xs font-medium underline decoration-daiku-yellow underline-offset-4"
                                    >
                                        Unduh PDF
                                    </a>
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </SectionCard>
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
    budget: 'Alokasi Dana',
    documents: 'Dokumen',
    overview: 'Overview',
    milestone: 'Milestone',
    qa: 'QA',
    task: 'Task',
    overtime: 'Lembur',
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
    assistantPms,
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
    qaForms,
    canViewQa,
    canOpenQaForm,
    overtimeRequests,
    canViewOvertime,
    canOpenOvertimeList,
    termins,
    canViewTermins,
    canViewFinanceSummary,
    canIssueTerminInvoices,
    canMarkTerminPaid,
    canSubmitInvoiceProof,
    documents,
    budget,
    canManageBudget,
    canRequestAddendum,
    canDecideOverrun,
    budgetVendors,
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
    // Sprint 13 #3 — the tab lives in `?tab=` (links from Perlu Tindakan
    // and the cross-project lists); a tab this role doesn't get falls back
    // to the overview.
    const [tab, setTab] = useQueryTab(
        {
            milestone: true,
            qa: canViewQa,
            task: canViewTasks,
            overtime: canViewOvertime,
            progress: true,
            finance: true,
            budget: Boolean(budget),
            documents: Boolean(documents),
            material: canViewMaterials,
        },
        'overview',
    );
    const waiting = useMemo(() => {
        const rows: WaitingRow[] = [];
        const qaPending = qaForms.filter((form) => form.status === 'PENDING').length;
        const qaRejected = qaForms.filter((form) => form.status === 'REJECTED').length;
        const overtimeWaiting = overtimeRequests.filter((request) => request.status === 'PENDING' || request.status === 'PENDING_FINANCE').length;
        const requestsWaiting = projectMaterials.filter((line) => line.request_status === 'MENUNGGU_PM' || line.request_status === 'DIAJUKAN').length;
        const nextTermin = termins.find((termin) => termin.status !== 'PAID');

        if (qaRejected > 0) {
            rows.push({ key: 'qa-rejected', label: `${qaRejected} QA`, detail: 'Milestone ditolak QA — perbaiki lalu tandai selesai lagi', tab: 'qa', tone: 'error' });
        }
        if (qaPending > 0) {
            rows.push({ key: 'qa-pending', label: `${qaPending} QA`, detail: 'Milestone menunggu pemeriksaan QA', tab: 'qa', tone: 'warning' });
        }
        if (overtimeWaiting > 0) {
            rows.push({ key: 'overtime', label: `${overtimeWaiting} lembur`, detail: 'Pengajuan lembur menunggu keputusan PM / Finance', tab: 'overtime', tone: 'warning' });
        }
        if (requestsWaiting > 0) {
            rows.push({ key: 'material', label: `${requestsWaiting} barang`, detail: 'Pengajuan barang menunggu PM / Logistik', tab: 'material', tone: 'warning' });
        }
        if (nextTermin) {
            rows.push({
                key: 'termin',
                label: `Termin ${nextTermin.termin_number}`,
                detail: `Termin berikutnya ${formatRupiah(nextTermin.sisa_piutang ?? nextTermin.amount)} · ${terminTrigger(nextTermin)}${nextTermin.status === 'OVERDUE' ? ' · lewat jadwal' : ''}`,
                tab: 'finance',
                tone: nextTermin.status === 'OVERDUE' ? 'error' : 'info',
            });
        }

        return rows;
    }, [qaForms, overtimeRequests, projectMaterials, termins]);
    const [editOpen, setEditOpen] = useState(false);
    const isClosed = project.status === 'COMPLETED' || project.status === 'CANCELLED';
    // Sprint 20 Sub 04 — a finished project can become a portfolio draft
    // (settings.portfolio.* is role:CEO|MARKETING; SUPERADMIN passes every gate).
    const heldRoles = usePage<PageProps>().props.auth.user.roles ?? [];
    const canMakePortfolio =
        project.status === 'COMPLETED' && heldRoles.some((role) => role === 'CEO' || role === 'MARKETING' || role === 'SUPERADMIN');
    const [makingPortfolio, setMakingPortfolio] = useState(false);

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
                        {canMakePortfolio && (
                            <Button
                                variant="outline"
                                disabled={makingPortfolio}
                                title="Buat draf portofolio situs dari judul, jenis dan kota proyek ini — tanpa nama klien, alamat atau nilai proyek"
                                onClick={() =>
                                    router.post(
                                        route('settings.portfolio.fromProject', { project: project.id }),
                                        {},
                                        { onStart: () => setMakingPortfolio(true), onFinish: () => setMakingPortfolio(false) },
                                    )
                                }
                            >
                                <GalleryHorizontalEnd className="size-4" />
                                Jadikan Portofolio
                            </Button>
                        )}
                        {canEditProject && (
                            <Button variant="outline" onClick={() => setEditOpen(true)}>
                                <PenLine className="size-4" />
                                Ubah Proyek
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
                    assistantPms={assistantPms}
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
                    {canViewQa && (
                        <TabsTrigger value="qa">
                            <ShieldCheck />
                            QA
                        </TabsTrigger>
                    )}
                    {canViewTasks && (
                        <TabsTrigger value="task">
                            <ListChecks />
                            Task
                        </TabsTrigger>
                    )}
                    {canViewOvertime && (
                        <TabsTrigger value="overtime">
                            <Clock />
                            Lembur
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
                    {budget && (
                        <TabsTrigger value="budget">
                            <Layers />
                            Alokasi Dana
                        </TabsTrigger>
                    )}
                    {documents && (
                        <TabsTrigger value="documents">
                            <FileText />
                            Dokumen
                        </TabsTrigger>
                    )}
                    {canViewMaterials && (
                        <TabsTrigger value="material">
                            <Package />
                            Material
                        </TabsTrigger>
                    )}
                </UnderlineTabsList>
                <TabsContent value="overview" className="mt-6">
                    <OverviewTab project={project} progressLogs={progressLogs} waiting={waiting} onOpenTab={setTab} />
                </TabsContent>
                <TabsContent value="milestone" className="mt-6">
                    <MilestoneTab
                        project={project}
                        milestones={milestones}
                        canView={canViewMilestones}
                        canManage={canManageMilestones && !isClosed}
                    />
                </TabsContent>
                {canViewQa && (
                    <TabsContent value="qa" className="mt-6">
                        <ProjectQaTab qaForms={qaForms} canOpenForm={canOpenQaForm} />
                    </TabsContent>
                )}
                {canViewOvertime && (
                    <TabsContent value="overtime" className="mt-6">
                        <ProjectOvertimeTab requests={overtimeRequests} canOpenList={canOpenOvertimeList} />
                    </TabsContent>
                )}
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
                        termins={termins}
                        canView={canViewTermins}
                        canViewSummary={canViewFinanceSummary}
                        canIssueInvoices={canIssueTerminInvoices}
                        canMarkPaid={canMarkTerminPaid}
                        canSubmitProof={canSubmitInvoiceProof}
                        allocationBreakdown={allocationBreakdown}
                        supplierDebts={supplierDebts}
                    />
                </TabsContent>
                {budget && (
                    <TabsContent value="budget" className="mt-6">
                        <BudgetAllocationTab
                            projectId={project.id}
                            budget={budget}
                            canManage={canManageBudget}
                            canDecideOverrun={canDecideOverrun}
                            vendors={budgetVendors}
                        />
                    </TabsContent>
                )}
                {documents && (
                    <TabsContent value="documents" className="mt-6">
                        <DocumentsTab project={project} documents={documents} canRequestAddendum={canRequestAddendum} canSubmitProof={canSubmitInvoiceProof} />
                    </TabsContent>
                )}
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
