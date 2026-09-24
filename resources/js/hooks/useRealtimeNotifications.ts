import echo from '@/lib/echo';
import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';

interface NotificationCreatedPayload {
    id: number;
    title: string;
    message: string;
}

/**
 * PRD §4.9 "Badge counter pada bell icon di navbar, update real-time via
 * Echo private channel". On each NotificationCreated event (see
 * app/Events/NotificationCreated.php) this re-fetches only the two
 * shared bell props — the server stays the single source of truth,
 * nothing is mirrored into local state (frontend-standards.md §7).
 * No-op when real-time is off (lib/echo.ts).
 */
export function useRealtimeNotifications(userId: number | undefined) {
    useEffect(() => {
        const client = echo;

        if (!client || !userId) {
            return;
        }

        const channelName = `App.Models.User.${userId}`;

        client.private(channelName).listen('.notification.created', (payload: NotificationCreatedPayload) => {
            toast.info(payload.title, { description: payload.message });
            router.reload({ only: ['notifications', 'unreadNotificationsCount'] });
        });

        return () => client.leave(channelName);
    }, [userId]);
}
