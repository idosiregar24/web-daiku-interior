/*
 * Daiku service worker. Sprint 13 H7: it exists so the browser offers
 * "Tambahkan ke layar utama" and opens the app full screen — it caches
 * NOTHING and never intercepts a request (H6: no offline mode), so a
 * deploy is visible at once.
 *
 * Sprint 18 Sub 04: it also shows Web Push notifications (WebPushService
 * payload) and opens their link when tapped. Still no fetch handler.
 */
self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let data = {};

    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = { body: event.data ? event.data.text() : '' };
    }

    const urgent = data.priority === 'CLIENT_WAITING';

    event.waitUntil(
        self.registration.showNotification(data.title || 'Daiku Interior', {
            body: data.body || '',
            icon: data.icon,
            badge: data.icon,
            // Same tag = a repeated reminder replaces the previous entry;
            // renotify makes the replacement ring again (not for silent P3).
            tag: data.tag,
            renotify: Boolean(data.tag) && !data.silent,
            // P1 "klien menunggu" stays on a laptop screen until handled.
            requireInteraction: Boolean(data.requireInteraction),
            silent: Boolean(data.silent),
            vibrate: urgent ? [200, 100, 200, 100, 200] : [200],
            data: { url: data.url },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    // Only ever our own pages: the link is `notifications.open` (marks it
    // read, then redirects to the right page).
    const target = new URL(event.notification.data?.url || '/', self.location.origin);
    const url = target.origin === self.location.origin ? target.href : self.location.origin;

    event.waitUntil(
        (async () => {
            const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
            const open = windows.find((client) => new URL(client.url).origin === self.location.origin);

            if (open) {
                await open.focus();

                try {
                    await open.navigate(url);

                    return;
                } catch {
                    // Not controlled by this worker yet — open a fresh tab instead.
                }
            }

            await self.clients.openWindow(url);
        })(),
    );
});
