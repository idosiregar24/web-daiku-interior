import type { PageProps } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

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

    useEffect(() => {
        if (!flash || flash.key === lastKey.current) {
            return;
        }

        lastKey.current = flash.key;

        if (flash.success) {
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

    const closeSuccess = useCallback(() => setSuccess(null), []);

    return { success, closeSuccess };
}
