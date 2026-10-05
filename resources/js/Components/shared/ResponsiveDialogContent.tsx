import { DialogContent } from '@/Components/ui/dialog';
import { SheetContent } from '@/Components/ui/sheet';
import { BELOW_MD, useMediaQuery } from '@/hooks/useMediaQuery';
import type { ComponentProps } from 'react';

/**
 * Drop-in for `<DialogContent>` inside a shadcn `<Dialog>` (Sprint 13 P2/P3):
 * a centred dialog from `md` up, a bottom panel on a phone — easier to
 * reach with a thumb, and the footer buttons stack full width. Sheet and
 * Dialog share Radix's Dialog primitive, so DialogHeader/Title/Footer/
 * Close inside work unchanged. `className` sizes the desktop dialog only.
 */
export function ResponsiveDialogContent({ className, children, ...props }: ComponentProps<typeof DialogContent>) {
    const compact = useMediaQuery(BELOW_MD);

    if (compact) {
        return (
            <SheetContent
                side="bottom"
                {...props}
                className="max-h-[92svh] gap-4 overflow-y-auto rounded-t-2xl p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]"
            >
                {children}
            </SheetContent>
        );
    }

    return (
        <DialogContent className={className} {...props}>
            {children}
        </DialogContent>
    );
}
