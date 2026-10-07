import { router } from '@inertiajs/react';

/**
 * Sprint 18 Sub 04 — this device's Web Push subscription (the server side is
 * PushSubscriptionController / WebPushService). The browser only asks for
 * permission from a click on "Aktifkan" — never on page load.
 */
export type PushState =
    /** Browser can't do Web Push at all. */
    | 'unsupported'
    /** http:// on a non-localhost host — push needs HTTPS. */
    | 'insecure'
    /** iPhone/iPad Safari tab: push works only from the Home Screen app. */
    | 'needs-install'
    /** Permission refused — only the browser's site settings can undo it. */
    | 'denied'
    /** Supported, not (yet) subscribed on this device. */
    | 'off'
    | 'on';

const isIos = () => /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

const isStandalone = () =>
    window.matchMedia('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true;

async function registration(): Promise<ServiceWorkerRegistration | undefined> {
    return (await navigator.serviceWorker.getRegistration()) ?? navigator.serviceWorker.register('/sw.js');
}

export async function pushState(): Promise<PushState> {
    if (!window.isSecureContext) return 'insecure';
    if (isIos() && !isStandalone()) return 'needs-install';
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return 'unsupported';
    if (Notification.permission === 'denied') return 'denied';

    const subscription = await (await registration())?.pushManager.getSubscription();

    return subscription && Notification.permission === 'granted' ? 'on' : 'off';
}

function keyBytes(base64Url: string): Uint8Array<ArrayBuffer> {
    const base64 = (base64Url + '='.repeat((4 - (base64Url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    const bytes = new Uint8Array(new ArrayBuffer(raw.length));

    for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);

    return bytes;
}

function save(subscription: PushSubscription, onFinish?: () => void) {
    const json = subscription.toJSON();

    router.post(
        route('push-subscriptions.store'),
        {
            endpoint: json.endpoint ?? subscription.endpoint,
            keys: { p256dh: json.keys?.p256dh ?? '', auth: json.keys?.auth ?? '' },
            content_encoding: (PushManager as unknown as { supportedContentEncodings?: string[] }).supportedContentEncodings?.includes('aes128gcm')
                ? 'aes128gcm'
                : 'aesgcm',
        },
        { preserveScroll: true, preserveState: true, async: true, onFinish },
    );
}

/** From an "Aktifkan" click: asks permission, subscribes, registers the device. */
export async function enablePush(publicKey: string, userId: number): Promise<PushState> {
    const permission = await Notification.requestPermission();

    if (permission !== 'granted') return permission === 'denied' ? 'denied' : 'off';

    const reg = await registration();

    if (!reg) return 'unsupported';

    await navigator.serviceWorker.ready;
    const subscription =
        (await reg.pushManager.getSubscription()) ??
        (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(publicKey) }));

    save(subscription, () => rememberOwner(subscription, userId));

    return 'on';
}

export async function disablePush(): Promise<PushState> {
    const subscription = await (await registration())?.pushManager.getSubscription();

    if (subscription) {
        router.delete(route('push-subscriptions.unsubscribe'), {
            data: { endpoint: subscription.endpoint },
            preserveScroll: true,
            preserveState: true,
            async: true,
        });
        await subscription.unsubscribe();
    }

    return 'off';
}

/*
 * A phone shared between logins: the browser keeps one subscription, so on
 * the first page of each signed-in user it is re-registered for them (the
 * server moves the endpoint). Remembered per device to avoid re-posting on
 * every visit.
 */
const OWNER_KEY = 'daiku:push-owner';

function rememberOwner(subscription: PushSubscription, userId: number | null) {
    try {
        localStorage.setItem(OWNER_KEY, JSON.stringify({ endpoint: subscription.endpoint, userId }));
    } catch {
        // Private mode — we just re-sync next time.
    }
}

export async function syncPushOwner(userId: number): Promise<void> {
    if (!window.isSecureContext || !('serviceWorker' in navigator) || !('PushManager' in window)) return;
    if (!('Notification' in window) || Notification.permission !== 'granted') return;

    const subscription = await (await navigator.serviceWorker.getRegistration())?.pushManager.getSubscription();

    if (!subscription) return;

    let known: { endpoint?: string; userId?: number | null } = {};

    try {
        known = JSON.parse(localStorage.getItem(OWNER_KEY) ?? '{}');
    } catch {
        known = {};
    }

    if (known.endpoint === subscription.endpoint && known.userId === userId) return;

    save(subscription, () => rememberOwner(subscription, userId));
}
