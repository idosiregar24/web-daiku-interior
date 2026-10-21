import { quietSuccess } from '@/hooks/useFlashToasts';
import { projectVisit, RELOAD } from '@/lib/projectReload';
import { usePage } from '@inertiajs/react';

interface PartialVisitOptions {
    /** Rapid repeated input (stock in / out): no centred success pop-up — the changed row is the feedback. */
    quiet?: boolean;
}

/**
 * Sprint 29 Sub 01 (L9) — visit options for a Logistik save: reload only the
 * host page's `only` props (+ the shared `flash` / `navBadges`), which also
 * keeps the global "Menyimpan…" overlay away, so the button shows its own
 * spinner. Without keys the page reloads in full, as before.
 */
export function partialVisit(only: readonly string[] | undefined, { quiet = false }: PartialVisitOptions = {}) {
    return {
        preserveScroll: true,
        ...(only ? { only: [...only, 'flash', 'navBadges'], preserveState: true } : {}),
        ...(quiet ? quietSuccess : {}),
    };
}

/**
 * A material dialog also opened on Detail Proyek (ProjectMaterialsPanel):
 * there it reloads the material props (`RELOAD.materials`); on a Logistik
 * page the keys its host passes. `only` keys must exist on the current page.
 */
export function useMaterialVisit(only?: readonly string[], options: PartialVisitOptions = {}) {
    const { component } = usePage();

    return component === 'Projects/Show' && !only ? projectVisit(RELOAD.materials, options) : partialVisit(only, options);
}
