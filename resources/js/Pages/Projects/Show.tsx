import { formatDateTime, formatRupiah } from '@/lib/format';
import { projectVisit, RELOAD } from '@/lib/projectReload';
import { TASK_PRIORITY_LABEL } from '@/lib/taskLabels';
import { projectWaitingOn } from '@/lib/waitingOn';
import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import { EmptyState } from '@/Components/shared/EmptyState';
import { Notice } from '@/Components/shared/Notice';
import { ProgressBar } from '@/Components/shared/ProgressBar';
import { SectionCard } from '@/Components/shared/SectionCard';
import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { UnderlineTabsList } from '@/Components/shared/UnderlineTabsList';
import { MoreActions } from '@/Components/shared/MoreActions';
import { ConfirmDialog } from '@/Components/shared/ConfirmDialog';
import { Button } from '@/Components/ui/button';
import { DropdownMenuItem } from '@/Components/ui/dropdown-menu';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { BudgetAllocationTab } from '@/Components/modules/projects/BudgetAllocationTab';
import { RequestAddendumDialog } from '@/Components/modules/projects/RequestAddendumDialog';
import { MilestoneCalendar } from '@/Components/modules/projects/MilestoneCalendar';
import { MilestoneFormDialog, suggestTimelineStart } from '@/Components/modules/projects/MilestoneFormDialog';
import { MilestoneGanttCalendar } from '@/Components/modules/projects/MilestoneGanttCalendar';
import { MilestoneList } from '@/Components/modules/projects/MilestoneList';
import { ProgressLogFormDialog } from '@/Components/modules/projects/ProgressLogFormDialog';
import { ProgressTimeline } from '@/Components/modules/projects/ProgressTimeline';
import { ActivityTargetPanel } from '@/Components/modules/projects/ActivityTargetPanel';
import { TaskTargetTable } from '@/Components/modules/projects/TaskTargetTable';
import { ProjectFormDialog } from '@/Components/modules/projects/ProjectFormDialog';
import { ProjectPhaseStepper } from '@/Components/modules/projects/ProjectPhaseStepper';
import { DepartureButton } from '@/Components/modules/projects/DepartureButton';
import { ProjectOverviewTab } from '@/Components/modules/projects/ProjectOverviewTab';
import { terminTrigger, terminTriggerReached } from '@/Components/modules/projects/projectWaiting';
import { TerminStatusChip } from '@/Components/modules/finance/TerminPaymentDialog';
import { TaskDeleteDialog } from '@/Components/modules/projects/TaskDeleteDialog';
import { TaskFormDialog } from '@/Components/modules/projects/TaskFormDialog';
import { TaskKanbanBoard } from '@/Components/modules/projects/TaskKanbanBoard';
import { TaskWeekBoard } from '@/Components/modules/projects/TaskWeekBoard';
import { TaskRowMenu } from '@/Components/modules/projects/TaskRowMenu';
import { RescheduleTaskDialog } from '@/Components/modules/projects/RescheduleTaskDialog';
import { TaskStatusDialog } from '@/Components/modules/projects/TaskStatusDialog';
import { INVOICE_TYPE_LABEL, InvoicePdfButton, InvoiceProofButton, IssueInvoiceDialog } from '@/Components/modules/finance/InvoiceDialogs';
import { type MaterialPermissions, ProjectMaterialsPanel } from '@/Components/modules/projects/ProjectMaterialsPanel';
import { ProjectOvertimeTab } from '@/Components/modules/projects/ProjectOvertimeTab';
import { ProjectQaTab } from '@/Components/modules/projects/ProjectQaTab';
import { WorkItemQaPanel } from '@/Components/modules/projects/WorkItemQaPanel';
import { WorkItemWageCard } from '@/Components/modules/projects/WorkItemWageCard';
import { WageSplitPanel } from '@/Components/modules/projects/WageSplitPanel';
import { WorkStepsPanel } from '@/Components/modules/projects/WorkStepsPanel';
import { WorkProgressSheet } from '@/Components/modules/projects/WorkProgressSheet';

// Sprint 24 — the execution flow tab. Imported statically: a lazy() chunk shared with this page made Vite drop the page's manifest entry.
import { ProjectExecutionTab } from '@/Components/modules/projects/ProjectExecutionTab';
import { CompleteMeetingButton } from '@/Components/modules/projects/TechnicalMeetingCard';
import { useLastDefined } from '@/hooks/useLastDefined';
import { BELOW_MD, useMediaQuery } from '@/hooks/useMediaQuery';
import { resolveView, type TabAliases, useQueryTab } from '@/hooks/useQueryTab';
import AppLayout from '@/Layouts/AppLayout';
import type {
    ActivityTarget,
    FinanceAllocationLine,
    ItemQaOverview,
    Invoice,
    Material,
    MaterialCategory,
    Milestone,
    OvertimeRequest,
    ProgressLog,
    Project,
    ProjectBudget,
    ProjectExecution,
    ProjectMaterial,
    ProjectStatusNote,
    ProjectTeamOptions,
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
    ChevronDown,
    CircleCheck,
    FileDown,
    Flag,
    FolderKanban,
    LayoutDashboard,
    ListChecks,
    NotebookPen,
    Package,
    PenLine,
    PieChart,
    Plus,
    Receipt,
    FileText,
    Wallet,
    FilePlus2,
    Route,
    GalleryHorizontalEnd,
    Rows3,
} from 'lucide-react';
import { type ReactNode, useMemo, useState } from 'react';

interface ProjectShowProps {
    project: Project;
    /** Sprint 24 — ProjectExecutionService::overview() (null for roles without the tab). */
    execution: ProjectExecution | null;
    /** ProjectPolicy::update() and not COMPLETED/CANCELLED. */
    canEditProject: boolean;
    /** CEO only — PRD §4.4 "PM di-assign oleh CEO". */
    canChangePm: boolean;
    projectManagers: Pick<User, 'id' | 'name'>[];
    /** Sprint 12 D2 — Asisten PM choices for Edit Proyek. */
    assistantPms: Pick<User, 'id' | 'name'>[];
    /** Sprint 23 K15 — Kepala Tim / Pengawas choices for Edit Proyek (null without it). */
    teamOptions: ProjectTeamOptions | null;
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
    /** Sprint 25 — tab "Activity Target" (null when the tab is not readable). */
    activityTarget: ActivityTarget | null;
    /** Sprint 13 #3 — Tab QA: the timelines' QA forms (CEO/PM/Asisten PM/QA). */
    qaForms: QaForm[];
    canViewQa: boolean;
    /** Sprint 29 Sub 12 — QA per item RAB (WorkItemQaService::overview()); null = QA per timeline or no QA view. */
    itemQa: ItemQaOverview | null;
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
    // materialOptions / catalogOptions — Sprint 29 Sub 01: `Inertia::optional()`, read through useProjectCatalog().
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

/** Sprint 26 H3b — one small sub-tab of a page tab (`?view=`). */
interface ViewOption {
    key: string;
    label: string;
}

/** The sub-tab bar (only when there is more than one) around the current view's content. */
function ViewTabs({ views, view, onView, children }: { views: ViewOption[]; view: string; onView: (view: string) => void; children: ReactNode }) {
    return (
        <Tabs value={view} onValueChange={onView}>
            {views.length > 1 && (
                <TabsList className="max-w-full justify-start overflow-x-auto">
                    {views.map((option) => (
                        <TabsTrigger key={option.key} value={option.key}>
                            {option.label}
                        </TabsTrigger>
                    ))}
                </TabsList>
            )}
            <TabsContent value={view} className={views.length > 1 ? 'mt-4' : undefined}>
                {children}
            </TabsContent>
        </Tabs>
    );
}

/**
 * Sprint 26 H3e — tab "Timeline & QC": Gantt · Kalender · QC Timeline (Sprint 27 P2b).
 * Sprint 29 Sub 12 (G3) — with QA per item RAB the QC view is "QC Item" and
 * "Tandai Selesai" closes a timeline without a QA form.
 */
function MilestoneTab({
    project,
    milestones,
    canView,
    canManage,
    qaForms,
    canOpenQaForm,
    itemQa,
    fieldStaff,
    views,
    view,
    onView,
}: {
    project: Project;
    milestones: Milestone[];
    canView: boolean;
    canManage: boolean;
    qaForms: QaForm[];
    canOpenQaForm: boolean;
    itemQa: ItemQaOverview | null;
    fieldStaff: Pick<User, 'id' | 'name' | 'is_active'>[];
    views: ViewOption[];
    view: string;
    onView: (view: string) => void;
}) {
    const [dialogOpen, setDialogOpen] = useState(false);
    const [editing, setEditing] = useState<Milestone | null>(null);
    const [confirming, setConfirming] = useState<{ kind: 'delete' | 'done'; milestone: Milestone } | null>(null);
    const shownConfirm = useLastDefined(confirming);
    const [confirmBusy, setConfirmBusy] = useState(false);
    // Sprint 13 P2 — Gantt/calendar need width; a phone gets the plain list.
    const compact = useMediaQuery(BELOW_MD);
    const suggestedStart = useMemo(() => suggestTimelineStart(milestones, project.start_date), [milestones, project.start_date]);
    // The latest QC check per timeline — a rejected one shows "Perlu Perbaikan" beside the timeline.
    const qcRejected = useMemo(() => {
        const latest = new Map<number, QaForm>();
        for (const form of qaForms) {
            const seen = latest.get(form.milestone_id);
            if (!seen || form.created_at > seen.created_at) latest.set(form.milestone_id, form);
        }

        return new Set([...latest.values()].filter((form) => form.status === 'REJECTED').map((form) => form.milestone_id));
    }, [qaForms]);

    if (!canView) {
        return (
            <EmptyState className="rounded-xl border border-dashed border-border" title="Anda tidak punya akses untuk melihat timeline proyek ini." />
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

    // Sprint 26 H8a — the Daiku ConfirmDialog instead of the native browser prompt.
    function runConfirmed() {
        if (!confirming) return;

        // Sprint 29 Sub 01 — a confirmed step: keeps the success pop-up, reloads only the timeline side.
        const options = {
            ...projectVisit(RELOAD.milestones),
            onStart: () => setConfirmBusy(true),
            onFinish: () => {
                setConfirmBusy(false);
                setConfirming(null);
            },
        };

        if (confirming.kind === 'delete') {
            router.delete(route('milestones.destroy', { milestone: confirming.milestone.id }), options);
        } else {
            router.post(route('milestones.markDone', { milestone: confirming.milestone.id }), {}, options);
        }
    }

    const onDelete = (milestone: Milestone) => setConfirming({ kind: 'delete', milestone });
    const onMarkDone = (milestone: Milestone) => setConfirming({ kind: 'done', milestone });

    const timelineProps = { milestones, canManage, onEdit: openEdit, onDelete, onMarkDone, qcRejected };

    return (
        <div>
            <ViewTabs views={views} view={view} onView={onView}>
                {view === 'qc' ? (
                    itemQa ? (
                        <WorkItemQaPanel projectId={project.id} itemQa={itemQa} fieldStaff={fieldStaff} />
                    ) : (
                        <ProjectQaTab qaForms={qaForms} canOpenForm={canOpenQaForm} />
                    )
                ) : (
                    <>
                        {canManage && (
                            <div className="mb-4 flex justify-end">
                                <Button size="sm" onClick={openCreate}>
                                    <Plus className="size-4" />
                                    Tambah Timeline
                                </Button>
                            </div>
                        )}

                        {milestones.length === 0 ? (
                            <EmptyState className="rounded-xl border border-dashed border-border" title="Belum ada timeline." />
                        ) : compact ? (
                            // Sprint 13 P2 — Gantt/calendar need width; a phone gets the plain list.
                            <MilestoneList {...timelineProps} />
                        ) : view === 'kalender' ? (
                            <MilestoneCalendar {...timelineProps} />
                        ) : (
                            <MilestoneGanttCalendar {...timelineProps} />
                        )}
                    </>
                )}
            </ViewTabs>

            {canManage && (
                <MilestoneFormDialog
                    open={dialogOpen}
                    onOpenChange={setDialogOpen}
                    projectId={project.id}
                    editing={editing}
                    suggestedStart={suggestedStart}
                />
            )}

            <ConfirmDialog
                open={confirming !== null}
                onOpenChange={(open) => !open && setConfirming(null)}
                busy={confirmBusy}
                onConfirm={runConfirmed}
                {...(shownConfirm?.kind === 'delete'
                    ? {
                          tone: 'danger' as const,
                          title: 'Hapus timeline?',
                          description: `"${shownConfirm.milestone.name}" dihapus dari proyek ini.`,
                          confirmLabel: 'Ya, hapus',
                      }
                    : {
                          icon: CircleCheck,
                          title: 'Tandai timeline selesai?',
                          description: itemQa
                              ? `"${shownConfirm?.milestone.name ?? ''}" ditandai selesai. Pemeriksaan QA dilakukan per item RAB di tab Item RAB › QC Item.`
                              : `"${shownConfirm?.milestone.name ?? ''}" — Pengawas (QA/QC) proyek akan diminta memeriksanya.`,
                          confirmLabel: 'Ya, tandai selesai',
                      })}
            />
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
    onRescheduleClick,
}: {
    assigneeName: string;
    tasks: Task[];
    canManage: boolean;
    /** Edit/delete — PM on a project that isn't COMPLETED/CANCELLED. */
    canEdit: boolean;
    onStatusClick: (task: Task) => void;
    onEditClick: (task: Task) => void;
    onDeleteClick: (task: Task) => void;
    onRescheduleClick: (task: Task) => void;
}) {
    const usesWorkItems = usePage<PageProps<{ usesWorkItems?: boolean }>>().props.usesWorkItems ?? false;
    const doneCount = tasks.filter((t) => t.status === 'DONE').length;

    // Sprint 26 H8b — the status chip itself opens "Ubah Status" (was a separate button).
    function statusOf(task: Task) {
        const chip = <StatusChip domain="task" status={task.status} />;

        return (
            <div className="flex flex-wrap gap-1">
                {canManage ? (
                    <button
                        type="button"
                        onClick={() => onStatusClick(task)}
                        aria-label={`Ubah status ${task.title}`}
                        title="Ubah status"
                        className="inline-flex items-center gap-0.5 rounded-md transition-opacity hover:opacity-80 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        {chip}
                        <ChevronDown className="size-3.5 text-muted-foreground" aria-hidden />
                    </button>
                ) : (
                    chip
                )}
                {task.status === 'DONE' && task.is_wage_paid && <StatusChip status="PAID" label="Upah dibayar" />}
                {/* Sprint 27 P1b — the DP condition "Upah" points here; an empty rate shows on its row. */}
                {canEdit && !usesWorkItems && !(Number(task.rate_per_task) > 0) && <StatusChip status="NO_RATE" tone="warning" label="Upah belum diisi" />}
            </div>
        );
    }

    return (
        <TableCard>
            <div className="flex items-center justify-between gap-4 border-b border-border px-4 py-3">
                <p className="flex min-w-0 items-center gap-2.5 text-sm font-semibold text-foreground">
                    <span className="flex size-7 shrink-0 items-center justify-center rounded-full bg-daiku-yellow-light text-xs font-semibold text-daiku-yellow-dark">
                        {assigneeName.slice(0, 1).toUpperCase()}
                    </span>
                    <span className="truncate">{assigneeName}</span>
                </p>
                <div className="flex w-32 shrink-0 items-center gap-2 sm:w-40">
                    <ProgressBar
                        value={tasks.length ? Math.round((doneCount / tasks.length) * 100) : 0}
                        label={`Daily target selesai ${assigneeName}`}
                        tone="success"
                        className="flex-1"
                    />
                    <p className="shrink-0 text-xs text-daiku-muted tabular-nums">
                        {doneCount}/{tasks.length} selesai
                    </p>
                </div>
            </div>
            {/* Sprint 26 H8c — one card per daily target below md, the table above. */}
            <ul className="divide-y divide-border md:hidden">
                {tasks.map((task) => (
                    <li key={task.id} className="flex items-start gap-3 px-4 py-3 text-sm">
                        <div className="min-w-0 flex-1 space-y-1.5">
                            <p className="font-medium text-foreground">{task.title}</p>
                            <p className="text-xs text-daiku-muted">
                                {task.milestone?.name ?? '—'} · Prioritas {TASK_PRIORITY_LABEL[task.priority].toLowerCase()} · jatuh tempo {formatDate(task.due_date)}
                            </p>
                            {statusOf(task)}
                        </div>
                        {canEdit && (
                            <TaskRowMenu task={task} onEdit={onEditClick} onDelete={onDeleteClick} onReschedule={onRescheduleClick} />
                        )}
                    </li>
                ))}
            </ul>
            <table className="hidden w-full text-sm md:table">
                <thead className={TABLE_HEAD_CLASS}>
                    <tr>
                        <th className="px-4 py-2.5 text-left font-semibold">Judul</th>
                        <th className="px-4 py-2.5 text-left font-semibold">Timeline</th>
                        <th className="px-4 py-2.5 text-left font-semibold">Status</th>
                        <th className="px-4 py-2.5 text-left font-semibold">Prioritas</th>
                        <th className="px-4 py-2.5 text-left font-semibold">Jatuh Tempo</th>
                        <th className="w-12 px-4 py-2.5" />
                    </tr>
                </thead>
                <tbody>
                    {tasks.map((task) => (
                        <tr key={task.id} className="border-t border-border transition-colors hover:bg-daiku-gray/60">
                            <td className="px-4 py-3 font-medium">{task.title}</td>
                            <td className="px-4 py-3 text-daiku-muted">{task.milestone?.name ?? '—'}</td>
                            <td className="px-4 py-3">{statusOf(task)}</td>
                            <td className="px-4 py-3 text-daiku-muted">{TASK_PRIORITY_LABEL[task.priority]}</td>
                            <td className="px-4 py-3 text-daiku-muted">{formatDate(task.due_date)}</td>
                            <td className="px-4 py-3">
                                {canEdit && (
                                    <div className="flex justify-end">
                                        <TaskRowMenu task={task} onEdit={onEditClick} onDelete={onDeleteClick} onReschedule={onRescheduleClick} />
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
 * Sprint 26 H3f — tab "Daily Target": the targets (Per Hari · Per Tukang ·
 * Board), the project-wide Activity Target, and the overtime requests.
 *
 * "update agar terpisah pisah tabelnya per orangan" — Per Tukang is one
 * table per assignee instead of a single flat table, so a PM reviewing a
 * specific tukang's workload doesn't have to scan/filter a mixed list.
 * Unassigned tasks (assignee_id null) get their own bucket.
 */
function TaskTab({
    project,
    milestones,
    tasks,
    canManage,
    isClosed,
    fieldStaff,
    views,
    view,
    onView,
    activityTarget,
    progressLogs,
    canViewProgressLogs,
    canManageProgressLogs,
    overtimeRequests,
    canOpenOvertimeList,
    canViewTasks,
}: {
    project: Project;
    milestones: Milestone[];
    tasks: Task[];
    canManage: boolean;
    /** COMPLETED/CANCELLED — the task plan is final (TaskService refuses create/edit/delete). */
    isClosed: boolean;
    fieldStaff: Pick<User, 'id' | 'name' | 'is_active'>[];
    views: ViewOption[];
    view: string;
    onView: (view: string) => void;
    activityTarget: ActivityTarget | null;
    progressLogs: ProgressLog[];
    canViewProgressLogs: boolean;
    canManageProgressLogs: boolean;
    overtimeRequests: OvertimeRequest[];
    canOpenOvertimeList: boolean;
    canViewTasks: boolean;
}) {
    // Sprint 29 Sub 08 (B8) — wages per RAB item: the target form has no "Upah".
    const usesWorkItems = usePage<PageProps<{ usesWorkItems?: boolean }>>().props.usesWorkItems ?? false;
    const [formOpen, setFormOpen] = useState(false);
    const [editingTask, setEditingTask] = useState<Task | null>(null);
    const [statusOpen, setStatusOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [activeTask, setActiveTask] = useState<Task | null>(null);
    // Sprint 24 D9 — "Geser Tanggal" inside the timeline.
    const [reschedulingTask, setReschedulingTask] = useState<Task | null>(null);
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

    const isTargetView = view === 'hari' || view === 'tukang' || view === 'board';

    return (
        <div>
            <ViewTabs views={views} view={view} onView={onView}>
                {view === 'activity-target' ? (
                    <ActivityTargetTab
                        project={project}
                        activityTarget={activityTarget}
                        progressLogs={progressLogs}
                        canView={canViewProgressLogs}
                        canManage={canManageProgressLogs}
                        tasks={canViewTasks ? tasks : null}
                    />
                ) : view === 'lembur' ? (
                    <ProjectOvertimeTab requests={overtimeRequests} canOpenList={canOpenOvertimeList} />
                ) : (
                    <>
                        {canEdit && (
                            <div className="mb-4 flex justify-end">
                                <Button size="sm" onClick={openCreate}>
                                    <Plus className="size-4" />
                                    Tambah Daily Target
                                </Button>
                            </div>
                        )}

                        {groups.length === 0 ? (
                            <EmptyState className="rounded-xl border border-dashed border-border" title="Belum ada daily target — dibuat Pengawas setelah TM." />
                        ) : view === 'tukang' ? (
                            <div className="space-y-4">
                                {groups.map((group) => (
                                    <TaskAssigneeTable
                                        key={group.name}
                                        assigneeName={group.name}
                                        tasks={group.tasks}
                                        canManage={canManage}
                                        canEdit={canEdit}
                                        onRescheduleClick={setReschedulingTask}
                                        onStatusClick={openStatus}
                                        onEditClick={openEdit}
                                        onDeleteClick={openDelete}
                                    />
                                ))}
                            </div>
                        ) : view === 'board' ? (
                            <TaskKanbanBoard tasks={tasks} milestones={milestones} onCardClick={canManage ? openStatus : undefined} />
                        ) : (
                            // Sprint 24 D9 — drag a daily target to another day (inside its timeline).
                            <TaskWeekBoard tasks={tasks} milestones={milestones} canMove={canEdit} canReview={canEdit} reload={RELOAD.tasks} />
                        )}
                    </>
                )}
            </ViewTabs>

            {canEdit && isTargetView && (
                <>
                    <TaskFormDialog
                        open={formOpen}
                        onOpenChange={setFormOpen}
                        projectId={project.id}
                        editing={editingTask}
                        milestones={milestones}
                        fieldStaff={fieldStaff}
                        reload={RELOAD.tasks}
                        hideWage={usesWorkItems}
                    />
                    <TaskDeleteDialog open={deleteOpen} onOpenChange={setDeleteOpen} task={activeTask} reload={RELOAD.tasks} />
                    <RescheduleTaskDialog task={reschedulingTask} onOpenChange={(open) => !open && setReschedulingTask(null)} reload={RELOAD.tasks} />
                </>
            )}
            {isTargetView && <TaskStatusDialog open={statusOpen} onOpenChange={setStatusOpen} task={activeTask} reload={RELOAD.tasks} />}
        </div>
    );
}

/**
 * Sprint 25 — tab "Activity Target" (key `progress`): daily target vs
 * actual for the whole project, then the PM's Catatan Progress below.
 */
function ActivityTargetTab({
    project,
    activityTarget,
    progressLogs,
    canView,
    canManage,
    tasks,
}: {
    project: Project;
    activityTarget: ActivityTarget | null;
    progressLogs: ProgressLog[];
    canView: boolean;
    canManage: boolean;
    /** Sprint 27 P5 — the per-task table; null for viewers who don't see the project's tasks (PRD §4.6). */
    tasks: Task[] | null;
}) {
    const [dialogOpen, setDialogOpen] = useState(false);

    if (!canView) {
        return (
            <EmptyState className="rounded-xl border border-dashed border-border" title="Anda tidak punya akses untuk melihat activity target proyek ini." />
        );
    }

    return (
        <div className="space-y-6">
            {activityTarget && <ActivityTargetPanel data={activityTarget} />}
            {tasks && <TaskTargetTable tasks={tasks} />}

            {/* Sprint 24 D11 — notes only; the percentage is the project progress above (Sprint 29 Sub 10: progres berbobot or actual activity). */}
            <SectionCard
                title="Catatan Progress"
                description="Laporan naratif PM — persentase progress dihitung otomatis dari daily target yang selesai."
                icon={NotebookPen}
                action={
                    canManage && (
                        <Button size="sm" onClick={() => setDialogOpen(true)}>
                            <Plus className="size-4" />
                            Tambah Catatan
                        </Button>
                    )
                }
            >
                <ProgressTimeline logs={progressLogs} />
            </SectionCard>

            {canManage && (
                <ProgressLogFormDialog open={dialogOpen} onOpenChange={setDialogOpen} projectId={project.id} />
            )}
        </div>
    );
}

/** Sprint 26 H3g — Keuangan › Termin & Invoice (allocation and supplier debts are their own views). */
function FinanceTab({
    project,
    termins,
    canView,
    canIssueInvoices,
    canMarkPaid,
    canSubmitProof,
}: {
    project: Project;
    termins: Termin[];
    canView: boolean;
    /** Marketing — "Terbitkan Invoice" per termin (Sprint 12 #20). */
    canIssueInvoices: boolean;
    canMarkPaid: boolean;
    canSubmitProof: boolean;
}) {
    const [invoicing, setInvoicing] = useState<Termin | null>(null);
    // Sprint 26 H8a — the Daiku ConfirmDialog instead of the native browser prompt.
    const [markingPaid, setMarkingPaid] = useState<Termin | null>(null);
    const [markPaidBusy, setMarkPaidBusy] = useState(false);

    if (!canView) {
        return (
            <EmptyState className="rounded-xl border border-dashed border-border" title="Anda tidak punya akses untuk melihat termin proyek ini." />
        );
    }

    function markPaid() {
        if (!markingPaid) return;

        router.post(
            route('finance.termins.markPaid', { termin: markingPaid.id }),
            {},
            {
                // A payment decision — keeps the pop-up; reloads termins / invoices only.
                ...projectVisit(RELOAD.billing),
                onStart: () => setMarkPaidBusy(true),
                onFinish: () => {
                    setMarkPaidBusy(false);
                    setMarkingPaid(null);
                },
            },
        );
    }

    return (
        <div className="space-y-6">
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
                                            {termin.payment_label ?? `#${termin.termin_number}`}
                                            {termin.quotation_id && project.quotation_id && termin.quotation_id !== project.quotation_id && (
                                                <span className="ml-1.5 text-xs font-normal text-daiku-muted">Tambahan</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-daiku-muted">{terminTrigger(termin)}</td>
                                        <td className="px-4 py-3 text-right text-daiku-muted">{Number(termin.percentage).toLocaleString('id-ID')}%</td>
                                        <td className="px-4 py-3 text-right font-medium text-daiku-dark">{formatRupiah(termin.amount)}</td>
                                        <td className="px-4 py-3 text-right font-medium">{formatRupiah(termin.sisa_piutang)}</td>
                                        <td className="px-4 py-3">
                                            {/* Sprint 28 K6 — invoiced: the invoice's own state and holder, same chip as Finance › Termin. */}
                                            <TerminStatusChip termin={termin} />
                                            {termin.invoice && <p className="mt-1 text-xs text-daiku-muted">{termin.invoice.number}</p>}
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
                                                    <Button variant="outline" size="sm" onClick={() => setMarkingPaid(termin)}>
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
                    label={invoicing.payment_label ?? `Termin ${invoicing.termin_number}`}
                    amount={invoicing.sisa_piutang}
                    note={
                        terminTriggerReached(invoicing) === false
                            ? `Pemicu belum tercapai (${terminTrigger(invoicing)}). Invoice tetap bisa diterbitkan lebih awal.`
                            : undefined
                    }
                />
            )}

            <ConfirmDialog
                open={markingPaid !== null}
                onOpenChange={(open) => !open && setMarkingPaid(null)}
                icon={Wallet}
                title={`Tandai termin #${markingPaid?.termin_number ?? ''} sudah dibayar?`}
                description={markingPaid ? `${formatRupiah(markingPaid.sisa_piutang ?? markingPaid.amount)} dicatat sebagai pemasukan proyek ini.` : ''}
                confirmLabel="Ya, sudah dibayar"
                busy={markPaidBusy}
                onConfirm={markPaid}
            />
        </div>
    );
}

/** Sprint 12 #21 — "Finance menerima RAB Fix + invoice final": the approved RAB and every invoice of the deal. */
function DocumentsTab({ documents, canSubmitProof }: { documents: ProjectDocuments | null; canSubmitProof: boolean }) {
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
                description="Pekerjaan tambah setelah deal — alurnya sama: Estimator → PM → Marketing → link klien."
                className="lg:col-span-2"
                flush
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
                                <StatusChip domain="quotation" status={addendum.status} />
                            </li>
                        ))}
                    </ul>
                )}
            </SectionCard>
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
                                    <StatusChip domain="invoice" status={invoice.status} />
                                    <InvoicePdfButton invoiceId={invoice.id} />
                                    <InvoiceProofButton invoice={invoice} canSubmit={canSubmitProof} />
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
            title="Pos Anggaran Standar"
            icon={PieChart}
            description={`Persentase baku dari nilai kontrak ${formatRupiah(project.contract_value)} — acuan untuk Alokasi Dana.`}
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

/** Top-level tab → breadcrumb label (Timeline gets "& QC" only for viewers of the QC view). */
const PROJECT_TAB_LABEL: Record<string, string> = {
    overview: 'Ringkasan',
    execution: 'Eksekusi',
    items: 'Item RAB',
    milestone: 'Timeline',
    task: 'Daily Target',
    material: 'Material',
    finance: 'Keuangan',
};

/**
 * Sprint 26 H3c — the 11 tabs before Sprint 26 are 6 now; their old keys
 * (Perlu Tindakan, notifications, cross-project lists, bookmarks) still
 * open the same content: `?tab=qa` → Timeline & QC › QC Timeline.
 */
const PROJECT_TAB_ALIASES: TabAliases = {
    qa: ['milestone', 'qc'],
    progress: ['task', 'activity-target'],
    overtime: ['task', 'lembur'],
    budget: ['finance', 'alokasi'],
    documents: ['finance', 'dokumen'],
};

/** Where a link inside the page points: a tab, optionally a view in it and a card id. */
interface TabTarget {
    tab: string;
    view?: string;
    anchor?: string;
}

const SUPPLIER_DEBT_LABEL: Record<NonNullable<SupplierDebt['status']>, string> = {
    BERJALAN: 'Berjalan',
    JATUH_TEMPO: 'Jatuh Tempo',
    LUNAS: 'Lunas',
};

/**
 * Sprint 23 K4b — the one line under the title: what happens next on this
 * project and whose turn it is. Nothing once it's closed.
 */
interface ProjectStep {
    text: string;
    /** Sprint 26 H1e — where this step is done; a tab this viewer doesn't get is dropped. */
    target?: TabTarget;
}

function projectStep(project: Project, milestones: Milestone[], qaForms: QaForm[], itemQa: ItemQaOverview | null): ProjectStep | undefined {
    if (project.status === 'ON_HOLD') {
        return { text: 'Proyek ditahan — PM mengaktifkannya kembali lewat Ubah Proyek.' };
    }

    if (project.status !== 'ACTIVE') {
        return undefined;
    }

    if (!project.team_lead_id && !project.team_lead_name) {
        return { text: 'Menunggu PM melengkapi Kepala Tim proyek ini lewat Ubah Proyek.' };
    }

    // Sprint 24 D1 — before production the phase says what comes next.
    switch (project.phase) {
        case 'GAMBAR_KERJA':
            return { text: 'Menunggu tim desain menyelesaikan gambar kerja.', target: { tab: 'execution', anchor: 'gambar-kerja' } };
        case 'TM':
            return { text: 'Menunggu PM menjadwalkan Technical Meeting, menyusun timeline & tanggal selesai, lalu menekan TM Selesai.', target: { tab: 'execution', anchor: 'tm' } };
        case 'PERSIAPAN':
            return { text: 'Menunggu Pengawas melengkapi bahan, upah & daily target, lalu mengajukan DP upah tukang ke PM.', target: { tab: 'execution', anchor: 'syarat-dp' } };
    }

    if (milestones.length === 0) {
        return { text: 'Menunggu PM menyusun timeline proyek (tanggal mulai & selesai tiap tahap).', target: { tab: 'milestone' } };
    }

    // Sprint 29 Sub 12 — QA per item RAB: repairs first, then items waiting for the Pengawas.
    if (itemQa && itemQa.summary.perbaikan > 0) {
        return { text: `${itemQa.summary.perbaikan} item RAB perlu perbaikan — menunggu tukang memperbaiki, lalu Pengawas memeriksa ulang.`, target: { tab: 'items', view: 'qc' } };
    }

    if (itemQa && itemQa.summary.menunggu > 0) {
        return { text: `${itemQa.summary.menunggu} item RAB sudah 100 % — menunggu Pengawas memeriksa (QA).`, target: { tab: 'items', view: 'qc' } };
    }

    const rejected = qaForms.filter((form) => form.status === 'REJECTED').length;

    if (rejected > 0) {
        return { text: `${rejected} timeline ditolak Pengawas — menunggu PM memperbaiki lalu menandai selesai lagi.`, target: { tab: 'milestone', view: 'qc' } };
    }

    const waitingQa = milestones.filter((milestone) => milestone.status === 'QA_WAITING').length;
    const next = [...milestones]
        .sort((a, b) => a.order - b.order)
        .find((milestone) => milestone.status !== 'COMPLETED' && milestone.status !== 'QA_WAITING');

    if (next) {
        return {
            text: `Timeline berjalan: ${next.name} — selesai ${formatDate(next.target_date)}${next.status === 'OVERDUE' ? ' (terlambat)' : ''}.`,
            target: { tab: 'milestone' },
        };
    }

    return waitingQa > 0 ? { text: `${waitingQa} timeline menunggu Pengawas memeriksa.`, target: { tab: 'milestone', view: 'qc' } } : undefined;
}

/** Scrolls to a card once the tab that holds it has rendered. */
function scrollToAnchor(anchor?: string) {
    if (!anchor) return;

    requestAnimationFrame(() =>
        requestAnimationFrame(() => document.getElementById(anchor)?.scrollIntoView({ behavior: 'smooth', block: 'start' })),
    );
}

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
                    Proyek selesai — semua timeline lolos QA. Data proyek tidak bisa diubah lagi.
                </Notice>
            );
        default:
            return null;
    }
}

export default function ProjectShow({
    project,
    execution,
    canEditProject,
    canChangePm,
    projectManagers,
    assistantPms,
    teamOptions,
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
    activityTarget,
    qaForms,
    canViewQa,
    itemQa,
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
    vendors,
    units,
    materialCategories,
}: ProjectShowProps) {
    // Sprint 26 H3 — six tabs; Timeline, Daily Target and Keuangan hold
    // small views (`?view=`). A view is listed only when its data reached
    // this viewer (the controller filters the props — nothing new is sent).
    const compact = useMediaQuery(BELOW_MD);
    const producingPhase = execution?.phase === 'PRODUKSI' || execution?.phase === 'PEMASANGAN';
    const tabViews: Record<string, ViewOption[]> = {
        milestone: [
            { key: 'timeline', label: compact ? 'Timeline' : 'Gantt' },
            ...(compact ? [] : [{ key: 'kalender', label: 'Kalender' }]),
            ...(canViewQa && !itemQa ? [{ key: 'qc', label: 'QC Timeline' }] : []),
        ],
        // Sprint 31 Sub 07 — everything per RAB item in one tab: the Excel-like sheet, the wages, the QA.
        items: [
            // Sprint 33 — before Produksi the wages are filled first (Langkah Kerja order), then the schedule.
            ...(execution?.workItems && !producingPhase ? [{ key: 'upah', label: 'Upah Tukang' }] : []),
            ...(execution && (execution.workSchedule || execution.progressRecap || execution.workCheck) ? [{ key: 'progres', label: 'Lembar Progres' }] : []),
            ...(execution?.workItems && producingPhase ? [{ key: 'upah', label: 'Upah Tukang' }] : []),
            // Sprint 32 — Pembagian Upah per Orang (File D).
            ...(execution?.wageSplit ? [{ key: 'pembagian', label: 'Pembagian Upah' }] : []),
            ...(itemQa ? [{ key: 'qc', label: 'QC Item' }] : []),
        ],
        task: [
            ...(canViewTasks
                ? [
                      { key: 'hari', label: 'Per Hari' },
                      { key: 'tukang', label: 'Per Tukang' },
                      { key: 'board', label: 'Board' },
                  ]
                : []),
            ...(canViewProgressLogs ? [{ key: 'activity-target', label: 'Activity Target' }] : []),
            ...(canViewOvertime ? [{ key: 'lembur', label: 'Lembur' }] : []),
        ],
        finance: [
            ...(canViewTermins ? [{ key: 'termin', label: 'Termin & Invoice' }] : []),
            ...(budget || canViewFinanceSummary ? [{ key: 'alokasi', label: 'Anggaran' }] : []),
            ...(canViewFinanceSummary ? [{ key: 'hutang', label: 'Hutang Supplier' }] : []),
            ...(documents ? [{ key: 'dokumen', label: 'Dokumen' }] : []),
        ],
    };
    // Sprint 13 #3 — the tab lives in `?tab=` (links from Perlu Tindakan
    // and the cross-project lists); a tab this role doesn't get falls back
    // to the overview.
    const availableTabs: Record<string, boolean> = {
        overview: true,
        execution: Boolean(execution),
        items: Boolean(execution?.usesWorkItems) && tabViews.items.length > 0,
        milestone: true,
        task: tabViews.task.length > 0,
        material: canViewMaterials,
        finance: tabViews.finance.length > 0,
    };
    const [tab, setTab, requestedView, setView] = useQueryTab(availableTabs, 'overview', PROJECT_TAB_ALIASES);
    const views = tabViews[tab] ?? [];
    const view = resolveView(requestedView, views.map((option) => option.key));
    const viewLabel = views.length > 1 && view !== views[0].key ? views.find((option) => option.key === view)?.label : undefined;
    const showsTimelineQc = canViewQa && !itemQa;
    const tabLabel = tab === 'milestone' && showsTimelineQc ? 'Timeline & QC' : PROJECT_TAB_LABEL[tab];

    /** Opens a tab (an old key is resolved through the aliases), then scrolls to its card. */
    function openTarget(target: TabTarget) {
        const alias = PROJECT_TAB_ALIASES[target.tab];
        const next = alias ? alias[0] : target.tab;

        if (!availableTabs[next]) return;

        setTab(next, target.view ?? alias?.[1]);
        scrollToAnchor(target.anchor);
    }

    const canOpenTarget = (target: TabTarget) => Boolean(availableTabs[PROJECT_TAB_ALIASES[target.tab]?.[0] ?? target.tab]);
    const [editOpen, setEditOpen] = useState(false);
    const isClosed = project.status === 'COMPLETED' || project.status === 'CANCELLED';
    // Sprint 20 Sub 04 — a finished project can become a portfolio draft
    // (settings.portfolio.* is role:CEO|MARKETING; SUPERADMIN passes every gate).
    const authUser = usePage<PageProps>().props.auth.user;
    const heldRoles = authUser.roles ?? [];
    const canMakePortfolio =
        project.status === 'COMPLETED' && heldRoles.some((role) => role === 'CEO' || role === 'MARKETING' || role === 'SUPERADMIN');
    const [makingPortfolio, setMakingPortfolio] = useState(false);
    const [requestingAddendum, setRequestingAddendum] = useState(false);
    // Sprint 33 — a running project's next step is the Langkah Kerja panel; the header line stays for the rest.
    const workSteps = execution?.steps ?? null;
    const step = workSteps ? undefined : projectStep(project, milestones, qaForms, itemQa);
    const stepTarget = step?.target && canOpenTarget(step.target) ? step.target : undefined;

    return (
        <AppLayout
            breadcrumbs={[{ label: project.name }, { label: tabLabel }, ...(viewLabel ? [{ label: viewLabel }] : [])]}
        >
            <Head title={project.name} />

            <PageHeader
                title={project.name}
                icon={FolderKanban}
                description={project.lead?.client_name ? `Klien: ${project.lead.client_name}` : undefined}
                step={
                    step &&
                    (stepTarget ? (
                        <button
                            type="button"
                            onClick={() => openTarget(stepTarget)}
                            className="text-left underline decoration-daiku-yellow decoration-2 underline-offset-2 hover:decoration-daiku-yellow-dark"
                        >
                            {step.text}
                        </button>
                    ) : (
                        step.text
                    ))
                }
                actions={
                    <>
                        {/* Sprint 26 H1d — the phase lives in ProjectPhaseStepper below; Sprint 28 K14 — the chip names that phase's holder. */}
                        <StatusChip domain="project" status={project.status} waitingOn={projectWaitingOn(project)} />
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
                        {/* Sprint 24 — the TM's closing step, for the project's PM / Asisten PM. */}
                        {execution?.can.completeMeeting && (
                            <CompleteMeetingButton projectId={project.id} requirements={execution.meetingRequirements} warnUnsaved />
                        )}
                        {/* Sprint 26 H4c — once actual activity reaches the threshold (also on the Eksekusi card). */}
                        {execution && execution.progress >= execution.threshold && <DepartureButton project={project} execution={execution} />}
                        {/* Sprint 23 K4b — was on the Dokumen tab's RAB Tambahan card. */}
                        {canRequestAddendum && (
                            <MoreActions>
                                <DropdownMenuItem onSelect={() => setRequestingAddendum(true)}>
                                    <FilePlus2 className="size-4" />
                                    Minta RAB Tambahan
                                </DropdownMenuItem>
                            </MoreActions>
                        )}
                    </>
                }
            />

            <ProjectPhaseStepper project={project} execution={execution} />

            {workSteps && (
                <WorkStepsPanel
                    steps={workSteps}
                    onOpen={openTarget}
                    canOpen={canOpenTarget}
                    actions={
                        execution?.can.completeMeeting
                            ? { completeMeeting: <CompleteMeetingButton projectId={project.id} requirements={execution.meetingRequirements} warnUnsaved /> }
                            : undefined
                    }
                />
            )}

            {canRequestAddendum && (
                <RequestAddendumDialog
                    open={requestingAddendum}
                    onOpenChange={setRequestingAddendum}
                    projectId={project.id}
                    projectName={project.name}
                />
            )}

            <ProjectStatusNotice project={project} statusNote={statusNote} />

            {canEditProject && teamOptions && (
                <ProjectFormDialog
                    open={editOpen}
                    onOpenChange={setEditOpen}
                    project={project}
                    canChangePm={canChangePm}
                    projectManagers={projectManagers}
                    assistantPms={assistantPms}
                    hasTerminPayments={hasTerminPayments}
                    teamOptions={teamOptions}
                />
            )}

            <Tabs value={tab} onValueChange={(next) => setTab(next)}>
                <UnderlineTabsList>
                    <TabsTrigger value="overview">
                        <LayoutDashboard />
                        Ringkasan
                    </TabsTrigger>
                    {execution && (
                        <TabsTrigger value="execution">
                            <Route />
                            Eksekusi
                        </TabsTrigger>
                    )}
                    {availableTabs.items && (
                        <TabsTrigger value="items">
                            <Rows3 />
                            Item RAB
                        </TabsTrigger>
                    )}
                    <TabsTrigger value="milestone">
                        <Flag />
                        {showsTimelineQc ? 'Timeline & QC' : 'Timeline'}
                    </TabsTrigger>
                    {availableTabs.task && (
                        <TabsTrigger value="task">
                            <ListChecks />
                            Daily Target
                        </TabsTrigger>
                    )}
                    {canViewMaterials && (
                        <TabsTrigger value="material">
                            <Package />
                            Material
                        </TabsTrigger>
                    )}
                    {availableTabs.finance && (
                        <TabsTrigger value="finance">
                            <Wallet />
                            Keuangan
                        </TabsTrigger>
                    )}
                </UnderlineTabsList>
                <TabsContent value="overview" className="mt-6">
                    <ProjectOverviewTab
                        project={project}
                        execution={execution}
                        activityTarget={activityTarget}
                        progressLogs={progressLogs}
                        qaForms={qaForms}
                        overtimeRequests={overtimeRequests}
                        projectMaterials={projectMaterials}
                        termins={termins}
                        canManageMilestones={canManageMilestones}
                        canIssueTerminInvoices={canIssueTerminInvoices}
                        canMarkTerminPaid={canMarkTerminPaid}
                        canOpenQaForm={canOpenQaForm}
                        materialPermissions={materialPermissions}
                        currentUserId={authUser.id}
                        onOpen={openTarget}
                        canOpen={canOpenTarget}
                    />
                </TabsContent>
                {execution && (
                    <TabsContent value="execution" className="mt-6">
                        <ProjectExecutionTab
                            project={project}
                            execution={execution}
                            milestones={milestones}
                            fieldStaff={fieldStaff}
                            canManageMilestones={canManageMilestones}
                            onOpenActivityTarget={activityTarget ? () => openTarget({ tab: 'task', view: 'activity-target' }) : undefined}
                            onOpen={openTarget}
                            canOpen={canOpenTarget}
                            projectMaterials={canViewMaterials ? projectMaterials : null}
                        />
                    </TabsContent>
                )}
                {availableTabs.items && execution && (
                    <TabsContent value="items" className="mt-6">
                        <ViewTabs views={tabViews.items} view={tab === 'items' ? view : tabViews.items[0].key} onView={setView}>
                            {(tab === 'items' ? view : tabViews.items[0].key) === 'upah' ? (
                                <WorkItemWageCard projectId={project.id} execution={execution} fieldStaff={fieldStaff} />
                            ) : (tab === 'items' ? view : '') === 'pembagian' && execution.wageSplit ? (
                                <WageSplitPanel projectId={project.id} projectName={project.name} execution={execution} />
                            ) : (tab === 'items' ? view : '') === 'qc' && itemQa ? (
                                <WorkItemQaPanel projectId={project.id} itemQa={itemQa} fieldStaff={fieldStaff} />
                            ) : (
                                <WorkProgressSheet projectId={project.id} execution={execution} />
                            )}
                        </ViewTabs>
                    </TabsContent>
                )}
                <TabsContent value="milestone" className="mt-6">
                    <MilestoneTab
                        project={project}
                        milestones={milestones}
                        canView={canViewMilestones}
                        canManage={canManageMilestones && !isClosed}
                        qaForms={canViewQa ? qaForms : []}
                        canOpenQaForm={canOpenQaForm}
                        itemQa={itemQa}
                        fieldStaff={fieldStaff}
                        views={tabViews.milestone}
                        view={tab === 'milestone' ? view : 'timeline'}
                        onView={setView}
                    />
                </TabsContent>
                {availableTabs.task && (
                    <TabsContent value="task" className="mt-6">
                        <TaskTab
                            project={project}
                            milestones={milestones}
                            tasks={tasks}
                            canManage={canManageTasks}
                            isClosed={isClosed}
                            fieldStaff={fieldStaff}
                            views={tabViews.task}
                            view={tab === 'task' ? view : tabViews.task[0].key}
                            onView={setView}
                            activityTarget={activityTarget}
                            progressLogs={progressLogs}
                            canViewProgressLogs={canViewProgressLogs}
                            canManageProgressLogs={canManageProgressLogs}
                            overtimeRequests={overtimeRequests}
                            canOpenOvertimeList={canOpenOvertimeList}
                            canViewTasks={canViewTasks}
                        />
                    </TabsContent>
                )}
                {canViewMaterials && (
                    <TabsContent value="material" className="mt-6">
                        <ProjectMaterialsPanel
                            project={project}
                            items={projectMaterials}
                            canView={canViewMaterials}
                            permissions={materialPermissions}
                            vendors={vendors}
                            units={units}
                            materialCategories={materialCategories}
                        />
                    </TabsContent>
                )}
                {availableTabs.finance && (
                    <TabsContent value="finance" className="mt-6">
                        <ViewTabs views={tabViews.finance} view={tab === 'finance' ? view : tabViews.finance[0].key} onView={setView}>
                            {view === 'alokasi' ? (
                                <div className="space-y-6">
                                    {canViewFinanceSummary && <AllocationCard project={project} lines={allocationBreakdown} />}
                                    {budget && (
                                        <BudgetAllocationTab
                                            projectId={project.id}
                                            budget={budget}
                                            canManage={canManageBudget}
                                            canDecideOverrun={canDecideOverrun}
                                            vendors={budgetVendors}
                                        />
                                    )}
                                </div>
                            ) : view === 'hutang' ? (
                                <SupplierDebtCard debts={supplierDebts} />
                            ) : view === 'dokumen' ? (
                                <DocumentsTab documents={documents} canSubmitProof={canSubmitInvoiceProof} />
                            ) : (
                                <FinanceTab
                                    project={project}
                                    termins={termins}
                                    canView={canViewTermins}
                                    canIssueInvoices={canIssueTerminInvoices}
                                    canMarkPaid={canMarkTerminPaid}
                                    canSubmitProof={canSubmitInvoiceProof}
                                />
                            )}
                        </ViewTabs>
                    </TabsContent>
                )}
            </Tabs>
        </AppLayout>
    );
}
