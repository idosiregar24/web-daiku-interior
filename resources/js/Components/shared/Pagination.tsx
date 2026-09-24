import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';
import type { PaginatedData } from '@/types';
import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

interface PaginationProps {
    /** Any Laravel `->paginate()` payload — only its meta/links are read. */
    paginator: Pick<PaginatedData<unknown>, 'links' | 'current_page' | 'last_page' | 'total' | 'from' | 'to'>;
    className?: string;
}

/**
 * Page controls for every server-paginated list (backend-standards.md §4
 * / frontend-standards.md §4: paging happens server-side). Uses the
 * paginator's own `links` so the current query string (filters) carries
 * over — controllers call `->withQueryString()`. Laravel's first/last
 * `links` entries are prev/next; their English, HTML-entity labels are
 * replaced with icons here. Renders nothing for a single page.
 */
export function Pagination({ paginator, className }: PaginationProps) {
    if (paginator.last_page <= 1) {
        return null;
    }

    const previous = paginator.links[0];
    const next = paginator.links[paginator.links.length - 1];
    const pages = paginator.links.slice(1, -1);

    return (
        <nav
            aria-label="Navigasi halaman"
            className={cn('mt-4 flex flex-col items-center justify-between gap-3 sm:flex-row', className)}
        >
            <p className="text-xs text-daiku-muted">
                Menampilkan {paginator.from ?? 0}–{paginator.to ?? 0} dari {paginator.total} data
            </p>
            <div className="flex flex-wrap items-center gap-1">
                <PageLink url={previous?.url ?? null} label="Sebelumnya">
                    <ChevronLeft className="size-4" />
                </PageLink>
                {pages.map((link, index) =>
                    link.url === null ? (
                        <span key={`gap-${index}`} className="px-2 text-sm text-daiku-muted">
                            …
                        </span>
                    ) : (
                        <PageLink key={link.label} url={link.url} active={link.active} label={`Halaman ${link.label}`}>
                            {link.label}
                        </PageLink>
                    ),
                )}
                <PageLink url={next?.url ?? null} label="Berikutnya">
                    <ChevronRight className="size-4" />
                </PageLink>
            </div>
        </nav>
    );
}

function PageLink({
    url,
    active = false,
    label,
    children,
}: {
    url: string | null;
    active?: boolean;
    label: string;
    children: React.ReactNode;
}) {
    if (url === null) {
        return (
            <Button variant="outline" size="sm" disabled aria-label={label} className="min-w-8">
                {children}
            </Button>
        );
    }

    return (
        <Button variant={active ? 'default' : 'outline'} size="sm" asChild className="min-w-8">
            <Link href={url} preserveScroll preserveState aria-label={label} aria-current={active ? 'page' : undefined}>
                {children}
            </Link>
        </Button>
    );
}
