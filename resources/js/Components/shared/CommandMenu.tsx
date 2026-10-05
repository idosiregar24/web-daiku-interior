import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/Components/ui/dialog';
import { recentMenus } from '@/lib/recentMenus';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { Briefcase, CornerDownLeft, FileText, FolderKanban, History, IdCard, Loader2, type LucideIcon, Search, Users } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

export interface CommandGroup {
    label: string;
    items: { label: string; icon: LucideIcon; routeName: string }[];
}

interface CommandMenuProps {
    /** Already role-filtered navigation (AppLayout passes what the sidebar shows). */
    groups: CommandGroup[];
    className?: string;
}

/** One row of the list — a menu (route) or a search hit (ready URL). */
interface Entry {
    key: string;
    label: string;
    sublabel?: string | null;
    icon: LucideIcon;
    href: string;
}

interface EntryGroup {
    label: string;
    entries: Entry[];
}

/** `GET search` (SearchController) — whitelisted fields only. */
interface SearchGroup {
    key: 'projects' | 'leads' | 'quotations' | 'employees';
    label: string;
    items: { id: number; label: string; sublabel: string | null; url: string }[];
}

const SEARCH_ICON: Record<SearchGroup['key'], LucideIcon> = {
    projects: FolderKanban,
    leads: Users,
    quotations: FileText,
    employees: IdCard,
};

const MIN_QUERY = 2;

/**
 * Topbar search (Ctrl/⌘ + K) — Sprint 13 #12. Searches the menus the
 * sidebar shows for this role (local) plus projects / leads / quotations
 * / employees from `GET search` (debounced; the server scopes each kind
 * like its list page). With an empty box it shows "Terakhir dibuka" — the
 * last menus this device opened that the role can still see.
 */
export function CommandMenu({ groups, className }: CommandMenuProps) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [activeIndex, setActiveIndex] = useState(0);
    const [results, setResults] = useState<SearchGroup[]>([]);
    const [searching, setSearching] = useState(false);
    const [recent, setRecent] = useState<string[]>([]);
    const listRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        function onKeyDown(event: KeyboardEvent) {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                setOpen((value) => !value);
            }
        }

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    // Read the device's recent list each time the box opens.
    useEffect(() => {
        if (open) setRecent(recentMenus());
    }, [open]);

    // Data search: 250 ms after typing stops, the previous request aborted.
    const needle = query.trim();
    useEffect(() => {
        if (!open || needle.length < MIN_QUERY) {
            setResults([]);
            setSearching(false);

            return;
        }

        const controller = new AbortController();
        setSearching(true);
        const timer = window.setTimeout(() => {
            window.axios
                .get<{ groups: SearchGroup[] }>(route('search'), { params: { q: needle }, signal: controller.signal })
                .then((response) => setResults(response.data.groups))
                .catch(() => {
                    // Aborted, throttled or offline — the menu matches still show.
                })
                .finally(() => {
                    if (!controller.signal.aborted) setSearching(false);
                });
        }, 250);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [needle, open]);

    const sections = useMemo<EntryGroup[]>(() => {
        const lower = needle.toLowerCase();
        const menuEntry = (item: CommandGroup['items'][number]): Entry => ({
            key: `menu:${item.routeName}`,
            label: item.label,
            icon: item.icon,
            href: route(item.routeName),
        });

        if (!lower) {
            // Only menus the role still sees — a stale entry just drops out.
            const byRoute = new Map(groups.flatMap((group) => group.items.map((item) => [item.routeName, item] as const)));
            const recentEntries = recent.flatMap((routeName) => {
                const item = byRoute.get(routeName);

                return item ? [{ ...menuEntry(item), key: `recent:${routeName}` }] : [];
            });

            return [
                ...(recentEntries.length > 0 ? [{ label: 'Terakhir dibuka', entries: recentEntries }] : []),
                ...groups.map((group) => ({ label: group.label, entries: group.items.map(menuEntry) })),
            ];
        }

        const menuSections = groups
            .map((group) => ({
                label: group.label,
                entries: group.items
                    .filter((item) => item.label.toLowerCase().includes(lower) || group.label.toLowerCase().includes(lower))
                    .map(menuEntry),
            }))
            .filter((group) => group.entries.length > 0);

        const dataSections = results.map((group) => ({
            label: group.label,
            entries: group.items.map((item) => ({
                key: `${group.key}:${item.id}`,
                label: item.label,
                sublabel: item.sublabel,
                icon: SEARCH_ICON[group.key] ?? Briefcase,
                href: item.url,
            })),
        }));

        return [...menuSections, ...dataSections];
    }, [groups, needle, recent, results]);

    const flat = useMemo(() => sections.flatMap((group) => group.entries), [sections]);

    useEffect(() => setActiveIndex(0), [query, results]);

    useEffect(() => {
        listRef.current
            ?.querySelector<HTMLElement>(`[data-index="${activeIndex}"]`)
            ?.scrollIntoView({ block: 'nearest' });
    }, [activeIndex]);

    function onOpenChange(value: boolean) {
        setOpen(value);
        if (!value) {
            setQuery('');
        }
    }

    function go(entry: Entry) {
        onOpenChange(false);
        router.visit(entry.href);
    }

    function onInputKeyDown(event: React.KeyboardEvent<HTMLInputElement>) {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActiveIndex((index) => Math.min(flat.length - 1, index + 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActiveIndex((index) => Math.max(0, index - 1));
        } else if (event.key === 'Enter' && flat[activeIndex]) {
            event.preventDefault();
            go(flat[activeIndex]);
        }
    }

    let runningIndex = -1;

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className={cn(
                    'flex h-8 items-center gap-2 rounded-lg border border-border bg-daiku-gray/60 px-2.5 text-sm text-muted-foreground transition-colors hover:border-daiku-muted/40 hover:bg-background hover:text-foreground',
                    className,
                )}
            >
                <Search className="size-4 shrink-0" aria-hidden />
                <span className="hidden flex-1 truncate text-left md:inline">Cari menu, proyek, klien…</span>
                <kbd className="hidden items-center gap-0.5 rounded border border-border bg-background px-1.5 font-sans text-[10px] font-medium text-muted-foreground md:inline-flex">
                    Ctrl K
                </kbd>
                <span className="sr-only md:hidden">Cari</span>
            </button>

            <Dialog open={open} onOpenChange={onOpenChange}>
                <DialogContent showCloseButton={false} className="top-[20%] translate-y-0 gap-0 overflow-hidden p-0 sm:max-w-lg">
                    <DialogTitle className="sr-only">Cari</DialogTitle>
                    <DialogDescription className="sr-only">
                        Ketik nama menu, proyek, klien, atau karyawan lalu tekan Enter untuk membukanya.
                    </DialogDescription>
                    <div className="flex items-center gap-2 border-b border-border px-3">
                        {searching ? (
                            <Loader2 className="size-4 shrink-0 animate-spin text-muted-foreground" aria-hidden />
                        ) : (
                            <Search className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                        )}
                        <input
                            autoFocus
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            onKeyDown={onInputKeyDown}
                            placeholder="Cari menu, proyek, klien, quotation…"
                            className="h-12 w-full border-0 bg-transparent p-0 text-sm outline-none placeholder:text-muted-foreground focus:ring-0"
                        />
                        <kbd className="rounded border border-border px-1.5 text-[10px] text-muted-foreground">Esc</kbd>
                    </div>
                    <div ref={listRef} className="scrollbar-thin max-h-96 overflow-y-auto p-2">
                        {flat.length === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">
                                {searching ? 'Mencari…' : needle.length > 0 && needle.length < MIN_QUERY ? 'Ketik minimal 2 huruf.' : 'Tidak ditemukan.'}
                            </p>
                        ) : (
                            sections.map((group) => (
                                <div key={group.label} className="mb-1 last:mb-0">
                                    <p className="flex items-center gap-1.5 px-2 pt-2 pb-1 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                                        {group.label === 'Terakhir dibuka' && <History className="size-3" aria-hidden />}
                                        {group.label}
                                    </p>
                                    {group.entries.map((entry) => {
                                        runningIndex += 1;
                                        const index = runningIndex;
                                        const Icon = entry.icon;
                                        const active = index === activeIndex;

                                        return (
                                            <button
                                                key={entry.key}
                                                type="button"
                                                data-index={index}
                                                onMouseMove={() => setActiveIndex(index)}
                                                onClick={() => go(entry)}
                                                className={cn(
                                                    'flex w-full items-center gap-2.5 rounded-md px-2 py-2 text-left text-sm',
                                                    active ? 'bg-daiku-yellow-light text-foreground' : 'text-foreground/80',
                                                )}
                                            >
                                                <Icon
                                                    className={cn('size-4 shrink-0', active ? 'text-daiku-yellow-dark' : 'text-muted-foreground')}
                                                    aria-hidden
                                                />
                                                <span className="min-w-0 flex-1">
                                                    <span className="block truncate">{entry.label}</span>
                                                    {entry.sublabel && (
                                                        <span className="block truncate text-xs text-muted-foreground">{entry.sublabel}</span>
                                                    )}
                                                </span>
                                                {active && <CornerDownLeft className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />}
                                            </button>
                                        );
                                    })}
                                </div>
                            ))
                        )}
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
