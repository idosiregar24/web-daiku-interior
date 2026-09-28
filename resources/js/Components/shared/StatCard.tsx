import { Card, CardContent } from '@/Components/ui/card';
import { cn } from '@/lib/utils';
import { ArrowDownRight, ArrowUpRight, Minus, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Sparkline } from './Sparkline';

type Tone = 'default' | 'success' | 'warning' | 'error' | 'info';

const TONE_CLASS: Record<Tone, string> = {
    default: 'text-foreground',
    success: 'text-success-ink',
    warning: 'text-warning-ink',
    error: 'text-error-ink',
    info: 'text-info-ink',
};

export interface StatDelta {
    /** Signed change, already in display units (e.g. 12.5 for "+12,5%"). */
    value: number;
    /** Appended to the number — "%" by default. */
    suffix?: string;
    /** Comparison period, e.g. "vs bulan lalu". */
    label?: string;
    /** Whether a rise is good news (revenue) or bad news (expense, overdue). */
    goodWhen?: 'up' | 'down';
}

interface StatCardProps {
    label: string;
    value: ReactNode;
    /** Secondary line under the value (context, comparison, unit). */
    hint?: ReactNode;
    icon?: LucideIcon;
    /** Colors the value — reserve for values whose meaning is good/bad, not decoration. */
    tone?: Tone;
    /** Optional change vs a named period, colored by direction × whether up is good. */
    delta?: StatDelta;
    /** Optional oldest→newest series drawn as a sparkline beside the value. */
    trend?: number[];
    /** Extra block under the value, e.g. a <ProgressBar> meter. */
    children?: ReactNode;
    className?: string;
}

function DeltaPill({ value, suffix = '%', label, goodWhen = 'up' }: StatDelta) {
    const rounded = Math.round(value * 10) / 10;
    const direction = rounded > 0 ? 'up' : rounded < 0 ? 'down' : 'flat';
    const tone =
        direction === 'flat'
            ? 'bg-daiku-gray text-daiku-muted'
            : direction === goodWhen
              ? 'bg-success/10 text-success-ink'
              : 'bg-error/10 text-error-ink';
    const Icon = direction === 'up' ? ArrowUpRight : direction === 'down' ? ArrowDownRight : Minus;

    return (
        <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
            <span className={cn('inline-flex items-center gap-0.5 rounded-md px-1.5 py-0.5 font-medium tabular-nums', tone)}>
                <Icon className="size-3" aria-hidden />
                {rounded > 0 ? '+' : ''}
                {rounded.toLocaleString('id-ID')}
                {suffix}
            </span>
            {label}
        </span>
    );
}

/**
 * Headline number tile for dashboards and list-page summaries (the
 * "Analytics – Per Divisi" strips, PRD §7.1). One definition so every
 * page's KPI row reads the same. Follows the dataviz stat-tile contract:
 * label · value (proportional figures) · optional delta · optional trend.
 */
export function StatCard({ label, value, hint, icon: Icon, tone = 'default', delta, trend, children, className }: StatCardProps) {
    return (
        <Card className={cn('gap-0 py-0', className)}>
            <CardContent className="flex h-full flex-col gap-3 p-4 sm:p-5">
                <div className="flex items-center gap-2.5">
                    {Icon && (
                        <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-daiku-yellow-light text-daiku-yellow-dark">
                            <Icon className="size-4" aria-hidden />
                        </span>
                    )}
                    <p className="text-[13px] font-medium text-muted-foreground">{label}</p>
                </div>
                <div className="flex items-end justify-between gap-3">
                    <p className={cn('text-2xl leading-none font-semibold tracking-tight', TONE_CLASS[tone])}>{value}</p>
                    {trend && <Sparkline data={trend} />}
                </div>
                {children}
                {(delta || hint) && (
                    <div className="mt-auto flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                        {delta && <DeltaPill {...delta} />}
                        {hint && <span>{hint}</span>}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
