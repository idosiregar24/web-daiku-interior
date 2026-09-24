import { Card, CardContent } from '@/Components/ui/card';
import { cn } from '@/lib/utils';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

type Tone = 'default' | 'success' | 'warning' | 'error' | 'info';

const TONE_CLASS: Record<Tone, string> = {
    default: 'text-daiku-dark',
    success: 'text-success',
    warning: 'text-warning',
    error: 'text-error',
    info: 'text-info',
};

interface StatCardProps {
    label: string;
    value: ReactNode;
    /** Secondary line under the value (context, comparison, unit). */
    hint?: ReactNode;
    icon?: LucideIcon;
    /** Colors the value — reserve for values whose meaning is good/bad, not decoration. */
    tone?: Tone;
    className?: string;
}

/**
 * Headline number tile for dashboards and list-page summaries (the
 * "Analytics – Per Divisi" strips, PRD §7.1). One definition so every
 * page's KPI row reads the same.
 */
export function StatCard({ label, value, hint, icon: Icon, tone = 'default', className }: StatCardProps) {
    return (
        <Card className={cn('gap-0 py-0', className)}>
            <CardContent className="p-4">
                <div className="flex items-center justify-between gap-2">
                    <p className="text-xs font-medium text-daiku-muted">{label}</p>
                    {Icon && <Icon className="size-4 text-daiku-muted" aria-hidden />}
                </div>
                <p className={cn('mt-1 text-xl font-semibold tabular-nums', TONE_CLASS[tone])}>{value}</p>
                {hint && <p className="mt-0.5 text-xs text-daiku-muted">{hint}</p>}
            </CardContent>
        </Card>
    );
}
