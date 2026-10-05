/*
 * Daiku service worker (Sprint 13 H7). It exists only so the browser
 * offers "Tambahkan ke layar utama" and opens the app full screen.
 * It caches NOTHING and never intercepts a request (H6: no offline
 * mode, signal on site is fine) — every page and file comes from the
 * server as usual, so a deploy is visible at once.
 */
self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));
