import { openNotification } from '@/lib/notificationHref';
import { playNotificationSound } from '@/lib/notificationSound';
import { setUnreadCount } from '@/lib/unreadTitle';
import { syncPushOwner } from '@/lib/webPush';
import type { AppNotification, NotificationPreferences, NotificationPriority, PageProps } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

type Announceable = Pick<AppNotification, 'id' | 'title' | 'message' | 'priority' | 'category'>;

/** Same switch as lib/echo.ts — read here so the client is only downloaded when it's on. */
const realtimeEnabled = import.meta.env.VITE_BROADCAST_CONNECTION === 'reverb';

/** The bell props, plus the "Perlu Tindakan" counts a new notification usually changes. */
const LIVE_PROPS = ['notifications', 'unreadNotificationsCount', 'navBadges'];

/** Fallback while the socket is down or real-time is off. */
const POLL_MS = 60_000;

/** More new notifications than this at once (e.g. after a poll) → the rest are summarised in one toast. */
const MAX_TOASTS = 3;

const RANK: Record<NotificationPriority, number> = { CLIENT_WAITING: 0, ACTION_REQUIRED: 1, UPDATE: 2, INFO: 3 };

/*
 * Module state, not refs: AppLayout remounts on every page visit, and a
 * notification toasted on one page must not toast again on the next, while
 * one that arrived *with* a visit (someone else's action meanwhile) should.
 */
let seenUserId: number | undefined;
let newestSeenId: number | null = null;
const announced = new Set<number>();
/** Pengaturan Notifikasi (Sub 05) — kept current by the hook. */
let preferences: NotificationPreferences | undefined;

function toastFor(notification: Announceable, quiet: boolean) {
    const open = { label: 'Buka', onClick: () => openNotification(notification) };

    switch (notification.priority) {
        case 'CLIENT_WAITING':
            // P1 stays until dismissed or opened — a client is waiting.
            toast.warning(notification.title, {
                id: `notification-${notification.id}`,
                description: `Segera · klien menunggu — ${notification.message}`,
                duration: Infinity,
                action: open,
            });
            break;
        case 'ACTION_REQUIRED':
            toast.info(notification.title, { description: notification.message, duration: 10_000, action: open });
            break;
        default:
            toast(notification.title, { description: notification.message, duration: 5_000 });
    }

    const muted = notification.category !== null && preferences?.muted_categories.includes(notification.category);

    if (!quiet && preferences?.sound !== false && !muted) playNotificationSound(notification.priority);
}

function announce(fresh: Announceable[]) {
    const unseen = fresh.filter((notification) => !announced.has(notification.id));
    unseen.forEach((notification) => announced.add(notification.id));

    if (unseen.length === 0) return;

    const ordered = [...unseen].sort((a, b) => RANK[a.priority] - RANK[b.priority] || b.id - a.id);
    const shown = ordered.slice(0, MAX_TOASTS);

    // One chime for the batch, in the tone of its most urgent item.
    shown.forEach((notification, index) => toastFor(notification, index > 0));

    if (ordered.length > shown.length) {
        toast(`${ordered.length - shown.length} notifikasi lain`, {
            description: 'Buka lonceng untuk melihat semuanya.',
            duration: 5_000,
        });
    }
}

/**
 * PRD §4.9 + Sprint 18 Sub 03 — makes a new notification *felt* while Daiku
 * is open: a toast by priority (P1 sticky, P2 with "Buka"), a chime for
 * P1/P2, the unread count in the tab title, and fresh bell + menu badges.
 *
 * Two ways in, one announcement (de-duplicated by id):
 * - live: NotificationCreated on the user's private Reverb channel;
 * - fallback: any time the bell props bring an id newer than the last one
 *   seen — after the 60 s poll that runs while the socket is down or
 *   real-time is off, or after an ordinary page visit.
 *
 * The server stays the single source of truth: events only trigger a
 * partial reload, nothing is mirrored into local state (frontend-standards.md §7).
 */
export function useRealtimeNotifications(userId: number | undefined) {
    const { notifications, unreadNotificationsCount, auth } = usePage<PageProps>().props;
    const [connected, setConnected] = useState(false);
    preferences = auth.user?.notification_preferences;

    // Unread count → tab title + app icon badge.
    useEffect(() => setUnreadCount(unreadNotificationsCount), [unreadNotificationsCount]);

    // Sprint 18 Sub 04 — a device already ringing follows whoever is signed in on it.
    useEffect(() => {
        if (userId) void syncPushOwner(userId).catch(() => {});
    }, [userId]);

    // New ids in the bell props since the last look (first look only records).
    useEffect(() => {
        if (seenUserId !== userId) {
            seenUserId = userId;
            newestSeenId = null;
            announced.clear();
        }

        const newest = notifications.reduce((max, notification) => Math.max(max, notification.id), 0);

        if (newestSeenId === null) {
            newestSeenId = newest;

            return;
        }

        const since = newestSeenId;
        announce(notifications.filter((notification) => notification.id > since));
        newestSeenId = Math.max(newestSeenId, newest);
    }, [notifications, userId]);

    // Live channel.
    useEffect(() => {
        if (!realtimeEnabled || !userId) {
            return;
        }

        const channelName = `App.Models.User.${userId}`;
        let cancelled = false;
        let cleanup: (() => void) | undefined;

        import('@/lib/echo').then(({ default: client, watchConnection }) => {
            if (cancelled || !client) return;

            client.private(channelName).listen('.notification.created', (payload: Announceable) => {
                announce([payload]);
                router.reload({ only: LIVE_PROPS });
            });

            let wasConnected = false;
            const unwatch = watchConnection((isConnected) => {
                setConnected(isConnected);
                // Back online: catch up on anything sent while we were away.
                if (isConnected && !wasConnected) router.reload({ only: LIVE_PROPS });
                wasConnected = isConnected;
            });

            cleanup = () => {
                unwatch();
                client.leave(channelName);
            };
        });

        return () => {
            cancelled = true;
            cleanup?.();
        };
    }, [userId]);

    // Fallback poll — only while there is no live socket, only on a visible tab.
    useEffect(() => {
        if (connected || !userId) {
            return;
        }

        const poll = () => {
            if (document.visibilityState === 'visible') router.reload({ only: LIVE_PROPS });
        };
        const timer = window.setInterval(poll, POLL_MS);
        document.addEventListener('visibilitychange', poll);

        return () => {
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', poll);
        };
    }, [connected, userId]);
}
