import { Badge } from '@/Components/ui/badge';
import { cn } from '@/lib/utils';
import { domainStatusLabel, waitingOn as holderOf, type WaitingDomain } from '@/lib/waitingOn';

type Tone = 'success' | 'warning' | 'error' | 'info' | 'neutral';

// Tinted background in the status hue, text in its darker "ink" step
// (app.css) so small chip labels stay legible.
const TONE_CLASS: Record<Tone, string> = {
    success: 'bg-success/10 text-success-ink',
    warning: 'bg-warning/15 text-warning-ink',
    error: 'bg-error/10 text-error-ink',
    info: 'bg-info/10 text-info-ink',
    neutral: 'bg-daiku-gray text-daiku-muted',
};

/**
 * Every status value across the domain unions in `@/types` (LeadStatus,
 * DesignStatus, QuotationStatus, ProjectStatus, MilestoneStatus,
 * TaskStatus, QAStatus, OvertimeStatus, TerminStatus), mapped once to a semantic tone —
 * see .claude/rules/design-standards.md §3. Add new statuses here as new
 * modules land instead of re-deriving a color per page.
 */
const STATUS_TONE: Record<string, Tone> = {
    // CRM — LeadStatus
    FOLLOW_UP: 'info',
    DEAL_DESAIN: 'success',
    CLOSING: 'success',
    LOST: 'error',
    // Design — DesignStatus
    MENUNGGU_PENUGASAN: 'info',
    BRIEF: 'neutral',
    DESAIN: 'info',
    WAITING_ACC_DESAIN: 'warning',
    REVISI_DESAIN: 'warning',
    ACC_DESAIN: 'success',
    GAMBAR_RAB: 'info',
    PEMBUATAN_PENAWARAN: 'info',
    WAITING_ACC_PENAWARAN: 'warning',
    PRODUKSI: 'info',
    DONE_PRODUKSI: 'success',
    REJECT_PRODUKSI: 'error',
    HOLD_CLIENT: 'warning',
    REVISI_CLIENT: 'warning',
    // Quotation — QuotationStatus
    DIMINTA: 'warning',
    DRAFT: 'neutral',
    SUBMITTED: 'info',
    WAITING_CEO: 'warning',
    APPROVED_INTERNAL: 'success',
    READY_TO_SEND: 'info',
    CLIENT_APPROVED: 'success',
    CEO_REVIEW: 'warning',
    PM_REVIEW: 'warning',
    SENT_TO_CLIENT: 'info',
    APPROVED: 'success',
    REJECTED: 'error',
    // Quotation — QuotationRevisionReason (why a version was closed)
    CEO_REJECTED: 'error',
    PM_REJECTED: 'error',
    CLIENT_REJECTED: 'error',
    // Project — ProjectStatus / MilestoneStatus
    ACTIVE: 'info',
    COMPLETED: 'success',
    ON_HOLD: 'warning',
    CANCELLED: 'error',
    PENDING: 'neutral',
    IN_PROGRESS: 'info',
    QA_WAITING: 'warning',
    OVERDUE: 'error',
    // Task — TaskStatus
    ONPROGRESS: 'info',
    PENGECEKAN: 'warning',
    DONE: 'success',
    OVER: 'error',
    // Overtime — OvertimeStatus
    PENDING_FINANCE: 'warning',
    APPROVED_FINANCE: 'success',
    // Pinjaman Tukang / Hutang Supplier — derived from `remaining`/`due_date`, not a DB column
    BERJALAN: 'info',
    LUNAS: 'success',
    JATUH_TEMPO: 'error',
    // Penalti — PenaltyPaymentStatus (LUNAS reused from above)
    BELUM_DIBAYAR: 'warning',
    // Termin — TerminStatus (PENDING/APPROVED/REJECTED/OVERDUE reused from above)
    SCHEDULED: 'neutral',
    INVOICED: 'info',
    PARTIAL: 'warning',
    PAID: 'success',
    // Logistik — AssetCondition / StockMovementType
    GOOD: 'success',
    FAIR: 'warning',
    DAMAGED: 'error',
    IN: 'success',
    OUT: 'info',
    RETURN: 'success',
    MERGE_OUT: 'neutral',
    MERGE_IN: 'neutral',
    // Logistik — ProjectMaterialSource / MaterialRequestStatus (Sprint 11)
    GUDANG: 'info',
    PEMBELIAN: 'warning',
    CUSTOM: 'neutral',
    MENUNGGU_PM: 'warning',
    DIAJUKAN: 'warning',
    DISETUJUI: 'success',
    DITOLAK: 'error',
    // Sprint 29 Sub 11 — MaterialOrderStatus (DIAJUKAN/DISETUJUI/DITOLAK reused from above)
    DIRENCANAKAN: 'neutral',
    DIPESAN: 'info',
    SEBAGIAN: 'warning',
    LENGKAP: 'success',
    DARI_GUDANG: 'success',
    // SDM — DisciplinaryType
    TEGURAN_LISAN: 'warning',
    SP1: 'warning',
    SP2: 'error',
    SP3: 'error',
    CATATAN: 'neutral',
    PEMBATALAN: 'neutral',
    // SDM — KpiPeriodStatus / ReviewStatus (DRAFT/SUBMITTED/APPROVED reused from above)
    OPEN: 'info',
    CLOSED: 'success',
    ACKNOWLEDGED: 'success',
    // Finance — InvoiceStatus (Sprint 12 Sub 6)
    DITERBITKAN: 'info',
    MENUNGGU_VERIFIKASI: 'warning',
    TERVERIFIKASI: 'success',
    // CRM — LeadSurveyStatus (Sprint 12)
    DIJADWALKAN: 'info',
    MENUNGGU_BAYAR: 'warning',
    SIAP: 'success',
    SELESAI: 'success',
    BATAL: 'neutral',
    // Sprint 24 — ProjectPhase, WorkingDrawing, SupervisorPayment, SiteFinding
    GAMBAR_KERJA: 'info',
    TM: 'info',
    PERSIAPAN: 'warning',
    PEMASANGAN: 'info',
    DIKERJAKAN: 'info',
    DISETUJUI_PM: 'info',
    DIBAYAR: 'success',
    TERBUKA: 'warning',
    // Sprint 29 Sub 12 — QA per item RAB (WorkItemQaStatus).
    BELUM: 'neutral',
    MENUNGGU: 'info',
    PERBAIKAN: 'error',
    LOLOS: 'success',
    // Sprint 29 Sub 13 — DailyActivityReviewStatus (+ UI-only BELUM_DIPERIKSA)
    SESUAI: 'success',
    PERLU_KLARIFIKASI: 'warning',
    TIDAK_MENGISI: 'error',
    BELUM_DIPERIKSA: 'neutral',
};

/** Labels that read better than the humanized code (Sprint 12 #5, #3). */
const STATUS_LABEL: Record<string, string> = {
    DEAL_DESAIN: 'Pengajuan Desain/Survey',
    MENUNGGU_BAYAR: 'Menunggu Pembayaran',
    MENUNGGU_PENUGASAN: 'Menunggu Penugasan',
    SIAP: 'Siap Berangkat',
    // Quotation (Sprint 12 #7–#10)
    WAITING_CEO: 'Menunggu CEO',
    APPROVED_INTERNAL: 'Disetujui Internal',
    READY_TO_SEND: 'Siap Dikirim',
    CLIENT_APPROVED: 'Disetujui Klien',
    CLIENT_REJECTED: 'Ditolak Klien',
    CANCELLED: 'Dibatalkan',
};

function humanize(status: string) {
    if (STATUS_LABEL[status]) {
        return STATUS_LABEL[status];
    }

    return status
        .toLowerCase()
        .split('_')
        .map((word) => word[0]?.toUpperCase() + word.slice(1))
        .join(' ');
}

interface StatusChipProps {
    /** Any status value from the domain unions in `@/types` (or any string — unknown values render as neutral). */
    status: string;
    /** Override the auto-generated label (e.g. a nicer Indonesian phrase). */
    label?: string;
    /** Force a tone for states outside the domain unions (e.g. user Aktif/Nonaktif). */
    tone?: StatusTone;
    /**
     * Sprint 23 K3 — which domain the status belongs to (`lib/waitingOn.ts`):
     * picks the domain's wording and appends whose turn it is
     * ("Menunggu Review · PM / Asisten PM"). Finished statuses show no holder.
     */
    domain?: WaitingDomain;
    /** Explicit holder ("· Finance") — overrides the domain's; `null` hides it. */
    waitingOn?: string | null;
    className?: string;
}

export type StatusTone = Tone;

export function StatusChip({ status, label, tone: toneOverride, domain, waitingOn, className }: StatusChipProps) {
    const tone = toneOverride ?? STATUS_TONE[status] ?? 'neutral';
    const text = label ?? (domain && domainStatusLabel(domain, status)) ?? humanize(status);
    const holder = waitingOn !== undefined ? waitingOn : domain ? holderOf(domain, status) : null;

    return (
        <Badge
            variant="secondary"
            className={cn(
                TONE_CLASS[tone],
                'h-auto min-h-5.5 rounded-md border-transparent px-2 font-medium',
                className,
            )}
        >
            {text}
            {holder && (
                <span className="font-normal opacity-80">
                    <span aria-hidden>· </span>
                    <span className="sr-only">menunggu </span>
                    {holder}
                </span>
            )}
        </Badge>
    );
}
