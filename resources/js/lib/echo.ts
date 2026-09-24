import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo?: Echo<'pusher'>;
    }
}

/**
 * Mirrors the backend's BROADCAST_CONNECTION (`.env`:
 * `VITE_BROADCAST_CONNECTION="${BROADCAST_CONNECTION}"`) so one variable
 * switches real-time on for both sides. Locally it's `log` (no Soketi
 * without Docker) and this module never opens a socket — the bell still
 * refreshes on every Inertia visit, it just isn't live.
 */
export const realtimeEnabled = import.meta.env.VITE_BROADCAST_CONNECTION === 'pusher';

/**
 * Real-time client wired to Soketi (self-hosted, Pusher-protocol compatible).
 * See docker-compose.yml for the Soketi service and .env for connection
 * credentials (PUSHER_* / VITE_PUSHER_*). `null` when real-time is off.
 */
const echo: Echo<'pusher'> | null = realtimeEnabled
    ? new Echo({
          broadcaster: 'pusher',
          Pusher,
          key: import.meta.env.VITE_PUSHER_APP_KEY,
          wsHost: import.meta.env.VITE_PUSHER_HOST,
          wsPort: Number(import.meta.env.VITE_PUSHER_PORT ?? 6001),
          wssPort: Number(import.meta.env.VITE_PUSHER_PORT ?? 6001),
          forceTLS: (import.meta.env.VITE_PUSHER_SCHEME ?? 'http') === 'https',
          enabledTransports: ['ws', 'wss'],
          cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'mt1',
      })
    : null;

if (echo) {
    window.Pusher = Pusher;
    window.Echo = echo;
}

export default echo;
