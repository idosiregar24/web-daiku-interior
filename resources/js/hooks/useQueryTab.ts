import { router } from '@inertiajs/react';
import { useState } from 'react';

/**
 * A page-level tab kept in the URL's `?tab=` (Sprint 13 #3): a link from
 * elsewhere can open a given tab, and switching tabs rewrites the address
 * so it can be shared or reloaded. The rewrite is an Inertia client-side
 * visit (`router.replace`) — no server round trip, no props reloaded.
 *
 * `available` maps each tab to whether this user gets it; an unknown or
 * unavailable `?tab=` falls back to `fallback` (which drops the param).
 */
export function useQueryTab(available: Record<string, boolean>, fallback: string): [string, (tab: string) => void] {
    const [tab, setTabState] = useState(() => {
        const requested = typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('tab') : null;

        return requested && available[requested] ? requested : fallback;
    });

    function setTab(next: string) {
        setTabState(next);

        const url = new URL(window.location.href);
        if (next === fallback) {
            url.searchParams.delete('tab');
        } else {
            url.searchParams.set('tab', next);
        }

        router.replace({ url: url.pathname + url.search + url.hash, preserveState: true, preserveScroll: true });
    }

    return [tab, setTab];
}
