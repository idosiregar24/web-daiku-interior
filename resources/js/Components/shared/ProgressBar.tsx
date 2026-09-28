import { cn } from '@/lib/utils';

type Tone = 'default' | 'success' | 'warning' | 'error';

// Meter spec (dataviz): the fill carries the state, the unfilled track is a
// lighter step of the same ramp so the whole bar reads as one measure.
const FILL_CLASS: Record<Tone, string> = {
    default: 'bg-viz-1',
    success: 'bg-success',
    warning: 'bg-warning',
    error: 'bg-error',
};

const TRACK_CLASS: Record<Tone, string> = {
    default: 'bg-viz-seq-1/70',
    success: 'bg-success/15',
    warning: 'bg-warning/15',
    error: 'bg-error/15',
};

interface ProgressBarProps {
    /** 0–100; clamped. */
    value: number;
    /** Accessible name, e.g. "Progres Proyek A". */
    label: string;
    tone?: Tone;
    /** Print the percentage at the bar's end. */
    showValue?: boolean;
    className?: string;
}

/** Single-ratio meter (project progress, on-time rate, allocation usage). */
export function ProgressBar({ value, label, tone = 'default', showValue = false, className }: ProgressBarProps) {
    const clamped = Math.max(0, Math.min(100, value));

    return (
        <div className={cn('flex items-center gap-2', className)}>
            <div
                className={cn('h-1.5 flex-1 overflow-hidden rounded-full', TRACK_CLASS[tone])}
                role="progressbar"
                aria-valuenow={clamped}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-label={label}
            >
                <div className={cn('h-full rounded-full transition-[width]', FILL_CLASS[tone])} style={{ width: `${clamped}%` }} />
            </div>
            {showValue && (
                <span className="w-10 shrink-0 text-right text-xs font-medium text-foreground tabular-nums">{clamped}%</span>
            )}
        </div>
    );
}
