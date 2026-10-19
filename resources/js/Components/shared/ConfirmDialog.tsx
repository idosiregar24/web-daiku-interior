import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/Components/ui/dialog';
import { cn } from '@/lib/utils';
import { CircleHelp, type LucideIcon, Trash2 } from 'lucide-react';
import type { ReactNode } from 'react';

interface ConfirmDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: ReactNode;
    confirmLabel: string;
    onConfirm: () => void;
    /** `danger` for deleting/irreversible steps — red icon and button. */
    tone?: 'default' | 'danger';
    icon?: LucideIcon;
    /** The confirmed visit is running — buttons locked. */
    busy?: boolean;
    /** Extra content under the description (a summary, a note). */
    children?: ReactNode;
}

/**
 * The Daiku "are you sure?" pop-up — same look as SuccessDialog (centred,
 * icon in a circle, full-width buttons) — instead of the browser's
 * `confirm()`. The caller runs the visit in `onConfirm` and closes it.
 */
export function ConfirmDialog({
    open,
    onOpenChange,
    title,
    description,
    confirmLabel,
    onConfirm,
    tone = 'default',
    icon,
    busy = false,
    children,
}: ConfirmDialogProps) {
    const Icon = icon ?? (tone === 'danger' ? Trash2 : CircleHelp);

    return (
        <Dialog open={open} onOpenChange={(next) => !busy && onOpenChange(next)}>
            <DialogContent showCloseButton={false} className="flex flex-col items-center gap-3 px-6 py-7 text-center sm:max-w-sm">
                <span
                    className={cn(
                        'flex size-14 items-center justify-center rounded-full motion-safe:animate-in motion-safe:duration-300 motion-safe:zoom-in-50',
                        tone === 'danger' ? 'bg-error/10 text-error-ink' : 'bg-daiku-yellow-light text-daiku-yellow-dark',
                    )}
                >
                    <Icon className="size-7" aria-hidden />
                </span>
                <DialogTitle className="text-base font-semibold">{title}</DialogTitle>
                <DialogDescription className="text-sm text-muted-foreground">{description}</DialogDescription>
                {children}
                <div className="mt-1 flex w-full flex-col-reverse gap-2 sm:flex-row">
                    <Button type="button" variant="outline" className="flex-1" disabled={busy} onClick={() => onOpenChange(false)}>
                        Batal
                    </Button>
                    <Button
                        type="button"
                        variant={tone === 'danger' ? 'destructive' : 'default'}
                        className="flex-1"
                        disabled={busy}
                        onClick={onConfirm}
                        autoFocus
                    >
                        {busy ? 'Memproses…' : confirmLabel}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
