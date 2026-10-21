import { cn } from '@/lib/utils';
import { Check } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Sprint 29 Sub 01 (K4) — a quiet save's feedback: "Tersimpan ✓" beside the
 * button for a moment instead of the centred success pop-up. `useSavedHint()`
 * gives the flag and the `flash()` to call from the visit's `onSuccess`.
 */
export function useSavedHint(duration = 2500) {
    const [shown, setShown] = useState(false);
    const timer = useRef<ReturnType<typeof setTimeout>>();

    const flash = useCallback(() => {
        setShown(true);
        clearTimeout(timer.current);
        timer.current = setTimeout(() => setShown(false), duration);
    }, [duration]);

    useEffect(() => () => clearTimeout(timer.current), []);

    return { shown, flash };
}

/** Renders nothing until `shown` — no empty gap in a footer or a stacked phone layout. */
export function SavedHint({ shown, label = 'Tersimpan', className }: { shown: boolean; label?: string; className?: string }) {
    if (!shown) {
        return null;
    }

    return (
        <span role="status" className={cn('inline-flex items-center justify-center gap-1 text-xs font-medium text-success-ink', className)}>
            <Check className="size-3.5" />
            {label}
        </span>
    );
}
