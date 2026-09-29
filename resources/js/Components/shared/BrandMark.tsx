import ApplicationLogo from '@/Components/ApplicationLogo';
import { cn } from '@/lib/utils';
import type { PageProps } from '@/types';
import { usePage } from '@inertiajs/react';

interface BrandMarkProps {
    /** Size/position — applied to the uploaded logo and the fallback mark alike. */
    className?: string;
    /** Extra classes for the fallback SVG only (its fill colour). */
    fallbackClassName?: string;
}

/**
 * The company logo uploaded in Pengaturan Situs (`site.logoUrl`), or the
 * built-in mark when none is set. Use this wherever the brand appears;
 * ApplicationLogo on its own is only for showing the default mark itself
 * (e.g. the empty-state previews in Pengaturan Situs).
 */
export function BrandMark({ className, fallbackClassName }: BrandMarkProps) {
    const { site } = usePage<PageProps>().props;

    if (site?.logoUrl) {
        return <img src={site.logoUrl} alt={site.name} className={cn('object-contain', className)} />;
    }

    return <ApplicationLogo className={cn(className, fallbackClassName)} aria-hidden />;
}

interface BrandLogoTileProps {
    /** Height of the tile, e.g. "h-9" — the width follows the logo's own aspect ratio. */
    className?: string;
    /** Logo URL; defaults to the shared `site.logoUrl`. */
    src?: string | null;
}

/**
 * An uploaded logo as a rounded tile that the image fills edge to edge:
 * a square logo becomes a square tile, a wide one a wide tile — no inner
 * padding, and the rounded corners clip the image itself. The white
 * backdrop only shows through transparent parts of the logo. Render only
 * when a logo exists; callers keep their own default-mark fallback.
 */
export function BrandLogoTile({ className, src }: BrandLogoTileProps) {
    const { site } = usePage<PageProps>().props;
    const url = src ?? site?.logoUrl;

    if (!url) {
        return null;
    }

    return (
        <span className={cn('inline-flex max-w-40 shrink-0 overflow-hidden rounded-xl bg-background shadow-xs ring-1 ring-border', className)}>
            <img src={url} alt={site?.name ?? ''} className="h-full w-auto max-w-full object-contain" />
        </span>
    );
}
