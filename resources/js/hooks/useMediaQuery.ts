import { useEffect, useState } from 'react';

/** Tailwind's breakpoints (app.css keeps the defaults) as media queries. */
export const BELOW_MD = '(max-width: 767.98px)';
export const AT_LEAST_LG = '(min-width: 1024px)';

/**
 * Whether a CSS media query matches, kept in sync on resize/rotation.
 * Read synchronously on first render, so a phone never flashes the
 * desktop markup first. Use it only where the two layouts load different
 * code (Sprint 13 H8) — plain `md:`/`lg:` classes stay the default.
 */
export function useMediaQuery(query: string): boolean {
    const [matches, setMatches] = useState(() => typeof window !== 'undefined' && window.matchMedia(query).matches);

    useEffect(() => {
        const list = window.matchMedia(query);
        const update = () => setMatches(list.matches);

        update();
        list.addEventListener('change', update);

        return () => list.removeEventListener('change', update);
    }, [query]);

    return matches;
}
