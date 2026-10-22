import { DepartureButton } from '@/Components/modules/projects/DepartureButton';
import type { MaterialPermissions } from '@/Components/modules/projects/ProjectMaterialsPanel';
import { type ProjectTabTarget, projectWaiting, type WaitingRow } from '@/Components/modules/projects/projectWaiting';
import { CompleteMeetingButton } from '@/Components/modules/projects/TechnicalMeetingCard';
import { DetailItem, DetailList } from '@/Components/shared/DetailList';
import { EmptyState } from '@/Components/shared/EmptyState';
import { ProgressBar } from '@/Components/shared/ProgressBar';
import { SectionCard } from '@/Components/shared/SectionCard';
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
import { Activity, ArrowRight, BellRing, ChevronRight, Hourglass, Info, Users } from 'lucide-react';
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

        if (row.action === 'approveDeparture' && execution) {
            return <DepartureButton project={project} execution={execution} size="sm" className="w-full sm:w-auto" />;
        }

        if (!canOpen(row.target)) {
            return null;
        }

        return (
            <Button variant="outline" size="sm" className="w-full sm:w-auto" onClick={() => onOpen(row.target)}>
                Buka
                <ArrowRight className="size-3.5" />
            </Button>
        );
    }

    return (
        <SectionCard
            title={
                <span className="flex items-center gap-2">
                    Giliran Anda
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

/** H6e — rows that are someone else's turn, muted, "· {holder}". */
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
            description="Hal yang belum selesai di proyek ini dan giliran siapa."
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
                                    {row.holder && <span className="font-medium text-foreground/80"> · {row.holder}</span>}
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

/** H6g — "Mulai 1 Okt → Selesai 30 Nov · sisa 42 hari"; Sprint 27 P4a — amber inside the H-30% window. */
function DateLine({ project, nearDeadline = false }: { project: Project; nearDeadline?: boolean }) {
    const running = project.status === 'ACTIVE' || project.status === 'ON_HOLD';
    const start = project.start_date ? `Mulai ${shortDate(project.start_date)}` : 'Mulai belum ditentukan';

    if (!project.end_date) {
        return (
            <span>
                {start} → Selesai <span className="text-muted-foreground">belum ditentukan</span>
            </span>
        );
    }

    const daysLeft = differenceInCalendarDays(parseISO(project.end_date), new Date());

    return (
        <span>
            {start} → Selesai {shortDate(project.end_date)}
            {running &&
                (daysLeft >= 0 ? (
                    <span className={nearDeadline ? 'text-warning-ink' : 'text-muted-foreground'}>
                        {' '}
                        · {daysLeft === 0 ? 'hari ini' : `sisa ${daysLeft} hari`}
                        {nearDeadline && ' · masa H-30%'}
                    </span>
                ) : (
                    <span className="text-error-ink"> · lewat {-daysLeft} hari</span>
                ))}
        </span>
    );
}

function InfoCard({ project, nearDeadline }: { project: Project; nearDeadline: boolean }) {
    const hasContract = project.contract_value != null && project.contract_value !== '';

    return (
        <SectionCard title="Informasi Proyek" icon={Info}>
            <DetailList>
                <DetailItem label="Klien" valueClassName="font-medium">
                    {project.lead?.client_name ?? '—'}
                </DetailItem>
                {hasContract && (
                    <DetailItem label="Nilai Kontrak" valueClassName="font-medium">
                        {formatRupiah(project.contract_value)}
                    </DetailItem>
                )}
                <DetailItem label="Jadwal" className="sm:col-span-2" valueClassName="font-medium">
                    <DateLine project={project} nearDeadline={nearDeadline} />
                    {project.opened_on && (
                        <span className="mt-0.5 block text-xs font-normal text-muted-foreground">
                            Proyek dibuka {formatDate(project.opened_on)}
                        </span>
                    )}
                </DetailItem>
            </DetailList>
        </SectionCard>
    );
}

/**
 * H4a — the project progress, today's target and the gap (numbers from the
 * server only). Sprint 29 Sub 10 — a project whose RAB items carry a wage
 * shows the progres berbobot (pengecekan per item RAB) as its figure and the
 * daily targets' actual activity below, labelled as such.
 */
function ProgressSummaryCard({
    project,
    activityTarget,
    progressLogs,
    onOpen,
    canOpen,
}: {
    project: Project;
    activityTarget: ActivityTarget | null;
    progressLogs: ProgressLog[];
    onOpen: (target: ProjectTabTarget) => void;
    canOpen: (target: ProjectTabTarget) => boolean;
}) {
    const summary = activityTarget?.summary;
    const basis = project.progress_basis ?? 'activity';
    const weighted = basis === 'weighted';
    const progress = weighted ? (project.actual_progress ?? 0) : (summary?.progress ?? project.actual_progress ?? 0);
    const target: ProjectTabTarget = { tab: 'task', view: 'activity-target' };
    const latest = progressLogs[0];

    return (
        <SectionCard title={weighted ? 'Progres Berbobot' : 'Progress'} description={PROGRESS_BASIS_HINT[basis]} icon={Activity}>
            <p className="text-4xl leading-none font-semibold tracking-tight text-foreground tabular-nums">{formatProgress(progress)}</p>
            <ProgressBar value={progress} label={`${PROGRESS_BASIS_LABEL[basis]} proyek`} className="mt-4" />
            {summary && (
                // On a progres-berbobot project the daily-target figures are their own block, so the gap reads as actual activity vs target.
                <div className={cn('mt-3 space-y-1 text-sm', weighted && 'rounded-lg bg-daiku-gray px-3 py-2')}>
                    {weighted && (
                        <p className="text-muted-foreground">
                            Actual activity (daily target) <span className="font-medium text-foreground tabular-nums">{summary.progress}%</span>
                        </p>
                    )}
                    <p className="text-muted-foreground">
                        Target s.d. hari ini <span className="font-medium text-foreground tabular-nums">{summary.target_to_date}%</span>
                    </p>
                    <p className={cn('font-medium tabular-nums', summary.gap >= 0 ? 'text-success-ink' : 'text-error-ink')}>
                        Selisih {summary.gap > 0 ? '+' : summary.gap < 0 ? '−' : '±'}
                        {Math.abs(summary.gap)} poin
                    </p>
                </div>
            )}
            {summary && canOpen(target) && (
                <button
                    type="button"
                    onClick={() => onOpen(target)}
                    className="mt-3 text-sm font-medium text-foreground underline decoration-daiku-yellow decoration-2 underline-offset-2 hover:decoration-daiku-yellow-dark"
                >
                    Lihat Activity Target →
                </button>
            )}
            {latest && (
                <p className="mt-3 text-xs text-daiku-muted">
                    Update terakhir: {latest.description} ({formatDate(latest.log_date)})
                </p>
            )}
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
 * Sprint 26 H6 — the Ringkasan tab: whose turn it is (mine first, with the
 * one-click action where one exists), the rest of the open items, the
 * project's dates, progress vs target and the team. Reads only the page's
 * own props (`projectWaiting()`), never a query of its own.
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

    return (
        <div className="grid min-w-0 gap-6 lg:grid-cols-3">
            {mine.length > 0 && <MyTurnCard rows={mine} project={project} execution={execution} onOpen={onOpen} canOpen={canOpen} />}

            <div className="flex min-w-0 flex-col gap-6 lg:col-span-2">
                <OthersCard rows={others} onOpen={onOpen} canOpen={canOpen} />
                <InfoCard project={project} nearDeadline={Boolean(execution?.deadline.warning)} />
            </div>

            <div className="flex min-w-0 flex-col gap-6">
                <ProgressSummaryCard
                    project={project}
                    activityTarget={activityTarget}
                    progressLogs={progressLogs}
                    onOpen={onOpen}
                    canOpen={canOpen}
                />
                <TeamCard project={project} execution={execution} />
            </div>
        </div>
    );
}
