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
