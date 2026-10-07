import '../css/app.css';
import './bootstrap';

import { withUnread } from '@/lib/unreadTitle';
import type { SiteBranding } from '@/types';
import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

// Tab-title suffix: the site name from Pengaturan Situs (shared `site`
// prop), kept current on every visit; the build-time name is the fallback.
let siteName: string = import.meta.env.VITE_APP_NAME || 'Laravel';

const siteNameFrom = (props: Record<string, unknown>) => (props.site as SiteBranding | undefined)?.name;

// Same for the favicon (app.blade.php sets it on the first load only).
function syncFavicon(props: Record<string, unknown>) {
    const site = props.site as SiteBranding | undefined;
    const link = document.querySelector<HTMLLinkElement>('link[rel="icon"]');
    const href = site?.faviconUrl ?? site?.logoUrl ?? '/favicon.ico';

    if (link && link.getAttribute('href') !== href) {
        link.setAttribute('href', href);
    }
}

// Sprint 13 H7 — installable as an app (public/sw.js caches nothing and
// has no fetch handler, so it never gets in Vite's way). Sprint 18: it
// also receives Web Push, hence registered in dev too (http://localhost
// counts as a secure context; a plain-http .test host does not).
if (window.isSecureContext && 'serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // Not installable then — the site works exactly the same.
        });
    });
}

createInertiaApp({
    // Sprint 18 — prefixed with the unread notification count (lib/unreadTitle).
    title: (title) => withUnread(title ? `${title} - ${siteName}` : siteName),
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.tsx`,
            import.meta.glob('./Pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        siteName = siteNameFrom(props.initialPage.props) ?? siteName;
        router.on('navigate', (event) => {
            siteName = siteNameFrom(event.detail.page.props) ?? siteName;
            syncFavicon(event.detail.page.props);
        });

        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        // Daiku Yellow (dark step, for contrast on white) — app.css token.
        color: 'var(--color-daiku-yellow-dark)',
    },
});
