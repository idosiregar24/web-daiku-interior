import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/Components/ui/dialog';
import { useEffect } from 'react';

/** How long the pop-up stays before closing itself. */
const AUTO_CLOSE_MS = 3000;

interface SuccessDialogProps {
    /** The flash message; `null` = closed. */
    message: string | null;
    onClose: () => void;
}

/**
 * Sprint 23 K1 — "berhasil" as a pop-up in the middle of the screen
 * instead of a small toast in the corner. Fed by `useFlashToasts` (every
 * controller's `->with('success', …)`), mounted once in AppLayout. Errors
 * stay red toasts so they never cover the form being corrected.
 */
export function SuccessDialog({ message, onClose }: SuccessDialogProps) {
    useEffect(() => {
        if (!message) {
            return;
        }

        const timer = window.setTimeout(onClose, AUTO_CLOSE_MS);

        return () => window.clearTimeout(timer);
    }, [message]);

    return (
        <Dialog open={message !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent showCloseButton={false} className="flex flex-col items-center gap-3 px-6 py-7 text-center sm:max-w-xs">
                <SuccessCheck />
                <DialogTitle className="text-base font-semibold">Berhasil</DialogTitle>
                <DialogDescription className="text-sm text-muted-foreground">{message}</DialogDescription>
                <Button type="button" className="mt-1 w-full" onClick={onClose} autoFocus>
                    OK
                </Button>
            </DialogContent>
        </Dialog>
    );
}

/** A tick drawn in — static when the user prefers reduced motion. */
function SuccessCheck() {
    return (
        <span className="flex size-14 items-center justify-center rounded-full bg-success/10 text-success-ink motion-safe:animate-in motion-safe:zoom-in-50 motion-safe:duration-300">
            <svg viewBox="0 0 24 24" className="size-8" fill="none" stroke="currentColor" strokeWidth={2.5} strokeLinecap="round" strokeLinejoin="round" aria-hidden>
                <path d="M5 12.5l4.5 4.5L19 7.5" pathLength={1} className="success-check-path" />
            </svg>
        </span>
    );
}
