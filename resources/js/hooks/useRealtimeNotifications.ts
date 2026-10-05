import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';

interface NotificationCreatedPayload {
    id: number;
    title: string;
    message: string;
}

/** Same switch as lib/echo.ts — read here so the client is only downloaded when it's on. */
const realtimeEnabled = import.meta.env.VITE_BROADCAST_CONNECTION === 'pusher';

/**
 * PRD §4.9 "Badge counter pada bell icon di navbar, update real-time via
 * Echo private channel". On each NotificationCreated event (see
 * app/Events/NotificationCreated.php) this re-fetches only the two
 * shared bell props — the server stays the single source of truth,
 * nothing is mirrored into local state (frontend-standards.md §7).
 * No-op when real-time is off — and then lib/echo.ts (pusher-js) isn't
 * even downloaded (Sprint 13 H8: every page carries this layout).
 */
export function useRealtimeNotifications(userId: number | undefined) {
    useEffect(() => {
        if (!realtimeEnabled || !userId) {
            return;
        }

        const channelName = `App.Models.User.${userId}`;
        let cancelled = false;
        let leave: (() => void) | undefined;

        import('@/lib/echo').then(({ default: client }) => {
            if (cancelled || !client) return;

            client.private(channelName).listen('.notification.created', (payload: NotificationCreatedPayload) => {
                toast.info(payload.title, { description: payload.message });
                router.reload({ only: ['notifications', 'unreadNotificationsCount'] });
            });
            leave = () => client.leave(channelName);
        });

        return () => {
            cancelled = true;
            leave?.();
        };
    }, [userId]);
}
