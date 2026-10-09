import { BrandLogoTile, BrandMark } from '@/Components/shared/BrandMark';
import type { PageProps } from '@/types';
import { usePage } from '@inertiajs/react';
import { PropsWithChildren, ReactNode } from 'react';

interface AuthLayoutProps {
    /** Big heading above the form, e.g. "Masuk". */
    title?: string;
    /** One or two lines under the heading. */
    description?: ReactNode;
}

/**
 * Left half of the card (desktop only). Shows the login image uploaded in
 * Pengaturan Situs under a dark scrim, or — when none is set — the warm
 * gold mesh in brand colours. Text: site name/tagline + login headline.
 */
function BrandPanel() {
    const { site } = usePage<PageProps>().props;
    const hasPhoto = Boolean(site.loginImageUrl);

    return (
        <div className="relative hidden overflow-hidden rounded-2xl bg-daiku-yellow p-8 md:flex md:flex-col md:justify-between lg:p-10">
            {hasPhoto ? (
                <>
                    <img
                        src={site.loginImageUrl ?? undefined}
                        alt=""
                        className="absolute inset-0 size-full object-cover"
                    />
                    <div aria-hidden className="absolute inset-0 bg-linear-to-t from-daiku-dark/85 via-daiku-dark/30 to-daiku-dark/10" />
                </>
            ) : (
                <>
                    {/* warm gold mesh — one hue family, so the blend never turns muddy */}
                    <div aria-hidden className="absolute -top-32 -left-24 size-[28rem] rounded-full bg-daiku-cream opacity-90 blur-3xl" />
                    <div aria-hidden className="absolute top-1/4 -right-28 size-96 rounded-full bg-daiku-yellow-light opacity-70 blur-3xl" />
                    <div aria-hidden className="absolute -right-16 -bottom-24 size-[26rem] rounded-full bg-daiku-yellow-dark opacity-90 blur-3xl" />
                </>
            )}

            {/* `/` is the Blade company profile (Sprint 20): a full page load, not an Inertia visit. */}
            <a href="/" className="relative w-fit" aria-label={site.name}>
                {site.logoUrl ? (
                    <BrandLogoTile className="h-12 max-w-48 shadow-sm ring-0" />
                ) : (
                    <BrandMark className="size-10" fallbackClassName={hasPhoto ? 'fill-daiku-cream' : 'fill-daiku-dark'} />
                )}
            </a>

            <div className="relative">
                <p className={hasPhoto ? 'text-sm font-medium text-daiku-cream/80' : 'text-sm font-medium text-daiku-dark/70'}>
                    {site.name} {site.tagline}
                </p>
                <h2
                    className={
                        hasPhoto
                            ? 'mt-3 max-w-sm text-3xl leading-tight font-semibold tracking-tight text-daiku-cream'
                            : 'mt-3 max-w-sm text-3xl leading-tight font-semibold tracking-tight text-daiku-dark'
                    }
                >
                    {site.loginHeadline}
                </h2>
            </div>
        </div>
    );
}

export default function AuthLayout({ title, description, children }: PropsWithChildren<AuthLayoutProps>) {
    const { site } = usePage<PageProps>().props;

    return (
        <div className="flex min-h-screen items-center justify-center bg-daiku-cream p-4 sm:p-6 lg:p-10">
            <div className="grid w-full max-w-5xl rounded-3xl bg-card p-2.5 shadow-2xl shadow-daiku-dark/10 ring-1 ring-daiku-border md:min-h-[38rem] md:grid-cols-2">
                <BrandPanel />

                <div className="flex flex-col justify-center px-5 py-10 sm:px-10 lg:px-16">
                    <div className="mx-auto w-full max-w-sm">
                        <a href="/" className="inline-flex" aria-label={site.name}>
                            {site.logoUrl ? (
                                <BrandLogoTile className="h-11 max-w-48" />
                            ) : (
                                <BrandMark className="size-9" fallbackClassName="fill-daiku-yellow-dark" />
                            )}
                        </a>

                        {title && (
                            <h1 className="mt-6 text-3xl font-semibold tracking-tight text-foreground">{title}</h1>
                        )}
                        {description && <p className="mt-2 text-sm leading-relaxed text-muted-foreground">{description}</p>}

                        <div className="mt-8">{children}</div>
                    </div>
                </div>
            </div>
        </div>
    );
}
