import { router } from '@inertiajs/react';
import { useState } from 'react';

/** Sprint 26 H3c — an old `?tab=` key that now lives as a view inside another tab. */
export type TabAliases = Record<string, readonly [tab: string, view: string]>;

/**
 * A page-level tab kept in the URL's `?tab=` (Sprint 13 #3): a link from
 * elsewhere can open a given tab, and switching tabs rewrites the address
 * so it can be shared or reloaded. The rewrite is an Inertia client-side
 * visit (`router.replace`) — no server round trip, no props reloaded.
 *
 * `available` maps each tab to whether this user gets it; an unknown or
 * unavailable `?tab=` falls back to `fallback` (which drops the param).
 *
 * Sprint 26 H3b — a tab may hold small sub-tabs: their key is `?view=`,
 * returned raw (the tab resolves it against the views this user gets and
 * falls back to its first). `aliases` keeps old links working:
 * `?tab=qa` → tab `milestone`, view `qc`.
 */
export function useQueryTab(
    available: Record<string, boolean>,
    fallback: string,
    aliases: TabAliases = {},
): [string, (tab: string, view?: string) => void, string | null, (view: string) => void] {
    const [state, setState] = useState<{ tab: string; view: string | null }>(() => {
        const params = typeof window !== 'undefined' ? new URLSearchParams(window.location.search) : null;
        const requested = params?.get('tab') ?? null;
        const alias = requested ? aliases[requested] : undefined;
        const tab = alias ? alias[0] : requested;

        if (!tab || !available[tab]) {
            return { tab: fallback, view: null };
        }

        return { tab, view: params?.get('view') ?? alias?.[1] ?? null };
    });

    function write(tab: string, view: string | null) {
        setState({ tab, view });

        const url = new URL(window.location.href);
        if (tab === fallback) {
            url.searchParams.delete('tab');
        } else {
            url.searchParams.set('tab', tab);
        }
        if (view) {
            url.searchParams.set('view', view);
        } else {
            url.searchParams.delete('view');
        }

        router.replace({ url: url.pathname + url.search + url.hash, preserveState: true, preserveScroll: true });
    }

    return [state.tab, (tab, view) => write(tab, view ?? null), state.view, (view) => write(state.tab, view)];
}

/** The view to show: the requested one when this user gets it, else the first available. */
export function resolveView(requested: string | null, views: (string | false | null | undefined)[]): string {
    const available = views.filter((view): view is string => Boolean(view));

    return requested && available.includes(requested) ? requested : (available[0] ?? '');
}
