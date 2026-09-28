import { cn } from '@/lib/utils';
import { AlertTriangle, CheckCircle2, Info, type LucideIcon, XCircle } from 'lucide-react';
import type { ReactNode } from 'react';

type Tone = 'info' | 'success' | 'warning' | 'error';

const TONE: Record<Tone, { className: string; icon: LucideIcon }> = {
    info: { className: 'border-info/25 bg-info/10 text-info-ink', icon: Info },
    success: { className: 'border-success/25 bg-success/10 text-success-ink', icon: CheckCircle2 },
    warning: { className: 'border-warning/30 bg-warning/10 text-warning-ink', icon: AlertTriangle },
    error: { className: 'border-error/25 bg-error/10 text-error-ink', icon: XCircle },
};

interface NoticeProps {
    tone?: Tone;
    /** Override the tone's default icon. */
    icon?: LucideIcon;
    className?: string;
    children: ReactNode;
}

/** Inline status banner (overdue warning, blocked action, info hint) — status colour + icon + text. */
export function Notice({ tone = 'info', icon, className, children }: NoticeProps) {
    const Icon = icon ?? TONE[tone].icon;

    return (
        <div role="status" className={cn('flex items-start gap-2.5 rounded-lg border px-3.5 py-3 text-sm', TONE[tone].className, className)}>
            <Icon className="mt-0.5 size-4 shrink-0" aria-hidden />
            <div className="min-w-0 flex-1">{children}</div>
        </div>
    );
}
