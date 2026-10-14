import { router } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

/** Quick requests finish before the overlay would only flash. */
const SHOW_AFTER_MS = 300;

type Visit = {
    method: string;
    async: boolean;
    prefetch: boolean;
    only: string[];
    preserveState: unknown;
};

/**
 * Not something the user is waiting on: background reloads (bell, inbox,
 * polling — `only` partials or async), prefetches, and filter/search GETs
 * that keep the page in place (`preserveState`). The top progress bar
 * still shows for those.
 */
function isBackground(visit: Visit): boolean {
    if (visit.async || visit.prefetch || visit.only.length > 0) {
        return true;
    }

    return visit.method === 'get' && Boolean(visit.preserveState);
}

/**
 * Sprint 23 K2 — "Menyimpan…" / "Memuat…" in the middle of the screen for
 * visits taking longer than SHOW_AFTER_MS. Purely visual (no pointer
 * capture): an open Radix dialog would otherwise treat a click on it as
 * "outside" and close itself mid-save.
 */
export function GlobalLoadingOverlay() {
    const [label, setLabel] = useState<string | null>(null);
    const current = useRef<object | null>(null);
    const timer = useRef<number | undefined>(undefined);

    useEffect(() => {
        const stopStart = router.on('start', (event) => {
            const visit = event.detail.visit as unknown as Visit;

            if (isBackground(visit)) {
                return;
            }

            current.current = event.detail.visit;
            window.clearTimeout(timer.current);
            timer.current = window.setTimeout(() => {
                if (current.current === event.detail.visit) {
                    setLabel(visit.method === 'get' ? 'Memuat…' : 'Menyimpan…');
                }
            }, SHOW_AFTER_MS);
        });

        const stopFinish = router.on('finish', (event) => {
            if (current.current !== event.detail.visit) {
                return;
            }

            current.current = null;
            window.clearTimeout(timer.current);
            setLabel(null);
        });

        return () => {
            stopStart();
            stopFinish();
            window.clearTimeout(timer.current);
        };
    }, []);

    if (!label) {
        return null;
    }

    return (
        <div className="pointer-events-none fixed inset-0 z-[60] flex items-center justify-center bg-background/40 motion-safe:animate-in motion-safe:fade-in-0" role="status" aria-live="polite">
            <div className="flex items-center gap-3 rounded-xl bg-popover px-5 py-4 text-sm font-medium text-popover-foreground shadow-lg ring-1 ring-foreground/10">
                <Loader2 className="size-5 text-daiku-yellow-dark motion-safe:animate-spin" aria-hidden />
                {label}
            </div>
        </div>
    );
}
