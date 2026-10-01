import { Badge } from '@/Components/ui/badge';
import { cn } from '@/lib/utils';

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
    DRAFT: 'neutral',
    SUBMITTED: 'info',
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
};

function humanize(status: string) {
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
    className?: string;
}

export type StatusTone = Tone;

export function StatusChip({ status, label, tone: toneOverride, className }: StatusChipProps) {
    const tone = toneOverride ?? STATUS_TONE[status] ?? 'neutral';

    return (
        <Badge
            variant="secondary"
            className={cn(
                TONE_CLASS[tone],
                'h-5.5 rounded-md border-transparent px-2 font-medium',
                className,
            )}
        >
            {label ?? humanize(status)}
        </Badge>
    );
}
