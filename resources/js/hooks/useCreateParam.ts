import { router } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * Sprint 13 #6 — a page reached from the topbar "+ Buat" (`?create=1`)
 * opens its add dialog once, then drops the param from the address (a
 * client-side `router.replace`, no reload) so a refresh or Back doesn't
 * reopen it. `enabled` = the page offers that add button to this user.
 */
export function useCreateParam(enabled: boolean, open: () => void): void {
    useEffect(() => {
        const url = new URL(window.location.href);
        if (url.searchParams.get('create') !== '1') return;

        url.searchParams.delete('create');
        router.replace({ url: url.pathname + url.search + url.hash, preserveState: true, preserveScroll: true });

        if (enabled) open();
        // Once, on arrival.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
}
