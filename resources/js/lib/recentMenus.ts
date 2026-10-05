/**
 * Sprint 13 #11 — the last menus this browser opened, for the command
 * menu's "Terakhir dibuka". Per device on purpose (D3): it's a
 * convenience, not data. Storage can be missing or blocked (private mode,
 * blocked site data) — every access is guarded and falls back to empty.
 */
const KEY = 'daiku:recent-menus';
const LIMIT = 5;

export function recentMenus(): string[] {
    try {
        const parsed: unknown = JSON.parse(window.localStorage.getItem(KEY) ?? '[]');

        return Array.isArray(parsed) ? parsed.filter((value): value is string => typeof value === 'string').slice(0, LIMIT) : [];
    } catch {
        return [];
    }
}

/** Remember a menu (its route name) as the most recent one. */
export function rememberMenu(routeName: string): void {
    try {
        const next = [routeName, ...recentMenus().filter((value) => value !== routeName)].slice(0, LIMIT);
        window.localStorage.setItem(KEY, JSON.stringify(next));
    } catch {
        // Storage unavailable — the list just stays empty.
    }
}
