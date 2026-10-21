import { summarizeArrivals } from '@/Components/modules/projects/materialArrival';
import type { MaterialPermissions } from '@/Components/modules/projects/ProjectMaterialsPanel';
import { formatDate, formatRupiah } from '@/lib/format';
import { waitingOn } from '@/lib/waitingOn';
import type { OvertimeRequest, Project, ProjectExecution, ProjectMaterial, QaForm, Termin } from '@/types';

/** Where a Ringkasan row is handled: a tab of Detail Proyek, its sub-tab, and a card id inside it. */
export interface ProjectTabTarget {
    tab: string;
    view?: string;
    anchor?: string;
}

/**
 * Sprint 26 H6b — one line of the Ringkasan: what is still open on this
 * project, whose turn it is (`holder`, the same words as the status chips)
 * and whether that turn is the viewer's (`mine`).
 */
export interface WaitingRow {
    key: string;
    /** Short chip text ("2 QA", "Termin 3", "TM"). */
    label: string;
    detail: string;
    holder: string | null;
    mine: boolean;
    tone: 'warning' | 'error' | 'info';
    target: ProjectTabTarget;
    /** A one-click action that already has its own component (H6d, Q6). */
    action?: 'completeMeeting' | 'approveDeparture';
}

export interface ProjectWaitingInput {
    project: Project;
    execution: ProjectExecution | null;
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
}

/** Sprint 12 #12 — when a scheme termin falls due (App\Enums\PaymentTermTrigger); same wording as the Keuangan tab. */
export function terminTrigger(termin: Termin): string {
    switch (termin.trigger) {
        case 'DI_MUKA':
            return 'Di muka';
        case 'TANGGAL':
            return `Tanggal ${formatDate(termin.scheduled_date)}`;
        case 'MILESTONE':
            return `Timeline: ${termin.milestone_name ?? termin.milestone?.name ?? '—'}`;
        case 'PROYEK_SELESAI':
            return 'Proyek selesai';
        default:
            // Pre-Sprint-12 manual termin — always a Saturday (PRD §6.4).
            return `${termin.milestone?.name ? `${termin.milestone.name} · ` : ''}Sabtu ${formatDate(termin.scheduled_date)}`;
    }
}

/**
 * Sprint 28 K7/K8b — has the scheme termin's trigger been reached? Di muka
 * and a passed date are known here; a timeline / project completion is
 * known once TerminService::remindInvoices() stamped `invoice_reminded_at`.
 * Null for a pre-Sprint-12 manual termin (no trigger).
 */
export function terminTriggerReached(termin: Termin): boolean | null {
    switch (termin.trigger) {
        case 'DI_MUKA':
            return true;
        case 'TANGGAL':
            return termin.scheduled_date !== null && termin.scheduled_date.slice(0, 10) <= todayIso();
        case 'MILESTONE':
        case 'PROYEK_SELESAI':
            return Boolean(termin.invoice_reminded_at);
        default:
            return null;
    }
}

/** Sprint 28 K7 — the "Jadwal" column: "Lunas 20/10/2026", the date, "Pemicu tercapai" or "Menunggu pemicu · Timeline: X". */
export function terminSchedule(termin: Termin): string {
    if (termin.status === 'PAID') {
        return termin.paid_at ? `Lunas ${formatDate(termin.paid_at)}` : 'Lunas';
    }

    if (termin.scheduled_date) {
        return formatDate(termin.scheduled_date);
    }

    return terminTriggerReached(termin) ? 'Pemicu tercapai' : `Menunggu pemicu · ${terminTrigger(termin)}`;
}

function todayIso(): string {
    const now = new Date();
    const pad = (value: number) => String(value).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

/** The phase step (H6b) — only while the project is ACTIVE. */
function phaseRows(input: ProjectWaitingInput): WaitingRow[] {
    const { project, execution, currentUserId } = input;

    if (project.status !== 'ACTIVE' || !project.phase) {
        return [];
    }

    const can = execution?.can;
    const isSupervisor = project.supervisor_id != null && project.supervisor_id === currentUserId;

    switch (project.phase) {
        case 'GAMBAR_KERJA': {
            const drawing = execution?.workingDrawing ?? null;
            const parts = ['Gambar kerja belum selesai'];

            if (drawing?.pic) parts.push(`PIC ${drawing.pic}`);
            if (drawing?.deadline) parts.push(`target ${formatDate(drawing.deadline)}`);

            return [
                {
                    key: 'phase-drawing',
                    label: 'Gambar Kerja',
                    detail: parts.join(' · '),
                    holder: waitingOn('workingDrawing', drawing?.status) ?? waitingOn('projectPhase', 'GAMBAR_KERJA'),
                    // The page carries no flag for the drawing team — they work on the drawing's own page.
                    mine: false,
                    tone: 'info',
                    target: { tab: 'execution', anchor: 'gambar-kerja' },
                },
            ];
        }
        case 'TM': {
            const requirements = execution?.meetingRequirements ?? [];
            const done = requirements.filter((row) => row.done).length;

            return [
                {
                    key: 'phase-tm',
                    label: 'TM',
                    detail:
                        requirements.length > 0
                            ? `Technical Meeting belum selesai · syarat ${done}/${requirements.length}`
                            : 'Technical Meeting belum selesai',
                    holder: waitingOn('projectPhase', 'TM'),
                    mine: Boolean(can?.completeMeeting || can?.saveMeeting),
                    tone: 'warning',
                    target: { tab: 'execution', anchor: 'tm' },
                    action: can?.completeMeeting ? 'completeMeeting' : undefined,
                },
            ];
        }
        case 'PERSIAPAN': {
            if (!execution) {
                return [
                    {
                        key: 'phase-dp',
                        label: 'Persiapan',
                        detail: 'Bahan, upah & daily target disiapkan, lalu DP pengawas diajukan',
                        holder: waitingOn('projectPhase', 'PERSIAPAN'),
                        mine: isSupervisor,
                        tone: 'info',
                        target: { tab: 'execution', anchor: 'syarat-dp' },
                    },
                ];
            }

            // Same reading as DpRequirementsCard: a DP not rejected counts as asked.
            const dp = execution.payments.find((payment) => payment.kind === 'DP' && payment.status !== 'DITOLAK');

            if (dp?.status === 'DIAJUKAN') {
                return [
                    {
                        key: 'phase-dp',
                        label: 'DP Pengawas',
                        detail: `DP pengawas ${formatRupiah(dp.amount)} diajukan — keputusan PM membuka Produksi`,
                        holder: waitingOn('supervisorPayment', 'DIAJUKAN'),
                        mine: can?.decide ?? false,
                        tone: 'warning',
                        target: { tab: 'execution', anchor: 'dp' },
                    },
                ];
            }

            if (dp) {
                return [];
            }

            const requirements = execution.dpRequirements;
            const done = requirements.filter((row) => row.done).length;
            const ready = requirements.length > 0 && done === requirements.length;

            return [
                {
                    key: 'phase-dp',
                    label: 'DP Pengawas',
                    detail: ready
                        ? 'Syarat DP lengkap — DP pengawas belum diajukan'
                        : requirements.length > 0
                          ? `Syarat DP belum lengkap · ${done}/${requirements.length} (jasa, bahan, upah & daily target)`
                          : 'Bahan, upah & daily target disiapkan, lalu DP pengawas diajukan',
                    holder: 'Pengawas (QA/QC)',
                    mine: ready ? (can?.requestDp ?? false) : Boolean(can?.requestDp || can?.manageTargets || isSupervisor),
                    tone: 'warning',
                    target: { tab: 'execution', anchor: 'syarat-dp' },
                },
            ];
        }
        case 'PRODUKSI': {
            if (!execution || execution.departure || execution.progress < execution.threshold) {
                return [];
            }

            return [
                {
                    key: 'phase-departure',
                    label: 'Berangkat',
                    detail: `Siap Berangkat ke Lokasi — actual activity ${execution.progress}% (syarat ${execution.threshold}%)`,
                    holder: 'PM',
                    mine: can?.approveDeparture ?? false,
                    tone: 'warning',
                    target: { tab: 'execution', anchor: 'berangkat' },
                    action: can?.approveDeparture ? 'approveDeparture' : undefined,
                },
            ];
        }
        default:
            return [];
    }
}

/** The execution flow's open decisions (supervisor payments, weekly reports, findings). */
function executionRows(input: ProjectWaitingInput): WaitingRow[] {
    const { project, execution } = input;

    if (!execution) {
        return [];
    }

    const rows: WaitingRow[] = [];
    const { can } = execution;
    // In PERSIAPAN the DP is the phase row itself.
    const payments = execution.payments.filter(
        (payment) => payment.status === 'DIAJUKAN' && !(payment.kind === 'DP' && project.phase === 'PERSIAPAN'),
    );
    const reports = execution.weeklyReports.filter((report) => report.status === 'DIAJUKAN');

    // Sprint 27 P4c — the H-30% reminder (Sprint 24 D13) as a row: the PM's turn to push the pace.
    const { deadline } = execution;
    if (project.status === 'ACTIVE' && deadline.warning && deadline.days_left !== null) {
        rows.push({
            key: 'deadline',
            label: deadline.days_left >= 0 ? `Sisa ${deadline.days_left} hari` : `Lewat ${-deadline.days_left} hari`,
            detail: `Mendekati Tanggal Selesai ${formatDate(deadline.end_date)} — actual activity ${execution.progress}%`,
            holder: 'PM',
            mine: can.decide,
            tone: deadline.days_left >= 0 ? 'warning' : 'error',
            target: { tab: 'execution', anchor: execution.targetVsActual ? 'target-vs-actual' : undefined },
        });
    }
    const findingsOpen = execution.findings.filter((finding) => finding.status === 'TERBUKA').length;
    const thisWeek = execution.currentWeek
        ? execution.weeklyReports.find((report) => report.week_start === execution.currentWeek?.week_start)
        : undefined;

    if (payments.length > 0) {
        rows.push({
            key: 'supervisor-payments',
            label: `${payments.length} jasa pengawas`,
            detail: 'Pengajuan jasa pengawas menunggu keputusan PM',
            holder: waitingOn('supervisorPayment', 'DIAJUKAN'),
            mine: can.decide,
            tone: 'warning',
            target: { tab: 'execution', anchor: 'dp' },
        });
    }

    if (reports.length > 0) {
        rows.push({
            key: 'weekly-reports',
            label: `${reports.length} laporan`,
            detail: 'Laporan mingguan pengawas menunggu persetujuan PM',
            holder: waitingOn('weeklyReport', 'DIAJUKAN'),
            mine: can.decide,
            tone: 'warning',
            target: { tab: 'execution', anchor: 'laporan-mingguan' },
        });
    }

    // Same rule as WeeklyReportsCard's "Kirim Laporan": any day of the week (Sprint 29 Sub 02), none sent (or it was rejected).
    if (can.submitWeekly && execution.currentWeek && (!thisWeek || thisWeek.status === 'DITOLAK')) {
        rows.push({
            key: 'weekly-due',
            label: 'Laporan',
            detail: `Laporan mingguan ke-${execution.currentWeek.week_number} ${thisWeek ? 'ditolak — kirim ulang' : 'belum dikirim · paling lambat Jumat'}`,
            holder: 'Pengawas (QA/QC)',
            mine: true,
            tone: thisWeek ? 'error' : execution.currentWeek.is_due ? 'warning' : 'info',
            target: { tab: 'execution', anchor: 'laporan-mingguan' },
        });
    }

    if (findingsOpen > 0) {
        rows.push({
            key: 'findings',
            label: `${findingsOpen} temuan`,
            detail: 'Temuan finishing di lokasi belum selesai',
            holder: waitingOn('siteFinding', 'TERBUKA'),
            mine: can.resolveFinding,
            tone: 'error',
            target: { tab: 'execution', anchor: 'temuan' },
        });
    }

    return rows;
}

/** The rows the old Overview had (QA, overtime, material requests, next termin), now with a holder. */
function workRows(input: ProjectWaitingInput): WaitingRow[] {
    const { project, qaForms, overtimeRequests, projectMaterials, termins, materialPermissions, currentUserId } = input;
    const rows: WaitingRow[] = [];
    const managesProject = project.pm_id === currentUserId || (project.assistant_pm_id != null && project.assistant_pm_id === currentUserId);
    const isSupervisor = project.supervisor_id != null && project.supervisor_id === currentUserId;

    const qaRejected = qaForms.filter((form) => form.status === 'REJECTED').length;
    const qaPending = qaForms.filter((form) => form.status === 'PENDING').length;

    if (qaRejected > 0) {
        rows.push({
            key: 'qa-rejected',
            label: `${qaRejected} QA`,
            detail: 'Timeline ditolak Pengawas (QA/QC) — perbaiki lalu tandai selesai lagi',
            holder: waitingOn('qa', 'REJECTED'),
            // canManageMilestones also holds for the CEO — the turn is the project's PM / Asisten PM.
            mine: input.canManageMilestones && managesProject,
            tone: 'error',
            target: { tab: 'milestone', view: 'qc' },
        });
    }

    if (qaPending > 0) {
        rows.push({
            key: 'qa-pending',
            label: `${qaPending} QA`,
            detail: 'Timeline menunggu pemeriksaan Pengawas (QA/QC)',
            holder: waitingOn('qa', 'PENDING'),
            // QaFormPolicy::review — the project's Pengawas (a project without one is open to every QA user, unknown here).
            mine: input.canOpenQaForm && isSupervisor,
            tone: 'warning',
            target: { tab: 'milestone', view: 'qc' },
        });
    }

    const overtimePm = overtimeRequests.filter((request) => request.status === 'PENDING').length;
    const overtimeFinance = overtimeRequests.filter((request) => request.status === 'PENDING_FINANCE').length;

    if (overtimePm > 0) {
        rows.push({
            key: 'overtime-pm',
            label: `${overtimePm} lembur`,
            detail: 'Pengajuan lembur menunggu ACC PM',
            holder: waitingOn('overtime', 'PENDING'),
            // pmApprove is role:PM — the Asisten PM doesn't decide overtime.
            mine: project.pm_id === currentUserId,
            tone: 'warning',
            target: { tab: 'task', view: 'lembur' },
        });
    }

    if (overtimeFinance > 0) {
        rows.push({
            key: 'overtime-finance',
            label: `${overtimeFinance} lembur`,
            detail: 'Lembur disetujui PM — menunggu ACC Finance',
            holder: waitingOn('overtime', 'PENDING_FINANCE'),
            // canMarkTerminPaid = role FINANCE (ProjectController) — the same role approves overtime.
            mine: input.canMarkTerminPaid,
            tone: 'warning',
            target: { tab: 'task', view: 'lembur' },
        });
    }

    const requestsPm = projectMaterials.filter((line) => line.request_status === 'MENUNGGU_PM').length;
    const requestsLogistics = projectMaterials.filter((line) => line.request_status === 'DIAJUKAN').length;

    if (requestsPm > 0) {
        rows.push({
            key: 'material-pm',
            label: `${requestsPm} barang`,
            detail: 'Pengajuan barang tukang menunggu ACC PM / Asisten PM',
            holder: waitingOn('materialRequest', 'MENUNGGU_PM'),
            mine: materialPermissions.pmDecide,
            tone: 'warning',
            target: { tab: 'material' },
        });
    }

    if (requestsLogistics > 0) {
        rows.push({
            key: 'material-logistics',
            label: `${requestsLogistics} barang`,
            detail: 'Pengajuan barang menunggu keputusan Logistik',
            holder: waitingOn('materialRequest', 'DIAJUKAN'),
            mine: materialPermissions.review,
            tone: 'warning',
            target: { tab: 'material' },
        });
    }

    // Sprint 27 P3c — Start Produksi → Logistik: materials not (fully) arrived yet.
    if (project.status === 'ACTIVE' && project.phase === 'PRODUKSI') {
        const arrivals = summarizeArrivals(projectMaterials);

        if (arrivals.open.length > 0) {
            rows.push({
                key: 'material-arrival',
                label: `${arrivals.open.length} bahan`,
                detail: `Bahan belum lengkap datang (${arrivals.complete} dari ${arrivals.total - arrivals.request} lengkap)`,
                holder: 'Logistik',
                mine: materialPermissions.receive && !managesProject,
                tone: 'info',
                target: { tab: 'material' },
            });
        }
    }

    const nextTermin = termins.find((termin) => termin.status !== 'PAID');

    if (nextTermin) {
        const verifying = nextTermin.invoice?.status === 'MENUNGGU_VERIFIKASI';
        // A scheduled termin is Marketing's to bill only once it falls due (a dated/upfront one on or after its day).
        const due =
            nextTermin.trigger === 'DI_MUKA' || (nextTermin.scheduled_date != null && nextTermin.scheduled_date.slice(0, 10) <= todayIso());

        rows.push({
            key: 'termin',
            label: `Termin ${nextTermin.termin_number}`,
            detail: `Termin berikutnya ${formatRupiah(nextTermin.sisa_piutang ?? nextTermin.amount)} · ${terminTrigger(nextTermin)}${
                nextTermin.status === 'OVERDUE' ? ' · lewat jadwal' : ''
            }${verifying ? ' · bukti bayar menunggu verifikasi' : ''}`,
            holder: verifying ? waitingOn('invoice', 'MENUNGGU_VERIFIKASI') : waitingOn('termin', nextTermin.status),
            mine: verifying
                ? input.canMarkTerminPaid
                : nextTermin.status === 'SCHEDULED' && due && input.canIssueTerminInvoices,
            tone: nextTermin.status === 'OVERDUE' ? 'error' : 'info',
            target: { tab: 'finance', view: 'termin' },
        });
    }

    return rows;
}

/**
 * Sprint 26 H6b–c — everything still open on this project, built only from
 * the props the page already has (golden rule 12: the cross-project queue
 * stays in ActionInboxService). Phase step first, then the execution flow,
 * then the work rows. `mine` is true only where a flag on the page says so.
 */
export function projectWaiting(input: ProjectWaitingInput): WaitingRow[] {
    return [...phaseRows(input), ...executionRows(input), ...workRows(input)];
}
