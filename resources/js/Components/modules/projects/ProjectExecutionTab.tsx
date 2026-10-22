import { EmptyState } from '@/Components/shared/EmptyState';
import { Notice } from '@/Components/shared/Notice';
import { ProgressBar } from '@/Components/shared/ProgressBar';
import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { TABLE_HEAD_CLASS, TableCard } from '@/Components/shared/TableCard';
import { Button } from '@/Components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/Components/ui/collapsible';
import { Dialog, DialogClose, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { formatDate, formatDateTime } from '@/lib/format';
import { formatProgress, PROGRESS_BASIS_HINT, PROGRESS_BASIS_LABEL, progressPhrase } from '@/lib/progress';
import { projectVisit, RELOAD } from '@/lib/projectReload';
import { cn } from '@/lib/utils';
import { domainStatusLabel } from '@/lib/waitingOn';
import type { Milestone, Project, ProjectExecution, ProjectMaterial, ProjectPhase, User } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Link, router } from '@inertiajs/react';
import {
    Activity,
    ArrowLeftRight,
    CheckCircle2,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Circle,
    DraftingCompass,
    ExternalLink,
    History,
    Loader2,
    MapPin,
    Package,
    Pause,
    XCircle,
} from 'lucide-react';
import { type ReactNode, useEffect, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { DepartureButton } from './DepartureButton';
import { DpRequirementsCard } from './DpRequirementsCard';
import { MaterialOrdersCard } from './MaterialOrdersCard';
import { QcPanelCard } from './QcPanelCard';
import type { ProjectTabTarget } from './projectWaiting';
import { SiteFindingsCard } from './SiteFindingsCard';
import { SupervisorFeeCard } from './SupervisorFeeCard';
import { TechnicalMeetingCard } from './TechnicalMeetingCard';
import { WeeklyReportsCard } from './WeeklyReportsCard';

const DAY_LABEL = new Intl.DateTimeFormat('id-ID', { weekday: 'short', day: 'numeric' });

const PHASE_ORDER: ProjectPhase[] = ['GAMBAR_KERJA', 'TM', 'PERSIAPAN', 'PRODUKSI', 'PEMASANGAN'];

type ExecutionCard = 'workingDrawing' | 'meeting' | 'dpRequirements' | 'qc' | 'materialOrders' | 'materialPlan' | 'fee' | 'targetVsActual' | 'departure' | 'weekly' | 'findings';

/**
 * Sprint 26 H2a — the one place that says which cards a phase works with.
 * A card used by several phases (laporan mingguan, jasa pengawas) belongs
 * to the latest phase the project has reached that lists it, so it renders
 * once — with the running phase, or in that phase's history row.
 */
const PHASE_CARDS: Record<ProjectPhase, ExecutionCard[]> = {
    GAMBAR_KERJA: ['workingDrawing'],
    // Sprint 31 Sub 07 — Upah Tukang, Jadwal & Rekap per item live in the "Item RAB" tab (Lembar Progres).
    TM: ['meeting'],
    // Sprint 26 — the Pengawas plans daily targets in Persiapan, so Target vs Actual (Tukar Hari) shows from here.
    PERSIAPAN: ['dpRequirements', 'materialPlan', 'fee', 'targetVsActual'],
    // Sprint 27 P2 — the whiteboard's QC branch first, then the cards it points to.
    PRODUKSI: ['qc', 'materialOrders', 'targetVsActual', 'departure', 'weekly', 'fee'],
    PEMASANGAN: ['qc', 'findings', 'weekly', 'fee'],
};

/** H2f / Sub 03 — card ids used by the header's step link, Perlu Tindakan and notifications. */
const CARD_ANCHORS: Record<ExecutionCard, string[]> = {
    workingDrawing: ['gambar-kerja'],
    // `#tm-bahan` is rendered by the TM card itself, and only while TM is the running phase.
    meeting: ['tm'],
    dpRequirements: ['syarat-dp'],
    qc: ['qc-pengawas'],
    materialOrders: ['pemesanan-bahan'],
    materialPlan: ['tm-bahan'],
    fee: ['dp'],
    targetVsActual: ['target-vs-actual'],
    departure: ['berangkat'],
    weekly: ['laporan-mingguan'],
    findings: ['temuan'],
};

/** H2c — what the next phase is about ("Berikutnya: …"). */
function nextStepText(next: ProjectPhase | null, threshold: number): string {
    switch (next) {
        case 'TM':
            return 'PM memimpin Technical Meeting: timeline, upah tukang, tanggal selesai — Pengawas merencanakan bahan.';
        case 'PERSIAPAN':
            return 'Pengawas melengkapi bahan, upah & daily target, lalu mengajukan DP upah tukang.';
        case 'PRODUKSI':
            return 'DP upah tukang disetujui PM = Start Produksi; tukang mengerjakan daily target.';
        case 'PEMASANGAN':
            return `PM menyetujui berangkat ke lokasi setelah progres proyek mencapai ${threshold}%.`;
        default:
            return 'Proyek selesai setelah semua timeline lolos QC dan temuan lokasi ditutup.';
    }
}

function phaseLabel(phase: ProjectPhase): string {
    return domainStatusLabel('projectPhase', phase) ?? phase;
}

/** H2b — the one-line summary of a finished phase, from data the tab already has. */
function phaseDoneSummary(phase: ProjectPhase, execution: ProjectExecution, milestones: Milestone[]): string {
    const { workingDrawing: drawing, meeting, departure } = execution;

    switch (phase) {
        case 'GAMBAR_KERJA':
            if (!drawing) return 'Gambar Kerja — tidak ada (proyek dibuka sebelum alur baru)';
            return ['Gambar Kerja selesai' + (drawing.completed_at ? ` ${formatDate(drawing.completed_at)}` : ''), drawing.completer]
                .filter(Boolean)
                .join(' · ');
        case 'TM':
            if (!meeting) return 'Technical Meeting — tidak tercatat';
            return [
                'TM selesai' + (meeting.completed_at ? ` ${formatDate(meeting.completed_at)}` : ''),
                `${meeting.attendees.length} peserta`,
                `${milestones.length} timeline`,
            ].join(' · ');
        case 'PERSIAPAN': {
            const dp = execution.payments.find((payment) => payment.kind === 'DP' && (payment.status === 'DISETUJUI_PM' || payment.status === 'DIBAYAR'));
            return dp?.decided_at ? `Persiapan selesai · DP upah tukang disetujui ${formatDate(dp.decided_at)}` : 'Persiapan selesai';
        }
        case 'PRODUKSI':
            return departure
                ? ['Produksi selesai · berangkat ke lokasi disetujui ' + formatDateTime(departure.approved_at), departure.approver].filter(Boolean).join(' · ')
                : 'Produksi selesai';
        case 'PEMASANGAN':
            return `Pemasangan selesai · ${execution.findings.length} temuan lokasi`;
    }
}

/** Consumed per URL, so switching tabs back to Eksekusi doesn't jump to the hash again. */
let consumedHashHref: string | null = null;

function pendingHash(): string | null {
    if (typeof window === 'undefined') return null;
    const id = window.location.hash.slice(1);

    return id && consumedHashHref !== window.location.href ? id : null;
}

function consumeHash() {
    consumedHashHref = window.location.href;
}

function scrollToId(id: string) {
    requestAnimationFrame(() => requestAnimationFrame(() => document.getElementById(id)?.scrollIntoView({ block: 'start' })));
}

/**
 * Sprint 24 — the project's "Eksekusi" tab. Sprint 26 H2: only the running
 * phase's cards (PHASE_CARDS); finished phases fold into one line each at
 * the top (click → the card as it is), the next phase is one muted line,
 * later ones aren't rendered. A COMPLETED / ON_HOLD / CANCELLED project
 * shows every reached phase folded (history). Everything comes from
 * ProjectExecutionService::overview(); the buttons are the ones its `can`
 * allows (the routes check again).
 */
export function ProjectExecutionTab({
    project,
    execution,
    milestones,
    fieldStaff,
    canManageMilestones,
    onOpenActivityTarget,
    onOpen,
    canOpen,
    projectMaterials,
}: {
    project: Project;
    execution: ProjectExecution;
    milestones: Milestone[];
    fieldStaff: Pick<User, 'id' | 'name' | 'is_active'>[];
    canManageMilestones: boolean;
    /** Sprint 25 — opens the project-wide "Activity Target" tab (when this viewer reads it). */
    onOpenActivityTarget?: () => void;
    /** Sprint 27 — opens another tab/view of Detail Proyek ("Lengkapi →", "Buka →"). */
    onOpen?: (target: ProjectTabTarget) => void;
    canOpen?: (target: ProjectTabTarget) => boolean;
    /** Sprint 27 P3 — the Material tab's lines (null for viewers without that tab). */
    projectMaterials?: ProjectMaterial[] | null;
}) {
    const active = project.status === 'ACTIVE';
    const currentIndex = Math.max(0, PHASE_ORDER.indexOf(execution.phase));
    const reached = PHASE_ORDER.slice(0, currentIndex + 1);
    const next = active ? (PHASE_ORDER[currentIndex + 1] ?? null) : null;

    // H2d — a card shared by several phases lives in the latest reached one.
    const owner = new Map<ExecutionCard, ProjectPhase>();
    reached.forEach((phase) => PHASE_CARDS[phase].forEach((card) => owner.set(card, phase)));

    function relevant(card: ExecutionCard): boolean {
        switch (card) {
            // The server sends the TM plan only during TM; at PERSIAPAN the card points to the Material tab.
            case 'materialPlan':
                return active && execution.phase === 'PERSIAPAN';
            // Once approved, the PRODUKSI history line says when and by whom.
            case 'departure':
                return execution.phase === 'PRODUKSI';
            default:
                return true;
        }
    }

    function cardsOf(phase: ProjectPhase): ExecutionCard[] {
        return PHASE_CARDS[phase].filter((card) => owner.get(card) === phase && relevant(card));
    }

    /** History rows only fold cards that have something to show (H2e). */
    function hasData(card: ExecutionCard): boolean {
        switch (card) {
            case 'workingDrawing':
                return execution.workingDrawing !== null;
            case 'meeting':
                return execution.meeting !== null;
            case 'dpRequirements':
                return execution.dpRequirements.length > 0;
            case 'materialOrders':
                return Boolean(projectMaterials?.length);
            case 'fee':
                return execution.fee !== null;
            case 'targetVsActual':
                return execution.targetVsActual !== null;
            case 'weekly':
                return execution.currentWeek !== null || execution.weeklyReports.length > 0;
            case 'findings':
                return execution.can.recordFinding || execution.findings.length > 0;
            default:
                return true;
        }
    }

    /** A folded card that still waits on this viewer opens by itself — folding never hides a pending action. */
    function waitsOnViewer(card: ExecutionCard): boolean {
        const { can, fee } = execution;

        switch (card) {
            case 'fee':
                return (can.requestSettlement && fee !== null && fee.remaining > 0) || (can.decide && execution.payments.some((row) => row.status === 'DIAJUKAN' && !row.via_report));
            case 'weekly':
                return can.decide && execution.weeklyReports.some((row) => row.status === 'DIAJUKAN');
            case 'findings':
                return can.resolveFinding && execution.findings.some((row) => row.status === 'TERBUKA');
            default:
                return false;
        }
    }

    function renderCard(card: ExecutionCard): ReactNode {
        let body: ReactNode;

        switch (card) {
            case 'workingDrawing':
                body = <WorkingDrawingCard execution={execution} />;
                break;
            case 'meeting':
                body = (
                    <TechnicalMeetingCard
                        project={project}
                        execution={execution}
                        milestones={milestones}
                        canManageTimeline={canManageMilestones}
                        fieldStaff={fieldStaff}
                    />
                );
                break;
            case 'qc':
                body = <QcPanelCard execution={execution} />;
                break;
            case 'materialOrders':
                body = projectMaterials && <MaterialOrdersCard lines={projectMaterials} onOpen={onOpen} canOpen={canOpen} />;
                break;
            case 'dpRequirements':
                body = <DpRequirementsCard projectId={project.id} execution={execution} onOpen={onOpen} canOpen={canOpen} />;
                break;
            case 'materialPlan':
                body = <MaterialPlanCard project={project} execution={execution} />;
                break;
            case 'fee':
                body = <SupervisorFeeCard projectId={project.id} execution={execution} />;
                break;
            case 'targetVsActual':
                body = execution.targetVsActual && (
                    <TargetVsActualCard project={project} execution={execution} fieldStaff={fieldStaff} onOpenActivityTarget={onOpenActivityTarget} />
                );
                break;
            case 'departure':
                body = <DepartureCard project={project} execution={execution} />;
                break;
            case 'weekly':
                body = <WeeklyReportsCard projectId={project.id} execution={execution} onOpen={onOpen} />;
                break;
            case 'findings':
                body = <SiteFindingsCard projectId={project.id} execution={execution} milestones={milestones} fieldStaff={fieldStaff} />;
                break;
        }

        // Sprint 26 H1e — ids are the targets of the header's step link (`projectStep()`).
        return (
            <div key={card} id={CARD_ANCHORS[card][0]} className="scroll-mt-24 empty:hidden">
                {body}
            </div>
        );
    }

    // Projects opened before Sprint 24 skipped phases 1–3 (no drawing, no TM) — one note instead of three rows.
    const skippedOldFlow = execution.isLegacy || (execution.workingDrawing === null && execution.meeting === null && currentIndex >= 3);
    const historyPhases = (active ? reached.slice(0, -1) : reached).filter((phase) => !(skippedOldFlow && PHASE_ORDER.indexOf(phase) < 3));

    // A target in the running phase's cards (e.g. `?tab=execution#tm-bahan` on load): history rows
    // run their effects first and consume the hash when it is theirs.
    useEffect(() => {
        const id = pendingHash();

        if (id && document.getElementById(id)) {
            consumeHash();
            scrollToId(id);
        }
    }, []);

    return (
        <div className="space-y-6">
            {skippedOldFlow && (
                <p className="text-xs text-muted-foreground">Proyek ini dibuka sebelum alur eksekusi baru — Gambar Kerja, TM & Persiapan dilewati, dimulai dari tahap Produksi.</p>
            )}

            {execution.deadline.warning && (
                <Notice tone={execution.deadline.days_left !== null && execution.deadline.days_left < 0 ? 'error' : 'warning'}>
                    Mendekati Tanggal Selesai ({formatDate(execution.deadline.end_date)}):{' '}
                    {execution.deadline.days_left !== null && execution.deadline.days_left >= 0
                        ? `sisa ${execution.deadline.days_left} hari`
                        : `lewat ${Math.abs(execution.deadline.days_left ?? 0)} hari`}{' '}
                    — {progressPhrase(execution.progress, execution.progressBasis)}.
                </Notice>
            )}

            {historyPhases.length > 0 && (
                <SectionCard
                    title={active ? 'Tahap Sebelumnya' : 'Riwayat Eksekusi'}
                    description={
                        active
                            ? 'Ketuk satu tahap untuk melihat rinciannya.'
                            : `Proyek ${project.status === 'COMPLETED' ? 'selesai' : project.status === 'ON_HOLD' ? 'ditahan' : 'dibatalkan'} — tiap tahap dilipat sebagai riwayat.`
                    }
                    icon={History}
                    flush
                >
                    <ul className="divide-y divide-border">
                        {historyPhases.map((phase) => {
                            const cards = cardsOf(phase).filter(hasData);
                            const isCurrent = phase === execution.phase;
                            const state = !isCurrent || project.status === 'COMPLETED' ? 'done' : project.status === 'ON_HOLD' ? 'hold' : 'stopped';
                            const summary =
                                state === 'done'
                                    ? phaseDoneSummary(phase, execution, milestones)
                                    : `${phaseLabel(phase)} — ${state === 'hold' ? 'ditahan' : 'dibatalkan'}`;

                            return (
                                <PhaseHistoryRow
                                    key={phase}
                                    state={state}
                                    summary={summary}
                                    anchors={cards.flatMap((card) => CARD_ANCHORS[card])}
                                    defaultOpen={cards.some(waitsOnViewer)}
                                >
                                    {cards.map(renderCard)}
                                </PhaseHistoryRow>
                            );
                        })}
                    </ul>
                </SectionCard>
            )}

            {active && cardsOf(execution.phase).map(renderCard)}

            {active && (
                <p className="text-sm text-muted-foreground">
                    <span className="font-medium text-foreground">Berikutnya: {next ? phaseLabel(next) : 'Selesai'}</span> — {nextStepText(next, execution.threshold)}
                </p>
            )}
        </div>
    );
}

/** H2b — one folded phase: a single line; with cards, a click opens them as they are. */
function PhaseHistoryRow({
    state,
    summary,
    anchors,
    defaultOpen,
    children,
}: {
    state: 'done' | 'hold' | 'stopped';
    summary: string;
    /** Card ids inside — a matching `#hash` opens the row and scrolls to the card. */
    anchors: string[];
    defaultOpen: boolean;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(defaultOpen);
    const scrollTarget = useRef<string | null>(null);
    const anchorKey = anchors.join(' ');
    const expandable = anchors.length > 0;

    useEffect(() => {
        function openFromHash() {
            const id = pendingHash();

            if (id && anchorKey.split(' ').includes(id)) {
                consumeHash();
                scrollTarget.current = id;
                setOpen(true);
            }
        }

        openFromHash();
        window.addEventListener('hashchange', openFromHash);

        return () => window.removeEventListener('hashchange', openFromHash);
    }, [anchorKey]);

    useEffect(() => {
        if (open && scrollTarget.current) {
            scrollToId(scrollTarget.current);
            scrollTarget.current = null;
        }
    }, [open]);

    const Icon = state === 'done' ? CheckCircle2 : state === 'hold' ? Pause : XCircle;
    const line = (
        <>
            <Icon
                className={cn('size-4 shrink-0', state === 'done' ? 'text-success-ink' : state === 'hold' ? 'text-warning-ink' : 'text-muted-foreground')}
                aria-hidden
            />
            <span className="min-w-0 flex-1 text-foreground">{summary}</span>
        </>
    );

    if (!expandable) {
        return <li className="flex items-center gap-3 px-4 py-3 text-sm sm:px-5">{line}</li>;
    }

    return (
        <li>
            <Collapsible open={open} onOpenChange={setOpen}>
                <CollapsibleTrigger asChild>
                    <button
                        type="button"
                        className="group flex w-full items-center gap-3 px-4 py-3 text-left text-sm transition-colors hover:bg-daiku-gray/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:px-5"
                    >
                        {line}
                        <ChevronDown className="size-4 shrink-0 text-muted-foreground transition-transform group-data-[state=open]:rotate-180" aria-hidden />
                    </button>
                </CollapsibleTrigger>
                <CollapsibleContent className="space-y-4 border-t border-border bg-daiku-gray/40 p-3 sm:p-4">{children}</CollapsibleContent>
            </Collapsible>
        </li>
    );
}

function WorkingDrawingCard({ execution }: { execution: ProjectExecution }) {
    const drawing = execution.workingDrawing;

    return (
        <SectionCard
            title="Gambar Kerja"
            icon={DraftingCompass}
            action={
                drawing?.canOpen ? (
                    <Button variant="outline" size="sm" asChild>
                        <Link href={route('design.working-drawings.show', { workingDrawing: drawing.id })}>Buka</Link>
                    </Button>
                ) : undefined
            }
        >
            {!drawing ? (
                <p className="text-sm text-muted-foreground">Tidak ada (proyek dibuka sebelum Sprint 24).</p>
            ) : (
                <div className="space-y-2 text-sm">
                    <StatusChip domain="workingDrawing" status={drawing.status} />
                    <p className="text-muted-foreground">
                        {drawing.pic ? `Arsitek: ${drawing.pic}${drawing.staff.length ? ` + ${drawing.staff.join(', ')}` : ''}` : 'Belum ditugaskan'}
                        {drawing.deadline && ` · deadline ${formatDate(drawing.deadline)}`}
                    </p>
                    {drawing.completed_at && (
                        <p className="text-muted-foreground">
                            Selesai {formatDateTime(drawing.completed_at)} · {drawing.completer}
                        </p>
                    )}
                    {drawing.drawing_urls.map((url) => (
                        <a key={url} href={url} target="_blank" rel="noreferrer" className="flex items-center gap-1 truncate text-info-ink underline">
                            <ExternalLink className="size-3.5 shrink-0" />
                            {url}
                        </a>
                    ))}
                </div>
            )}
        </SectionCard>
    );
}

/**
 * Sprint 26 H2a/H2f — PERSIAPAN's view of the TM material plan. The plan
 * itself is only sent during TM; from here the Pengawas revises it until
 * Start Produksi on the Material tab (`reviseMaterialPlan`).
 */
function MaterialPlanCard({ project, execution }: { project: Project; execution: ProjectExecution }) {
    const supervises = execution.can.requestDp;

    return (
        <SectionCard title="Rencana Bahan" description="Disusun Pengawas saat Technical Meeting." icon={Package}>
            <p className="text-sm text-muted-foreground">
                {supervises
                    ? 'Rencana bahan masih bisa Anda ubah sampai Start Produksi — tambah, ubah jumlah atau hapus baris di tab Material.'
                    : 'Pengawas masih bisa merevisi rencana bahan sampai Start Produksi.'}
            </p>
            <Button variant={supervises ? 'outline' : 'link'} size="sm" className={cn('mt-3', !supervises && 'h-auto px-0')} asChild>
                <Link href={route('projects.show', { project: project.id, tab: 'material' })}>
                    <ExternalLink className="size-3.5" />
                    Buka tab Material
                </Link>
            </Button>
        </SectionCard>
    );
}

/** Sprint 26 H4c — the PRODUKSI → PEMASANGAN step (replaces the old "Actual Activity" card). */
function DepartureCard({ project, execution }: { project: Project; execution: ProjectExecution }) {
    const { progress, progressBasis, threshold, departure, can } = execution;
    const reached = progress >= threshold;
    // Sprint 29 Sub 10 — progres berbobot (pengecekan) or actual activity, named.
    const basisLabel = PROGRESS_BASIS_LABEL[progressBasis];

    return (
        <SectionCard title="Berangkat ke Lokasi" description="Disetujui PM — proyek pindah ke tahap Pemasangan." icon={MapPin}>
            <p className="flex items-center gap-2 text-sm" title={PROGRESS_BASIS_HINT[progressBasis]}>
                {reached ? <CheckCircle2 className="size-4 shrink-0 text-success-ink" aria-hidden /> : <Circle className="size-4 shrink-0 text-muted-foreground" aria-hidden />}
                <span className={reached ? 'text-foreground' : 'text-muted-foreground'}>
                    {basisLabel} ≥ {threshold}% — sekarang <span className="font-medium text-foreground tabular-nums">{formatProgress(progress)}</span>
                </span>
            </p>
            <ProgressBar value={progress} tone={reached ? 'success' : 'default'} label={`${basisLabel} proyek`} className="mt-3" />
            {departure ? (
                <p className="mt-3 text-sm text-success-ink">
                    Berangkat ke lokasi disetujui {formatDateTime(departure.approved_at)}
                    {departure.approver && ` · ${departure.approver}`}
                </p>
            ) : can.approveDeparture ? (
                <>
                    <DepartureButton project={project} execution={execution} className="mt-4 w-full sm:w-auto" />
                    {!reached && <p className="mt-2 text-xs text-muted-foreground">Terkunci sampai {basisLabel.toLowerCase()} {threshold}%.</p>}
                </>
            ) : (
                project.status === 'ACTIVE' && (
                    <p className="mt-3 text-xs text-muted-foreground">{reached ? 'Menunggu persetujuan PM.' : `Terbuka mulai ${threshold}% · PM.`}</p>
                )
            )}
        </SectionCard>
    );
}

function shiftWeek(weekStart: string, days: number): string {
    const date = new Date(`${weekStart}T00:00:00`);
    date.setDate(date.getDate() + days);

    return date.toISOString().slice(0, 10);
}

/** D6/D7 — per Tukang per day: target (first planned) vs actual (finished), rolled ones marked. */
function TargetVsActualCard({
    project,
    execution,
    fieldStaff,
    onOpenActivityTarget,
}: {
    project: Project;
    execution: ProjectExecution;
    fieldStaff: Pick<User, 'id' | 'name' | 'is_active'>[];
    onOpenActivityTarget?: () => void;
}) {
    const data = execution.targetVsActual!;
    const [swapping, setSwapping] = useState(false);

    function goTo(week: string) {
        router.get(route('projects.show', { project: project.id }), { tab: 'execution', week }, { preserveScroll: true, preserveState: true, only: ['execution'] });
    }

    return (
        <SectionCard
            title="Target vs Actual"
            description={`Minggu mulai ${formatDate(data.week_start)} — angka: selesai / target hari itu (↻ = bergulir ke hari berikutnya).`}
            icon={Activity}
            flush
            footer={
                onOpenActivityTarget && (
                    <Button variant="link" size="sm" className="h-auto px-0" onClick={onOpenActivityTarget}>
                        Lihat seluruh proyek (Activity Target)
                        <ChevronRight className="size-4" />
                    </Button>
                )
            }
            action={
                <div className="flex items-center gap-1">
                    <Button variant="ghost" size="icon-sm" aria-label="Minggu sebelumnya" onClick={() => goTo(shiftWeek(data.week_start, -7))}>
                        <ChevronLeft className="size-4" />
                    </Button>
                    <Button variant="ghost" size="icon-sm" aria-label="Minggu berikutnya" onClick={() => goTo(shiftWeek(data.week_start, 7))}>
                        <ChevronRight className="size-4" />
                    </Button>
                    {execution.can.manageTargets && (
                        <Button variant="outline" size="sm" onClick={() => setSwapping(true)}>
                            <ArrowLeftRight className="size-4" />
                            Tukar Hari
                        </Button>
                    )}
                </div>
            }
        >
            {data.rows.length === 0 ? (
                <EmptyState title="Belum ada daily target." />
            ) : (
                <TableCard className="rounded-none border-0 shadow-none ring-0">
                    <table className="w-full min-w-[560px] text-sm">
                        <thead className={TABLE_HEAD_CLASS}>
                            <tr>
                                <th className="px-4 py-2 text-left">Tukang</th>
                                {data.days.map((day) => (
                                    <th key={day} className="px-2 py-2 text-center">
                                        {DAY_LABEL.format(new Date(`${day}T00:00:00`))}
                                    </th>
                                ))}
                                <th className="px-4 py-2 text-right">Minggu ini</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border">
                            {data.rows.map((row) => (
                                <tr key={row.staff_id}>
                                    <td className="px-4 py-2 font-medium text-foreground">{row.name}</td>
                                    {data.days.map((day) => {
                                        const cell = row.cells[day];
                                        const empty = cell.target === 0 && cell.actual === 0;

                                        return (
                                            <td key={day} className="px-2 py-2 text-center tabular-nums">
                                                {empty ? (
                                                    <span className="text-muted-foreground/50">—</span>
                                                ) : (
                                                    <span className={cn(cell.actual >= cell.target ? 'text-success-ink' : 'text-warning-ink')}>
                                                        {cell.actual}/{cell.target}
                                                        {cell.rolled > 0 && <span className="ml-0.5 text-xs text-error-ink">↻{cell.rolled}</span>}
                                                    </span>
                                                )}
                                            </td>
                                        );
                                    })}
                                    <td className="px-4 py-2 text-right tabular-nums">
                                        {row.actual}/{row.target}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </TableCard>
            )}
            <SwapDaysDialog open={swapping} onOpenChange={setSwapping} projectId={project.id} fieldStaff={fieldStaff} />
        </SectionCard>
    );
}

// Mirrors RescheduleTargetRequest (swap form). Whether a target would land on a
// non-working day depends on which day holds targets — TaskService::swapDays() checks it.
const swapSchema = z
    .object({
        from_date: z.string().min(1, 'Tanggal pertama wajib diisi.'),
        to_date: z.string().min(1, 'Tanggal kedua wajib diisi.'),
        assignee_id: z.string().optional(),
    })
    .refine((values) => values.from_date !== values.to_date, { message: 'Pilih dua tanggal yang berbeda.', path: ['to_date'] });

/** D9 — "Tukar": unfinished targets of two days swap places, only inside their timelines (server-checked). */
function SwapDaysDialog({
    open,
    onOpenChange,
    projectId,
    fieldStaff,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    projectId: number;
    fieldStaff: Pick<User, 'id' | 'name' | 'is_active'>[];
}) {
    const [saving, setSaving] = useState(false);
    const form = useForm<z.infer<typeof swapSchema>>({ resolver: zodResolver(swapSchema), defaultValues: { from_date: '', to_date: '', assignee_id: '' } });

    function onSubmit(values: z.infer<typeof swapSchema>) {
        router.post(
            route('projects.targets.swap', { project: projectId }),
            { ...values, assignee_id: values.assignee_id ? Number(values.assignee_id) : null },
            {
                // A bulk move whose message says how many targets swapped — keeps the pop-up; reloads the targets only.
                ...projectVisit(RELOAD.tasks),
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => {
                    form.reset();
                    onOpenChange(false);
                },
                onError: (errors) => Object.entries(errors).forEach(([key, message]) => form.setError(key as 'to_date', { message })),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <ResponsiveDialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Tukar Hari Target</DialogTitle>
                    <DialogDescription>Target yang belum selesai di kedua tanggal bertukar hari — hanya di dalam rentang timeline masing-masing.</DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <FormField
                                control={form.control}
                                name="from_date"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Tanggal pertama</FormLabel>
                                        <FormControl>
                                            <Input type="date" {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="to_date"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Tanggal kedua</FormLabel>
                                        <FormControl>
                                            <Input type="date" {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <FormField
                            control={form.control}
                            name="assignee_id"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Tukang</FormLabel>
                                    <Select value={field.value || 'all'} onValueChange={(value) => field.onChange(value === 'all' ? '' : value)}>
                                        <FormControl>
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                        </FormControl>
                                        <SelectContent>
                                            <SelectItem value="all">Semua tukang</SelectItem>
                                            {fieldStaff.map((staff) => (
                                                <SelectItem key={staff.id} value={String(staff.id)}>
                                                    {staff.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </FormItem>
                            )}
                        />
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    Batal
                                </Button>
                            </DialogClose>
                            <Button type="submit" disabled={saving}>
                                {saving && <Loader2 className="size-4 animate-spin" />}
                                Tukar
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </ResponsiveDialogContent>
        </Dialog>
    );
}
