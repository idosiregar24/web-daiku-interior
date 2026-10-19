import type { PageProps } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

const QUIET_HEADER = 'X-Daiku-Quiet-Success';

/**
 * Sprint 24 — spread into a visit's options for rapid inline edits (the TM
 * material planner): its own success flash skips the centred pop-up, the
 * changed row on screen is the feedback. Errors still toast.
 */
export const quietSuccess = { headers: { [QUIET_HEADER]: '1' } };

/**
 * Surfaces server feedback that otherwise has nowhere to render:
 *
 * - `flash` — every controller redirects with `->with('success', ...)`
 *   (shared by HandleInertiaRequests). Shown once per `key`, since
 *   Inertia keeps the previous flash prop across partial reloads.
 *   Sprint 23 K1: success opens the centred `SuccessDialog` (the returned
 *   `success` / `closeSuccess`, rendered by AppLayout); errors stay toasts.
 * - Validation errors from button-only actions (mark paid, approve,
 *   mark done…) — service-layer rules like "termin masih terkunci" throw
 *   a ValidationException on a key no form field displays, so without
 *   this the click would silently do nothing. Form dialogs still show
 *   their own inline errors too.
 */
export function useFlashToasts() {
    const { flash } = usePage<PageProps>().props;
    const lastKey = useRef<string | null>(null);
    const [success, setSuccess] = useState<string | null>(null);
    // Flash keys answered to a `quietSuccess` visit.
    const quietKeys = useRef(new Set<string>());

    useEffect(() => {
        if (!flash || flash.key === lastKey.current) {
            return;
        }

        lastKey.current = flash.key;

        if (flash.success && !quietKeys.current.has(flash.key)) {
            setSuccess(flash.success);
        }

        if (flash.error) {
            toast.error(flash.error);
        }
    }, [flash?.key]);

    useEffect(
        () =>
            router.on('error', (event) => {
                const [firstMessage] = Object.values(event.detail.errors);

                if (firstMessage) {
                    toast.error(firstMessage);
                }
            }),
        [],
    );

    useEffect(() => {
        let quiet = false;
        const offStart = router.on('start', (event) => {
            quiet = event.detail.visit.headers?.[QUIET_HEADER] === '1';
        });
        const offSuccess = router.on('success', (event) => {
            const key = (event.detail.page.props as PageProps).flash?.key;

            if (quiet && key) {
                quietKeys.current.add(key);
                // The flash effect may have run first — close what it opened.
                if (lastKey.current === key) {
                    setSuccess(null);
                }
            }
        });

        return () => {
            offStart();
            offSuccess();
        };
    }, []);

    const closeSuccess = useCallback(() => setSuccess(null), []);

    return { success, closeSuccess };
}
