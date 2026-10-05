import { cn } from '@/lib/utils';
import { Link, usePage } from '@inertiajs/react';
import { CalendarCheck, Clock, type LucideIcon, ListChecks, Menu } from 'lucide-react';

interface BottomNavItem {
    label: string;
    icon: LucideIcon;
    href: () => string;
    /** Is this the button of the page on screen? */
    isActive: () => boolean;
}

/**
 * Sprint 13 H1 — the Tukang's phone navigation: 4 big buttons fixed at the
 * bottom (below `lg`), instead of the sidebar behind a hamburger. "Hari
 * Ini" is the Tukang's first screen (today.index, Sub 09); "Lainnya"
 * covers every other page a Tukang may open.
 */
const ITEMS: BottomNavItem[] = [
    {
        label: 'Hari Ini',
        icon: CalendarCheck,
        href: () => route('today.index'),
        isActive: () => route().current('today.*'),
    },
    {
        label: 'Tugas',
        icon: ListChecks,
        href: () => route('tasks.index'),
        isActive: () => route().current('tasks.*') || route().current('daily-forms.*'),
    },
    {
        label: 'Lembur',
        icon: Clock,
        href: () => route('overtime.index'),
        isActive: () => route().current('overtime.*'),
    },
    {
        label: 'Lainnya',
        icon: Menu,
        href: () => route('more.index'),
        isActive: () =>
            ['more.*', 'penalties.*', 'logistics.material-requests.*', 'projects.*', 'profile.*'].some((pattern) => route().current(pattern)),
    },
];

export function BottomNav() {
    // Re-render on every visit so the active button follows the page.
    usePage();

    return (
        <nav
            aria-label="Navigasi utama"
            className="fixed inset-x-0 bottom-0 z-30 border-t border-border bg-background pb-[env(safe-area-inset-bottom)] lg:hidden"
        >
            <ul className="grid h-16 grid-cols-4">
                {ITEMS.map((item) => {
                    const active = item.isActive();

                    return (
                        <li key={item.label}>
                            <Link
                                href={item.href()}
                                aria-current={active ? 'page' : undefined}
                                className={cn(
                                    'flex h-full min-h-12 flex-col items-center justify-center gap-1 text-[11px]',
                                    active ? 'font-semibold text-foreground' : 'text-muted-foreground',
                                )}
                            >
                                <span
                                    className={cn(
                                        'flex h-7 w-12 items-center justify-center rounded-full',
                                        active && 'bg-daiku-yellow-light',
                                    )}
                                >
                                    <item.icon className={cn('size-5', active && 'text-daiku-yellow-dark')} strokeWidth={active ? 2.4 : 2} />
                                </span>
                                {item.label}
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
