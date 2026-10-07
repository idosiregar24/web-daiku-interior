<?php

namespace App\Enums;

/**
 * Sprint 18 (K3) — how loudly a notification calls its recipient. The
 * deciding question: "if this is opened two hours late, who is kept
 * waiting?" A client → ClientWaiting; a colleague or a project →
 * ActionRequired; nobody → Update / Info. Values must match the
 * `NotificationPriority` union in resources/js/types/index.d.ts.
 */
enum NotificationPriority: string
{
    /** P1 — sticky toast, sound + vibration, re-pushed once if left unopened. */
    case ClientWaiting = 'CLIENT_WAITING';

    /** P2 — toast with "Buka" + sound. */
    case ActionRequired = 'ACTION_REQUIRED';

    /** P3 — short toast, silent push. */
    case Update = 'UPDATE';

    /** P4 — bell only, never pushed to a device. */
    case Info = 'INFO';

    public function label(): string
    {
        return match ($this) {
            self::ClientWaiting => 'Segera · klien menunggu',
            self::ActionRequired => 'Perlu tindakan',
            self::Update => 'Kabar',
            self::Info => 'Info',
        };
    }
}
