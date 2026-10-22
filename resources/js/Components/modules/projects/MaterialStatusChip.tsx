import { StatusChip } from '@/Components/shared/StatusChip';
import { cn } from '@/lib/utils';
import { materialStatus, type MaterialStatusLine } from './materialArrival';

/**
 * Sprint 29 Sub 11 (E4) — a material line's status chip ("Dipesan ·
 * Logistik") with its detail (perkiraan tiba, x/y, alasan ditolak), read
 * through `materialStatus()` — the same everywhere the line shows.
 */
export function MaterialStatusChip({
    line,
    unit,
    className,
    inline = false,
}: {
    line: MaterialStatusLine;
    unit?: string | null;
    className?: string;
    /** Chip and detail on one line (list rows) instead of stacked (table cells). */
    inline?: boolean;
}) {
    const { status, detail, tone } = materialStatus(line, unit);

    return (
        <span className={cn(inline ? 'inline-flex flex-wrap items-center gap-x-1.5' : 'flex flex-col items-start gap-0.5', className)}>
            <StatusChip domain="materialOrder" status={status} />
            {detail && <span className={cn('text-xs', tone === 'error' ? 'text-error-ink' : 'text-muted-foreground')}>{detail}</span>}
        </span>
    );
}
