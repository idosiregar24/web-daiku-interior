import { Notice } from '@/Components/shared/Notice';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';
import { disablePush, enablePush, pushState, type PushState } from '@/lib/webPush';
import type { PageProps } from '@/types';
import { usePage } from '@inertiajs/react';
import { BellRing, Smartphone } from 'lucide-react';
import { useEffect, useState } from 'react';

const HELP: Partial<Record<PushState, string>> = {
    denied: 'Notifikasi diblokir browser. Klik ikon gembok di samping alamat situs → Notifikasi → Izinkan, lalu muat ulang halaman.',
    'needs-install':
        'Di iPhone/iPad: ketuk Bagikan → "Tambahkan ke Layar Utama", lalu buka Daiku dari ikon itu dan aktifkan notifikasi di sana.',
    insecure: 'Notifikasi ke perangkat hanya bisa lewat alamat HTTPS.',
    unsupported: 'Browser ini tidak mendukung notifikasi ke perangkat. Coba Chrome atau Edge terbaru.',
};

/**
 * Sprint 18 Sub 04 — "Aktifkan notifikasi di perangkat ini". The browser's
 * permission prompt only ever appears from this button (never on load).
 * Renders nothing when the server has no VAPID key.
 *
 * - `compact` (bell dropdown): only shown while it needs doing — hidden
 *   once on, and on plain-http dev hosts.
 * - `card` (Hari Ini, Pengaturan Notifikasi): always explains the state,
 *   with "Matikan" once on.
 */
export function PushOptIn({
    variant = 'card',
    hideWhenOn = false,
    className,
}: {
    variant?: 'compact' | 'card';
    /** A landing page (Hari Ini) only nudges; the settings page also offers "Matikan". */
    hideWhenOn?: boolean;
    className?: string;
}) {
    const { webPushKey, auth } = usePage<PageProps>().props;
    const [state, setState] = useState<PushState | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        let cancelled = false;
        pushState()
            .then((current) => !cancelled && setState(current))
            .catch(() => !cancelled && setState('unsupported'));

        return () => {
            cancelled = true;
        };
    }, []);

    if (!webPushKey || !auth.user || state === null) return null;
    if (variant === 'compact' && (state === 'on' || state === 'insecure' || state === 'unsupported')) return null;

    const run = async (action: () => Promise<PushState>) => {
        setBusy(true);
        try {
            setState(await action());
        } catch {
            setState(await pushState().catch(() => 'unsupported' as const));
        } finally {
            setBusy(false);
        }
    };

    const enable = () => run(() => enablePush(webPushKey, auth.user!.id));

    if (variant === 'compact') {
        return (
            <div className={cn('border-b border-border px-3 py-2.5', className)}>
                {state === 'off' ? (
                    <div className="flex items-center gap-2.5">
                        <BellRing className="size-4 shrink-0 text-daiku-yellow-dark" />
                        <p className="min-w-0 flex-1 text-xs text-muted-foreground">Dapatkan notifikasi di HP/laptop ini walau Daiku tertutup.</p>
                        <Button size="sm" onClick={enable} disabled={busy}>
                            Aktifkan
                        </Button>
                    </div>
                ) : (
                    <p className="text-xs text-muted-foreground">{HELP[state]}</p>
                )}
            </div>
        );
    }

    if (state === 'on') {
        if (hideWhenOn) return null;

        return (
            <Notice tone="success" icon={Smartphone} className={className}>
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <span>Notifikasi aktif di perangkat ini — HP/laptop berbunyi walau Daiku tertutup.</span>
                    <Button size="sm" variant="outline" onClick={() => run(disablePush)} disabled={busy}>
                        Matikan di perangkat ini
                    </Button>
                </div>
            </Notice>
        );
    }

    if (state === 'off') {
        return (
            <Notice tone="info" icon={BellRing} className={className}>
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <span>Aktifkan notifikasi supaya HP/laptop ini berbunyi saat ada pekerjaan baru, walau Daiku tertutup.</span>
                    <Button size="sm" onClick={enable} disabled={busy} className="w-full sm:w-auto">
                        Aktifkan notifikasi
                    </Button>
                </div>
            </Notice>
        );
    }

    return (
        <Notice tone={state === 'denied' ? 'warning' : 'info'} className={className}>
            {HELP[state]}
        </Notice>
    );
}
