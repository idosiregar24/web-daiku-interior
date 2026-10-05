import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { router } from '@inertiajs/react';
import { type ReactNode, useState } from 'react';

interface DesignConfirmDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: ReactNode;
    confirmLabel: string;
    /** The POST endpoint (a `route(...)` URL). */
    action: string;
}

/**
 * Sprint 12 decision #17 — Marketing's yes/no steps on a design ("Kirim ke
 * Klien", "Desain Disetujui Klien"): no fields, the server checks the
 * status (DesignService) and flashes the outcome.
 */
export function DesignConfirmDialog({ open, onOpenChange, title, description, confirmLabel, action }: DesignConfirmDialogProps) {
    const [submitting, setSubmitting] = useState(false);

    function confirm() {
        setSubmitting(true);
        router.post(action, {}, {
            preserveScroll: true,
            onFinish: () => setSubmitting(false),
            onSuccess: () => onOpenChange(false),
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button type="button" variant="outline">
                            Batal
                        </Button>
                    </DialogClose>
                    <Button onClick={confirm} disabled={submitting}>
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
