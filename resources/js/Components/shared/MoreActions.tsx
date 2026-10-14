import { Button } from '@/Components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/Components/ui/dropdown-menu';
import { MoreHorizontal } from 'lucide-react';
import type { ReactNode } from 'react';

interface MoreActionsProps {
    /** `<DropdownMenuItem>`s — the page's less frequent actions. */
    children: ReactNode;
    label?: string;
}

/**
 * Sprint 23 K4b — the "Lainnya" menu at the end of a `PageHeader` action
 * row, for secondary actions that would crowd it (Unduh PDF, Batalkan…).
 * An item that opens a dialog sets the page's dialog state in `onSelect`
 * (the dialog itself is rendered outside the menu).
 */
export function MoreActions({ children, label = 'Lainnya' }: MoreActionsProps) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button type="button" variant="outline">
                    <MoreHorizontal className="size-4" aria-hidden />
                    {label}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-48">
                {children}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
