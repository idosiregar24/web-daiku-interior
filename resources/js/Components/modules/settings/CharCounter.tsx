import { cn } from '@/lib/utils';

/**
 * "48/160" under a length-limited text field of the company profile
 * settings (meta description, testimonial, summary). Turns red past
 * `max` — the Form Request's `max:` rule, so the save would be refused.
 */
export function CharCounter({ value, max, className }: { value: string | null | undefined; max: number; className?: string }) {
    const length = (value ?? '').length;

    return (
        <span className={cn('shrink-0 text-xs tabular-nums', length > max ? 'font-medium text-error-ink' : 'text-muted-foreground', className)}>
            {length}/{max}
        </span>
    );
}
