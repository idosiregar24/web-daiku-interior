import { BrandLogoTile, BrandMark } from '@/Components/shared/BrandMark';
import type { PageProps } from '@/types';
import { usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';

/**
 * Sprint 12 Sub 5 — pages for people outside the company (the client's
 * offer link). No navigation, no user, no notifications: only the
 * company's branding over the cream canvas, and a narrow white sheet that
 * reads well on a phone.
 */
export default function PublicLayout({ children }: PropsWithChildren) {
    const { site } = usePage<PageProps>().props;

    return (
        <div className="min-h-screen bg-daiku-cream">
            <header className="mx-auto flex max-w-4xl items-center gap-3 px-4 pt-6 sm:px-6">
                {site.logoUrl ? (
                    <BrandLogoTile className="h-10 max-w-44" />
                ) : (
                    <BrandMark className="size-8" fallbackClassName="fill-daiku-yellow-dark" />
                )}
                <div className="min-w-0">
                    <p className="truncate font-semibold text-daiku-dark">{site.name}</p>
                    <p className="truncate text-xs text-daiku-muted">{site.tagline}</p>
                </div>
            </header>
            <main className="mx-auto max-w-4xl px-4 py-6 sm:px-6 sm:py-8">{children}</main>
            <footer className="mx-auto max-w-4xl px-4 pb-8 text-center text-xs text-daiku-muted sm:px-6">
                Halaman ini khusus untuk Anda — jangan bagikan link-nya kepada orang lain.
            </footer>
        </div>
    );
}
