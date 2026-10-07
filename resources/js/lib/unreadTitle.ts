/**
 * Sprint 18 Sub 03 — "(3) Proyek - Daiku Interior": the unread count in the
 * tab title (and on the installed app's icon), so a waiting notification is
 * visible from another tab. app.tsx's `title` callback wraps every page
 * title with withUnread(); setUnreadCount() updates the current one in place.
 */
let unread = 0;

const PREFIX = /^\(\d+\+?\) /;

export function withUnread(title: string): string {
    return unread > 0 ? `(${unread > 99 ? '99+' : unread}) ${title}` : title;
}

type BadgeNavigator = Navigator & {
    setAppBadge?: (count?: number) => Promise<void>;
    clearAppBadge?: () => Promise<void>;
};

export function setUnreadCount(count: number): void {
    if (count === unread) return;

    unread = count;
    document.title = withUnread(document.title.replace(PREFIX, ''));

    // Badging API — only the installed PWA has an icon to badge; a no-op elsewhere.
    const nav = navigator as BadgeNavigator;
    const result = count > 0 ? nav.setAppBadge?.(count) : nav.clearAppBadge?.();
    result?.catch(() => {});
}
