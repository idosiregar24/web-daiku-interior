import { DepartureButton } from '@/Components/modules/projects/DepartureButton';
import type { MaterialPermissions } from '@/Components/modules/projects/ProjectMaterialsPanel';
import { type ProjectTabTarget, projectWaiting, type WaitingRow } from '@/Components/modules/projects/projectWaiting';
import { DecidePaymentButton } from '@/Components/modules/projects/SupervisorFeeCard';
import { CompleteMeetingButton } from '@/Components/modules/projects/TechnicalMeetingCard';
import { EmptyState } from '@/Components/shared/EmptyState';
import { ProgressBar } from '@/Components/shared/ProgressBar';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatCard } from '@/Components/shared/StatCard';
import { Button } from '@/Components/ui/button';
import { formatDate, formatRupiah } from '@/lib/format';
import { formatProgress, PROGRESS_BASIS_HINT, PROGRESS_BASIS_LABEL } from '@/lib/progress';
import { cn } from '@/lib/utils';
import type {
    ActivityTarget,
    OvertimeRequest,
    ProgressLog,
    Project,
    ProjectExecution,
    ProjectMaterial,
    QaForm,
    Termin,
} from '@/types';
import { differenceInCalendarDays, parseISO } from 'date-fns';
import { Activity, ArrowRight, BellRing, CalendarClock, ChevronRight, Hourglass, NotebookPen, Target, Users, Wallet } from 'lucide-react';
import { useMemo } from 'react';

export type { ProjectTabTarget } from '@/Components/modules/projects/projectWaiting';

export interface ProjectOverviewTabProps {
    project: Project;
    execution: ProjectExecution | null;
    activityTarget: ActivityTarget | null;
    /** Newest first — the latest Catatan Progress stays under the progress. */
    progressLogs: ProgressLog[];
    qaForms: QaForm[];
    overtimeRequests: OvertimeRequest[];
    projectMaterials: ProjectMaterial[];
    termins: Termin[];
    canManageMilestones: boolean;
    canIssueTerminInvoices: boolean;
    canMarkTerminPaid: boolean;
    canOpenQaForm: boolean;
    materialPermissions: MaterialPermissions;
    currentUserId: number;
    /** Switch to a tab (and sub-tab), then scroll to `anchor`. */
    onOpen: (target: ProjectTabTarget) => void;
    /** Whether this viewer gets that tab/sub-tab — rows elsewhere are shown without a link. */
    canOpen: (target: ProjectTabTarget) => boolean;
}

const TONE_CLASS: Record<WaitingRow['tone'], string> = {
    warning: 'bg-warning/10 text-warning-ink',
    error: 'bg-error/10 text-error-ink',
    info: 'bg-info/10 text-info-ink',
};

function RowChip({ row, muted = false }: { row: WaitingRow; muted?: boolean }) {
    return (
        <span
            className={cn(
                'shrink-0 rounded-md px-1.5 py-0.5 text-xs font-semibold tabular-nums',
                muted ? 'bg-daiku-gray text-daiku-muted' : TONE_CLASS[row.tone],
            )}
        >
            {row.label}
        </span>
    );
}

/** H6d — the viewer's turn: label + sentence + the one-click action, else "Buka". */
function MyTurnCard({
    rows,
    project,
    execution,
    onOpen,
    canOpen,
}: {
    rows: WaitingRow[];
    project: Project;
    execution: ProjectExecution | null;
    onOpen: (target: ProjectTabTarget) => void;
    canOpen: (target: ProjectTabTarget) => boolean;
}) {
    function actionOf(row: WaitingRow) {
        if (row.action === 'completeMeeting' && execution) {
            return <CompleteMeetingButton projectId={project.id} requirements={execution.meetingRequirements} className="w-full sm:w-auto" />;
        }

        if (row.action === 'decidePayment' && execution) {
            return <DecidePaymentButton execution={execution} size="sm" className="w-full sm:w-auto" />;
        }

        if (row.action === 'approveDeparture' && execution) {
            return <DepartureButton project={project} execution={execution} size="sm" className="w-full sm:w-auto" />;
        }

        if (!canOpen(row.target)) {
            return null;
        }

        return (
            <Button variant="outline" size="sm" className="w-full sm:w-auto" onClick={() => onOpen(row.target)}>
                Kerjakan
                <ArrowRight className="size-3.5" />
            </Button>
        );
    }

    return (
        <SectionCard
            title={
                <span className="flex items-center gap-2">
                    Tugas Anda
                    <span className="rounded-md bg-daiku-yellow-light px-1.5 py-0.5 text-[11px] font-semibold text-daiku-yellow-dark tabular-nums">
                        {rows.length}
                    </span>
                </span>
            }
            description="Langkah di proyek ini yang menunggu Anda."
            icon={BellRing}
            flush
            className="lg:col-span-3"
        >
            <ul className="divide-y divide-border">
                {rows.map((row) => (
                    <li key={row.key} className="flex flex-col gap-2.5 px-4 py-3 sm:flex-row sm:items-center sm:gap-3 sm:px-5">
                        <span className="flex min-w-0 flex-1 items-start gap-3 sm:items-center">
                            <RowChip row={row} />
                            <span className="min-w-0 text-sm text-foreground">{row.detail}</span>
                        </span>
                        {actionOf(row)}
                    </li>
                ))}
            </ul>
        </SectionCard>
    );
}

/** H6e — rows that wait on someone else, muted; each detail is a sentence naming whom it waits on (Sprint 34). */
function OthersCard({
    rows,
    onOpen,
    canOpen,
}: {
    rows: WaitingRow[];
    onOpen: (target: ProjectTabTarget) => void;
    canOpen: (target: ProjectTabTarget) => boolean;
}) {
    return (
        <SectionCard
            title="Menunggu Orang Lain"
            description="Hal yang belum selesai di proyek ini dan siapa yang sedang ditunggu."
            icon={Hourglass}
            flush
        >
            {rows.length === 0 ? (
                <EmptyState title="Tidak ada yang menunggu." />
            ) : (
                <ul className="divide-y divide-border">
                    {rows.map((row) => {
                        const content = (
                            <>
                                <RowChip row={row} muted />
                                <span className="min-w-0 flex-1 text-sm text-muted-foreground">
                                    {row.detail}
                                </span>
                            </>
                        );

                        return (
                            <li key={row.key}>
                                {canOpen(row.target) ? (
                                    <button
                                        type="button"
                                        onClick={() => onOpen(row.target)}
                                        className="flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-daiku-yellow-light/60 sm:items-center sm:px-5"
                                    >
                                        {content}
                                        <ChevronRight className="size-4 shrink-0 text-muted-foreground/60" />
                                    </button>
                                ) : (
                                    <div className="flex items-start gap-3 px-4 py-3 sm:items-center sm:px-5">{content}</div>
                                )}
                            </li>
                        );
                    })}
                </ul>
            )}
        </SectionCard>
    );
}

/** "1 Okt" — the year only when it isn't this year. */
function shortDate(value: string): string {
    const date = parseISO(value);
    const sameYear = date.getFullYear() === new Date().getFullYear();

    return date.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', ...(sameYear ? {} : { year: 'numeric' }) });
}

const TILE_LINK_CLASS = 'font-medium text-foreground underline decoration-daiku-yellow decoration-2 underline-offset-2 hover:decoration-daiku-yellow-dark';

/**
 * The project's health at a glance — the first row of the Ringkasan (was a
 * Progress card and an Informasi Proyek card further down): progress, the gap
 * to today's target, the end date and the contract value. Numbers from the
 * server only (H4a); Sprint 29 Sub 10 — a project whose RAB items carry a
 * wage shows the progres berbobot, with the daily targets' actual activity
 * beside the target.
 */
function ProjectPulse({
    project,
    activityTarget,
    nearDeadline,
    onOpen,
    canOpen,
}: {
    project: Project;
    activityTarget: ActivityTarget | null;
    /** Sprint 27 P4a — inside the H-30% window. */
    nearDeadline: boolean;
    onOpen: (target: ProjectTabTarget) => void;
    canOpen: (target: ProjectTabTarget) => boolean;
}) {
    const summary = activityTarget?.summary;
    const basis = project.progress_basis ?? 'activity';
    const weighted = basis === 'weighted';
    const progress = weighted ? (project.actual_progress ?? 0) : (summary?.progress ?? project.actual_progress ?? 0);
    const activityLink: ProjectTabTarget = { tab: 'task', view: 'activity-target' };
    const hasContract = project.contract_value != null && project.contract_value !== '';

    const running = project.status === 'ACTIVE' || project.status === 'ON_HOLD';
    const daysLeft = project.end_date ? differenceInCalendarDays(parseISO(project.end_date), new Date()) : null;
    const dateTone = !running ? 'default' : daysLeft === null ? 'warning' : daysLeft < 0 ? 'error' : nearDeadline ? 'warning' : 'default';
    const dateNote =
        project.status === 'COMPLETED'
            ? 'Proyek selesai'
            : !running || daysLeft === null
              ? null
              : daysLeft < 0
                ? `Lewat ${-daysLeft} hari`
                : daysLeft === 0
                  ? 'Hari ini'
                  : `Sisa ${daysLeft} hari${nearDeadline ? ' · masa H-30%' : ''}`;
    const started = project.start_date ? `Mulai ${shortDate(project.start_date)}` : 'Mulai belum ditentukan';

    const tiles = 2 + (summary ? 1 : 0) + (hasContract ? 1 : 0);

    return (
        <section aria-label="Kondisi proyek" className={cn('grid grid-cols-2 gap-3 lg:col-span-3', tiles === 4 ? 'lg:grid-cols-4' : tiles === 3 ? 'lg:grid-cols-3' : '')}>
            <StatCard label={weighted ? 'Progres Berbobot' : 'Progres'} value={formatProgress(progress)} icon={Activity} hint={PROGRESS_BASIS_HINT[basis]}>
                <ProgressBar value={progress} label={`${PROGRESS_BASIS_LABEL[basis]} proyek`} />
            </StatCard>
            {summary && (
                <StatCard
                    label="Terhadap Target"
                    value={`${summary.gap > 0 ? '+' : summary.gap < 0 ? '−' : '±'}${Math.abs(summary.gap)} poin`}
                    tone={summary.gap >= 0 ? 'success' : 'error'}
                    icon={Target}
                    hint={
                        <>
                            {weighted && <span className="block">Actual activity (daily target) {summary.progress}%</span>}
                            <span className="block">Target s.d. hari ini {summary.target_to_date}%</span>
                            {canOpen(activityLink) && (
                                <button type="button" onClick={() => onOpen(activityLink)} className={cn('mt-1.5 block', TILE_LINK_CLASS)}>
                                    Lihat Activity Target →
                                </button>
                            )}
                        </>
                    }
                />
            )}
            <StatCard
                label="Tanggal Selesai"
                value={project.end_date ? shortDate(project.end_date) : 'Belum ditentukan'}
                tone={dateTone}
                icon={CalendarClock}
                hint={
                    <>
                        {dateNote && <span className={cn('block font-medium', dateTone === 'error' ? 'text-error-ink' : dateTone === 'warning' ? 'text-warning-ink' : 'text-foreground')}>{dateNote}</span>}
                        <span className="block">
                            {started}
                            {project.opened_on && ` · dibuka ${formatDate(project.opened_on)}`}
                        </span>
                    </>
                }
            />
            {/* A half-width phone tile can't hold "Rp 33.000.000" at the KPI size. */}
            {hasContract && (
                <StatCard label="Nilai Kontrak" value={<span className="text-lg sm:text-2xl">{formatRupiah(project.contract_value)}</span>} icon={Wallet} />
            )}
        </section>
    );
}

/** The PM's latest Catatan Progress (Sprint 24 D11 — notes only, never a percentage); the rest sit under Activity Target. */
function LatestNoteCard({
    log,
    onOpen,
    canOpen,
}: {
    log: ProgressLog;
    onOpen: (target: ProjectTabTarget) => void;
    canOpen: (target: ProjectTabTarget) => boolean;
}) {
    const target: ProjectTabTarget = { tab: 'task', view: 'activity-target' };

    return (
        <SectionCard
            title="Catatan Progress"
            description={`Terakhir · ${formatDate(log.log_date)}`}
            icon={NotebookPen}
            action={
                canOpen(target) && (
                    <button type="button" onClick={() => onOpen(target)} className={cn('text-sm', TILE_LINK_CLASS)}>
                        Semua catatan
                    </button>
                )
            }
        >
            <p className="text-sm text-foreground">{log.description}</p>
        </SectionCard>
    );
}

interface TeamMember {
    key: string;
    name: string;
    role: string;
}

/** H6f — the people of the project (set at Buka Proyek / Ubah Proyek, drawing team from the execution data). */
function TeamCard({ project, execution }: { project: Project; execution: ProjectExecution | null }) {
    const members: TeamMember[] = [];

    if (project.pm?.name) members.push({ key: 'pm', name: project.pm.name, role: 'Project Manager' });
    if (project.assistant_pm?.name) members.push({ key: 'apm', name: project.assistant_pm.name, role: 'Asisten PM' });
    if (project.team_lead?.name) {
        members.push({ key: 'lead', name: project.team_lead.name, role: 'Kepala Tim' });
    } else if (project.team_lead_name) {
        members.push({ key: 'lead', name: project.team_lead_name, role: 'Kepala Tim · luar perusahaan' });
    }
    project.supervisors?.forEach((person) => members.push({ key: `supervisor-${person.id}`, name: person.name, role: 'Pengawas (QA/QC)' }));

    const drawing = execution?.workingDrawing;

    if (drawing?.pic) members.push({ key: 'architect-pic', name: drawing.pic, role: 'Arsitek gambar kerja (PIC)' });
    drawing?.staff
        .filter((name) => name !== drawing.pic)
        .forEach((name, index) => members.push({ key: `architect-${index}`, name, role: 'Arsitek gambar kerja' }));

    return (
        <SectionCard title="Tim Proyek" icon={Users} flush>
            {members.length === 0 ? (
                <EmptyState title="Tim proyek belum diisi." />
            ) : (
                <ul className="divide-y divide-border">
                    {members.map((member) => (
                        <li key={member.key} className="flex items-center gap-3 px-4 py-2.5 sm:px-5">
                            <span
                                className="flex size-8 shrink-0 items-center justify-center rounded-full bg-daiku-yellow-light text-xs font-semibold text-daiku-yellow-dark"
                                aria-hidden
                            >
                                {member.name.trim().slice(0, 1).toUpperCase()}
                            </span>
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-sm font-medium text-foreground">{member.name}</span>
                                <span className="block truncate text-xs text-muted-foreground">{member.role}</span>
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </SectionCard>
    );
}

/**
 * Sprint 26 H6 — the Ringkasan tab: the project's health (progress vs
 * target, end date, contract), whose turn it is (mine first, with the
 * one-click action where one exists), the rest of the open items, the
 * latest Catatan Progress and the team. Reads only the page's own props
 * (`projectWaiting()`), never a query of its own.
 */
export function ProjectOverviewTab({
    project,
    execution,
    activityTarget,
    progressLogs,
    qaForms,
    overtimeRequests,
    projectMaterials,
    termins,
    canManageMilestones,
    canIssueTerminInvoices,
    canMarkTerminPaid,
    canOpenQaForm,
    materialPermissions,
    currentUserId,
    onOpen,
    canOpen,
}: ProjectOverviewTabProps) {
    const rows = useMemo(
        () =>
            projectWaiting({
                project,
                execution,
                qaForms,
                overtimeRequests,
                projectMaterials,
                termins,
                canManageMilestones,
                canIssueTerminInvoices,
                canMarkTerminPaid,
                canOpenQaForm,
                materialPermissions,
                currentUserId,
            }),
        [
            project,
            execution,
            qaForms,
            overtimeRequests,
            projectMaterials,
            termins,
            canManageMilestones,
            canIssueTerminInvoices,
            canMarkTerminPaid,
            canOpenQaForm,
            materialPermissions,
            currentUserId,
        ],
    );
    const mine = rows.filter((row) => row.mine);
    const others = rows.filter((row) => !row.mine);

    const latestLog = progressLogs[0];

    // Health first (a phone used to find the progress at the very bottom), then my turn, then the rest.
    return (
        <div className="grid min-w-0 gap-6 lg:grid-cols-3">
            <ProjectPulse
                project={project}
                activityTarget={activityTarget}
                nearDeadline={Boolean(execution?.deadline.warning)}
                onOpen={onOpen}
                canOpen={canOpen}
            />

            {mine.length > 0 && <MyTurnCard rows={mine} project={project} execution={execution} onOpen={onOpen} canOpen={canOpen} />}

            <div className="flex min-w-0 flex-col gap-6 lg:col-span-2">
                <OthersCard rows={others} onOpen={onOpen} canOpen={canOpen} />
            </div>

            <div className="flex min-w-0 flex-col gap-6">
                {latestLog && <LatestNoteCard log={latestLog} onOpen={onOpen} canOpen={canOpen} />}
                <TeamCard project={project} execution={execution} />
            </div>
        </div>
    );
}
