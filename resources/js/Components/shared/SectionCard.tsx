import { Card } from '@/Components/ui/card';
import { cn } from '@/lib/utils';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

interface SectionCardProps {
    title: ReactNode;
    description?: ReactNode;
    /** Optional icon in a brand-tinted tile beside the title. */
    icon?: LucideIcon;
    /** Right-aligned header control(s) — a button, a select, a link. */
    action?: ReactNode;
    /** Rendered in a muted strip under the content (totals, "see all" links). */
    footer?: ReactNode;
    /** Drop the content padding — for tables/lists that run edge to edge. */
    flush?: boolean;
    className?: string;
    contentClassName?: string;
    children: ReactNode;
}

/**
 * The standard titled panel: header (title, description, action) over a
 * hairline divider, then content, then an optional footer strip. Use it
 * for every dashboard widget, detail block and form section instead of
 * hand-assembling Card + CardHeader + CardTitle per page.
 */
export function SectionCard({
    title,
    description,
    icon: Icon,
    action,
    footer,
    flush = false,
    className,
    contentClassName,
    children,
}: SectionCardProps) {
    return (
        <Card className={cn('gap-0 py-0', className)}>
            <div className="flex items-start justify-between gap-4 border-b border-border px-4 py-3.5 sm:px-5">
                <div className="flex min-w-0 items-start gap-3">
                    {Icon && (
                        <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-daiku-yellow-light text-daiku-yellow-dark">
                            <Icon className="size-4" aria-hidden />
                        </span>
                    )}
                    <div className="min-w-0 self-center">
                        <h2 className="text-sm leading-snug font-semibold text-foreground">{title}</h2>
                        {description && <p className="mt-0.5 text-xs text-muted-foreground">{description}</p>}
                    </div>
                </div>
                {action && <div className="flex shrink-0 items-center gap-2">{action}</div>}
            </div>
            <div className={cn('flex-1', !flush && 'p-4 sm:p-5', contentClassName)}>{children}</div>
            {footer && (
                <div className="border-t border-border bg-daiku-gray/60 px-4 py-3 text-xs text-muted-foreground sm:px-5">
                    {footer}
                </div>
            )}
        </Card>
    );
}
