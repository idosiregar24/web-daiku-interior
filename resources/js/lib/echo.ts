import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo?: Echo<'reverb'>;
    }
}

/**
 * Mirrors the backend's BROADCAST_CONNECTION (`.env`:
 * `VITE_BROADCAST_CONNECTION="${BROADCAST_CONNECTION}"`) so one variable
 * switches real-time on for both sides. Any other value (`log`, `null`)
 * never opens a socket — the bell then falls back to a 60 s poll
 * (useRealtimeNotifications).
 */
export const realtimeEnabled = import.meta.env.VITE_BROADCAST_CONNECTION === 'reverb';

/**
 * Real-time client wired to Laravel Reverb (Sprint 18 — replaced Soketi;
 * same Pusher protocol, so pusher-js is still the transport). See .env for
 * REVERB_* / VITE_REVERB_* and docker-compose*.yml for the `reverb` service.
 * `null` when real-time is off.
 */
const echo: Echo<'reverb'> | null = realtimeEnabled
    ? new Echo({
          broadcaster: 'reverb',
          Pusher,
          key: import.meta.env.VITE_REVERB_APP_KEY,
          wsHost: import.meta.env.VITE_REVERB_HOST,
          wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
          wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
          forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
          enabledTransports: ['ws', 'wss'],
      })
    : null;

if (echo) {
    window.Pusher = Pusher;
    window.Echo = echo;
}

/** True while the socket is up; `onChange` fires on every connect/disconnect. */
export function watchConnection(onChange: (connected: boolean) => void): () => void {
    const connection = echo?.connector.pusher.connection;

    if (!connection) {
        onChange(false);

        return () => {};
    }

    const handler = ({ current }: { current: string }) => onChange(current === 'connected');
    onChange(connection.state === 'connected');
    connection.bind('state_change', handler);

    return () => connection.unbind('state_change', handler);
}

export default echo;
