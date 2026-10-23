import { TabsList } from '@/Components/ui/tabs';
import { cn } from '@/lib/utils';
import { type ComponentProps, useCallback, useEffect, useRef, useState } from 'react';

/** A mask (not a colour) fades the edge that hides more tabs, so it works on any background. */
const FADE_CLASS = {
    start: '[mask-image:linear-gradient(to_right,transparent,black_2.5rem)]',
    end: '[mask-image:linear-gradient(to_left,transparent,black_2.5rem)]',
    both: '[mask-image:linear-gradient(to_right,transparent,black_2.5rem,black_calc(100%-2.5rem),transparent)]',
} as const;

type Fade = keyof typeof FADE_CLASS | null;

/**
 * Page-level section tabs (Project detail, Master Data): full-width
 * underline bar instead of the pill switcher. Scrolls sideways on narrow
 * screens; the bottom padding keeps the active underline inside the
 * scroll box so it isn't clipped. Children are ordinary <TabsTrigger>s.
 *
 * On a phone the bar hides most tabs: the edge with more tabs behind it
 * fades, and the active tab is scrolled into view (a link that opens
 * `?tab=finance` would otherwise select a tab nobody can see).
 */
export function UnderlineTabsList({ className, ...props }: ComponentProps<typeof TabsList>) {
    // TabsList (shadcn primitive) doesn't forward a ref — the list is reached through a `display: contents` wrapper.
    const wrapper = useRef<HTMLDivElement>(null);
    const listOf = useCallback(() => (wrapper.current?.firstElementChild as HTMLElement | null) ?? null, []);
    const [fade, setFade] = useState<Fade>(null);

    const measure = useCallback(() => {
        const list = listOf();
        if (!list) return;

        const before = list.scrollLeft > 4;
        const after = list.scrollLeft + list.clientWidth < list.scrollWidth - 4;
        setFade(before && after ? 'both' : before ? 'start' : after ? 'end' : null);
    }, [listOf]);

    // Every render — the parent re-renders on a tab change, which is when the active tab may have moved out of view.
    useEffect(() => {
        const list = listOf();
        const active = list?.querySelector<HTMLElement>('[data-state="active"]');

        if (list && active) {
            const box = list.getBoundingClientRect();
            const tab = active.getBoundingClientRect();

            if (tab.left < box.left || tab.right > box.right) {
                list.scrollBy({ left: tab.left - box.left - 16, behavior: 'smooth' });
            }
        }

        measure();
    });

    useEffect(() => {
        const list = listOf();
        list?.addEventListener('scroll', measure, { passive: true });
        window.addEventListener('resize', measure);

        return () => {
            list?.removeEventListener('scroll', measure);
            window.removeEventListener('resize', measure);
        };
    }, [listOf, measure]);

    return (
        <div ref={wrapper} className="contents">
            <TabsList
                variant="line"
                className={cn(
                    'scrollbar-thin h-auto! w-full justify-start overflow-x-auto overflow-y-hidden border-b border-border pb-1.5 *:flex-none *:px-3',
                    fade && FADE_CLASS[fade],
                    className,
                )}
                {...props}
            />
        </div>
    );
}
