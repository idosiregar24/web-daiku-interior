import type { AppNotification } from '@/types';
import { router } from '@inertiajs/react';

/**
 * Sprint 18 Sub 02 — where a notification leads is decided on the server
 * (`App\Support\NotificationTarget`), so the bell, the live toast and a
 * device push all land on the same page. `notifications.open` marks it read
 * (stamping `read_at`) and redirects there.
 */
export function notificationHref(notification: Pick<AppNotification, 'id'>): string {
    return route('notifications.open', { notification: notification.id });
}

/** Click handler shared by the topbar bell, the Dashboard widget and the notification history. */
export function openNotification(notification: Pick<AppNotification, 'id'>): void {
    router.visit(notificationHref(notification));
}
