import type { NotificationPriority } from '@/types';

/**
 * Sprint 18 Sub 03 — a short chime for notifications that someone is
 * waiting on (P1 "klien menunggu" / P2 "perlu tindakan"). Synthesised with
 * Web Audio, so there is no sound file to ship.
 *
 * Browsers only let a page make sound after the user has interacted with
 * it, so the AudioContext is created on the first tap/click/key and the
 * chime stays silent until then (it never throws). Played only while the
 * tab is visible — a hidden tab is the device push's job (Sub 04).
 */
let context: AudioContext | null = null;

function unlock() {
    if (context) return;

    try {
        context = new AudioContext();
    } catch {
        context = null;
    }
}

if (typeof window !== 'undefined') {
    for (const event of ['pointerdown', 'keydown'] as const) {
        window.addEventListener(event, unlock, { once: true, passive: true });
    }
}

/** Two rising notes for P1 (more insistent), one for P2. */
const NOTES: Partial<Record<NotificationPriority, number[]>> = {
    CLIENT_WAITING: [880, 1320],
    ACTION_REQUIRED: [988],
};

export function playNotificationSound(priority: NotificationPriority): void {
    const notes = NOTES[priority];

    if (!notes || !context || document.visibilityState !== 'visible') return;

    try {
        void context.resume();
        const start = context.currentTime;

        notes.forEach((frequency, index) => {
            const at = start + index * 0.16;
            const oscillator = context!.createOscillator();
            const gain = context!.createGain();

            oscillator.type = 'sine';
            oscillator.frequency.value = frequency;
            gain.gain.setValueAtTime(0.0001, at);
            gain.gain.exponentialRampToValueAtTime(0.18, at + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, at + 0.3);
            oscillator.connect(gain).connect(context!.destination);
            oscillator.start(at);
            oscillator.stop(at + 0.32);
        });
    } catch {
        // No audio output — the toast is still there.
    }
}
