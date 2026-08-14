import { Badge } from '@/Components/ui/badge';
import { cn } from '@/lib/utils';

type Tone = 'success' | 'warning' | 'error' | 'info' | 'neutral';

const TONE_CLASS: Record<Tone, string> = {
    success: 'bg-success/10 text-success',
    warning: 'bg-warning/10 text-warning',
    error: 'bg-error/10 text-error',
    info: 'bg-info/10 text-info',
    neutral: 'bg-daiku-gray text-daiku-muted',
};

/**
 * Every status value across the domain unions in `@/types` (LeadStatus,
 * DesignStatus, QuotationStatus, ProjectStatus, MilestoneStatus,
 * TaskStatus, QAStatus, OvertimeStatus), mapped once to a semantic tone —
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
    APPROVED_PM: 'info',
    APPROVED_FINANCE: 'success',
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
    className?: string;
}

export function StatusChip({ status, label, className }: StatusChipProps) {
    const tone = STATUS_TONE[status] ?? 'neutral';

    return (
        <Badge
            variant="secondary"
            className={cn(TONE_CLASS[tone], 'border-transparent font-medium', className)}
        >
            {label ?? humanize(status)}
        </Badge>
    );
}
