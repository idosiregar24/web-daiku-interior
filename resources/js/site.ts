/*
 * Sprint 20 — the public company profile's only script. Vanilla and small
 * (no React on the public site): the phone menu, the "Layanan" dropdown,
 * the portfolio lightbox, the service carousel and the motion (scroll
 * reveal, counting numbers, header line). Every page still works without
 * it — the dropdown trigger is a link to #layanan, gallery photos link to
 * the file, and the hidden reveal states only exist under `html.js`.
 */

const FOCUSABLE = 'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])';

const reducedMotion = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function phoneMenu(): void {
    const menu = document.querySelector<HTMLElement>('[data-menu]');
    const opener = document.querySelector<HTMLButtonElement>('[data-menu-open]');

    if (!menu || !opener) return;

    const focusables = () => Array.from(menu.querySelectorAll<HTMLElement>(FOCUSABLE));

    const close = () => {
        // Slide out first (site.css), then take it out of the page.
        menu.classList.remove('is-open');
        window.setTimeout(() => menu.classList.add('hidden'), reducedMotion() ? 0 : 450);
        opener.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
        document.removeEventListener('keydown', onKey);
        opener.focus();
    };

    const onKey = (event: KeyboardEvent) => {
        if (event.key === 'Escape') {
            close();
            return;
        }

        // Keep Tab inside the open panel.
        if (event.key === 'Tab') {
            const items = focusables();
            const first = items[0];
            const last = items[items.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    };

    opener.addEventListener('click', () => {
        menu.classList.remove('hidden');
        requestAnimationFrame(() => requestAnimationFrame(() => menu.classList.add('is-open')));
        opener.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', onKey);
        focusables()[0]?.focus();
    });

    menu.querySelectorAll('[data-menu-close]').forEach((element) => element.addEventListener('click', close));
}

function dropdowns(): void {
    document.querySelectorAll<HTMLElement>('[data-dropdown]').forEach((root) => {
        const trigger = root.querySelector<HTMLElement>('[data-dropdown-trigger]');
        const panel = root.querySelector<HTMLElement>('[data-dropdown-panel]');
        const chevron = root.querySelector<SVGElement>('[data-dropdown-chevron]');

        if (!trigger || !panel) return;

        const setOpen = (open: boolean) => {
            panel.classList.toggle('hidden', !open);
            trigger.setAttribute('aria-expanded', String(open));
            chevron?.classList.toggle('rotate-180', open);
        };

        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            setOpen(panel.classList.contains('hidden'));
        });

        document.addEventListener('click', (event) => {
            if (!root.contains(event.target as Node)) setOpen(false);
        });

        root.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                setOpen(false);
                trigger.focus();
            }
        });

        root.addEventListener('focusout', (event) => {
            if (!root.contains(event.relatedTarget as Node | null)) setOpen(false);
        });
    });
}

function lightbox(): void {
    const dialog = document.querySelector<HTMLDialogElement>('[data-lightbox-dialog]');
    const links = Array.from(document.querySelectorAll<HTMLAnchorElement>('[data-lightbox]'));

    if (!dialog || links.length === 0 || typeof dialog.showModal !== 'function') return;

    const image = dialog.querySelector<HTMLImageElement>('[data-lightbox-image]');
    const counter = dialog.querySelector<HTMLElement>('[data-lightbox-counter]');
    let current = 0;

    const show = (index: number) => {
        current = (index + links.length) % links.length;
        const link = links[current];
        const thumb = link.querySelector('img');

        if (image) {
            image.src = link.href;
            image.alt = thumb?.alt ?? '';
        }

        if (counter) counter.textContent = links.length > 1 ? `${current + 1} / ${links.length}` : '';
    };

    links.forEach((link, index) =>
        link.addEventListener('click', (event) => {
            event.preventDefault();
            show(index);
            dialog.showModal();
        }),
    );

    dialog.querySelector('[data-lightbox-close]')?.addEventListener('click', () => dialog.close());
    dialog.querySelector('[data-lightbox-prev]')?.addEventListener('click', () => show(current - 1));
    dialog.querySelector('[data-lightbox-next]')?.addEventListener('click', () => show(current + 1));

    // A click on the dark backdrop (not the photo or a button) closes it.
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog || (event.target as HTMLElement).classList.contains('on-dark')) dialog.close();
    });

    dialog.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowLeft') show(current - 1);
        if (event.key === 'ArrowRight') show(current + 1);
    });
}

/** Arrow buttons of a scroll-snap row (the service cards): one card per click. */
function carousels(): void {
    document.querySelectorAll<HTMLElement>('[data-carousel]').forEach((track) => {
        const step = () => (track.firstElementChild as HTMLElement | null)?.offsetWidth ?? track.clientWidth;
        const gap = () => parseFloat(getComputedStyle(track).columnGap) || 0;

        document.querySelectorAll<HTMLButtonElement>(`[aria-controls="${track.id}"]`).forEach((button) => {
            const direction = button.hasAttribute('data-carousel-prev') ? -1 : 1;
            button.addEventListener('click', () => track.scrollBy({ left: direction * (step() + gap()), behavior: 'smooth' }));
        });
    });
}

/**
 * Scroll reveal: [data-reveal] fades up once it enters the screen; the
 * children of [data-reveal-group] do so one after another.
 */
function reveals(): void {
    document.querySelectorAll<HTMLElement>('[data-reveal-group]').forEach((group) => {
        Array.from(group.children).forEach((child, index) => {
            const element = child as HTMLElement;
            element.setAttribute('data-reveal', '');
            element.style.setProperty('--reveal-delay', `${Math.min(index, 6) * 90}ms`);
        });
    });

    const targets = document.querySelectorAll<HTMLElement>('[data-reveal]');

    if (!('IntersectionObserver' in window) || reducedMotion()) {
        targets.forEach((element) => element.classList.add('is-visible'));
        return;
    }

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            });
        },
        { rootMargin: '0px 0px -8% 0px', threshold: 0.12 },
    );

    targets.forEach((element) => observer.observe(element));
}

/** Numbers marked [data-count] ("150+", "8") count up from 0 when they come into view. */
function counters(): void {
    if (reducedMotion() || !('IntersectionObserver' in window)) return;

    const run = (element: HTMLElement) => {
        const target = Number(element.dataset.countTarget);
        const suffix = element.dataset.countSuffix ?? '';
        const start = performance.now();
        const duration = 1400;

        const frame = (now: number) => {
            const progress = Math.min((now - start) / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            element.textContent = Math.round(target * eased).toLocaleString('id-ID') + suffix;
            if (progress < 1) requestAnimationFrame(frame);
        };

        requestAnimationFrame(frame);
    };

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    run(entry.target as HTMLElement);
                    observer.unobserve(entry.target);
                }
            });
        },
        { threshold: 0.6 },
    );

    document.querySelectorAll<HTMLElement>('[data-count]').forEach((element) => {
        // "1.250+" → 1250 and "+"; start from 0 so the final value never flashes first.
        const match = (element.textContent ?? '').trim().match(/^([\d.]+)(.*)$/);
        if (!match) return;

        element.dataset.countTarget = match[1].replace(/\./g, '');
        element.dataset.countSuffix = match[2];
        element.textContent = `0${match[2]}`;
        observer.observe(element);
    });
}

/** A hairline under the sticky header once the page has scrolled. */
function headerLine(): void {
    const header = document.querySelector<HTMLElement>('[data-site-header]');
    if (!header) return;

    const update = () => header.toggleAttribute('data-scrolled', window.scrollY > 8);
    update();
    window.addEventListener('scroll', update, { passive: true });
}

phoneMenu();
dropdowns();
lightbox();
carousels();
reveals();
counters();
headerLine();
