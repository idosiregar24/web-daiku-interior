import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/Components/ui/dialog';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { CornerDownLeft, type LucideIcon, Search } from 'lucide-react';
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

/**
 * Topbar "Cari menu" jump box (Ctrl/⌘ + K). Pure navigation over the same
 * items the sidebar shows for the current role — it never exposes a route
 * the sidebar hides, and server-side `role:` middleware still gates every
 * visit.
 */
export function CommandMenu({ groups, className }: CommandMenuProps) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [activeIndex, setActiveIndex] = useState(0);
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

    const filtered = useMemo(() => {
        const needle = query.trim().toLowerCase();

        return groups
            .map((group) => ({
                ...group,
                items: group.items.filter(
                    (item) =>
                        !needle ||
                        item.label.toLowerCase().includes(needle) ||
                        group.label.toLowerCase().includes(needle),
                ),
            }))
            .filter((group) => group.items.length > 0);
    }, [groups, query]);

    const flat = useMemo(() => filtered.flatMap((group) => group.items), [filtered]);

    useEffect(() => setActiveIndex(0), [query]);

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

    function go(routeName: string) {
        onOpenChange(false);
        router.visit(route(routeName));
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
            go(flat[activeIndex].routeName);
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
                <span className="hidden flex-1 text-left md:inline">Cari menu…</span>
                <kbd className="hidden items-center gap-0.5 rounded border border-border bg-background px-1.5 font-sans text-[10px] font-medium text-muted-foreground md:inline-flex">
                    Ctrl K
                </kbd>
                <span className="sr-only md:hidden">Cari menu</span>
            </button>

            <Dialog open={open} onOpenChange={onOpenChange}>
                <DialogContent showCloseButton={false} className="top-[20%] translate-y-0 gap-0 overflow-hidden p-0 sm:max-w-lg">
                    <DialogTitle className="sr-only">Cari menu</DialogTitle>
                    <DialogDescription className="sr-only">
                        Ketik nama modul lalu tekan Enter untuk membukanya.
                    </DialogDescription>
                    <div className="flex items-center gap-2 border-b border-border px-3">
                        <Search className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                        <input
                            autoFocus
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            onKeyDown={onInputKeyDown}
                            placeholder="Cari modul, mis. “Termin” atau “Material”…"
                            className="h-12 w-full border-0 bg-transparent p-0 text-sm outline-none placeholder:text-muted-foreground focus:ring-0"
                        />
                        <kbd className="rounded border border-border px-1.5 text-[10px] text-muted-foreground">Esc</kbd>
                    </div>
                    <div ref={listRef} className="scrollbar-thin max-h-80 overflow-y-auto p-2">
                        {flat.length === 0 ? (
                            <p className="py-8 text-center text-sm text-muted-foreground">Menu tidak ditemukan.</p>
                        ) : (
                            filtered.map((group) => (
                                <div key={group.label} className="mb-1 last:mb-0">
                                    <p className="px-2 pt-2 pb-1 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                                        {group.label}
                                    </p>
                                    {group.items.map((item) => {
                                        runningIndex += 1;
                                        const index = runningIndex;
                                        const Icon = item.icon;
                                        const active = index === activeIndex;

                                        return (
                                            <button
                                                key={item.routeName}
                                                type="button"
                                                data-index={index}
                                                onMouseMove={() => setActiveIndex(index)}
                                                onClick={() => go(item.routeName)}
                                                className={cn(
                                                    'flex w-full items-center gap-2.5 rounded-md px-2 py-2 text-left text-sm',
                                                    active ? 'bg-daiku-yellow-light text-foreground' : 'text-foreground/80',
                                                )}
                                            >
                                                <Icon
                                                    className={cn('size-4', active ? 'text-daiku-yellow-dark' : 'text-muted-foreground')}
                                                    aria-hidden
                                                />
                                                <span className="flex-1">{item.label}</span>
                                                {active && <CornerDownLeft className="size-3.5 text-muted-foreground" aria-hidden />}
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
