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

/**
 * Was this subscription made with the server's current VAPID key? After the
 * keys are regenerated every push to an old subscription is refused (403),
 * so it has to be replaced. Unknown (browser doesn't expose it) = assume yes.
 */
function matchesKey(subscription: PushSubscription, publicKey: string): boolean {
    const current = subscription.options?.applicationServerKey;

    if (!current) return true;

    const a = new Uint8Array(current);
    const b = keyBytes(publicKey);

    return a.length === b.length && a.every((byte, i) => byte === b[i]);
}

/**
 * aes128gcm (RFC 8291) unless the browser says it can't: it is the only
 * encoding Apple's push service accepts, and Safari doesn't always expose
 * `supportedContentEncodings` — falling back to the legacy aesgcm there made
 * every push to an iPhone fail.
 */
function contentEncoding(): 'aes128gcm' | 'aesgcm' {
    const supported = (PushManager as unknown as { supportedContentEncodings?: readonly string[] }).supportedContentEncodings;

    return supported && !supported.includes('aes128gcm') ? 'aesgcm' : 'aes128gcm';
}

function save(subscription: PushSubscription, onFinish?: () => void) {
    const json = subscription.toJSON();

    router.post(
        route('push-subscriptions.store'),
        {
            endpoint: json.endpoint ?? subscription.endpoint,
            keys: { p256dh: json.keys?.p256dh ?? '', auth: json.keys?.auth ?? '' },
            content_encoding: contentEncoding(),
        },
        { preserveScroll: true, preserveState: true, async: true, onFinish },
    );
}

/**
 * From an "Aktifkan" click. `pushManager.subscribe()` is called straight
 * away and asks for permission itself: Safari (iPhone) only allows it while
 * the tap is still "fresh", so asking with Notification.requestPermission()
 * first and subscribing after the user answered fails there with
 * NotAllowedError. Chrome, Edge and Firefox prompt the same way.
 */
export async function enablePush(publicKey: string, userId: number): Promise<PushState> {
    const reg = await registration();

    if (!reg) return 'unsupported';

    let subscription = await reg.pushManager.getSubscription();

    if (subscription && !matchesKey(subscription, publicKey)) {
        await subscription.unsubscribe();
        subscription = null;
    }

    try {
        subscription ??= await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(publicKey) });
    } catch (error) {
        if (Notification.permission === 'denied') return 'denied';
        if (Notification.permission === 'default') return 'off';

        throw error;
    }

    const saved = subscription;
    save(saved, () => rememberOwner(saved, userId));

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

export async function syncPushOwner(userId: number, publicKey: string | null): Promise<void> {
    if (!window.isSecureContext || !('serviceWorker' in navigator) || !('PushManager' in window)) return;
    if (!('Notification' in window) || Notification.permission !== 'granted') return;

    const subscription = await (await navigator.serviceWorker.getRegistration())?.pushManager.getSubscription();

    if (!subscription) return;

    // Made with an old VAPID key: useless now. Drop it so "Aktifkan" shows
    // again — re-subscribing needs a tap on Safari, so it isn't done here.
    if (publicKey && !matchesKey(subscription, publicKey)) {
        await subscription.unsubscribe();

        return;
    }

    let known: { endpoint?: string; userId?: number | null } = {};

    try {
        known = JSON.parse(localStorage.getItem(OWNER_KEY) ?? '{}');
    } catch {
        known = {};
    }

    if (known.endpoint === subscription.endpoint && known.userId === userId) return;

    save(subscription, () => rememberOwner(subscription, userId));
}
