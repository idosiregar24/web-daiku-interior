import { Button } from '@/Components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { Link } from '@inertiajs/react';
import { type LucideIcon, Plus } from 'lucide-react';

export interface QuickCreateItem {
    label: string;
    icon: LucideIcon;
    /** The page whose add dialog opens (`?create=1`, see useCreateParam). */
    routeName: string;
}

/**
 * Sprint 13 #6 — topbar "+ Buat": add something without hunting for its
 * menu first. Each entry visits its page with `?create=1`; the page opens
 * its own add dialog (the server still gates the save). The list comes
 * role-filtered from AppLayout (QUICK_CREATE); nothing left = no button.
 */
export function QuickCreateMenu({ items }: { items: QuickCreateItem[] }) {
    if (items.length === 0) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button size="sm" className="h-8 w-8 px-0 md:w-auto md:px-2.5" aria-label="Buat baru">
                    <Plus className="size-4" />
                    <span className="hidden md:inline">Buat</span>
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
                <DropdownMenuLabel className="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                    Buat baru
                </DropdownMenuLabel>
                {items.map((item) => (
                    <DropdownMenuItem key={item.routeName} asChild>
                        <Link href={route(item.routeName, { create: 1 })}>
                            <item.icon className="size-4 text-muted-foreground" />
                            {item.label}
                        </Link>
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
