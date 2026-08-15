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
import type { Design } from '@/types';
import { router } from '@inertiajs/react';
import { useState } from 'react';

interface ClientAccDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    design: Design;
    clientName: string;
}

/**
 * PRD §4.2 "Client ACC: Konfirmasi ACC desain → trigger ke tahap Gambar
 * RAB → Penawaran" (.claude/plan/sprint-02.md Week 4, Ido task 2). No form
 * fields — a plain yes/no confirm, since PRD doesn't describe any input
 * beyond the confirmation itself. Backend redirects into the new
 * Quotation on success (DesignController::clientAcc()).
 */
export function ClientAccDialog({ open, onOpenChange, design, clientName }: ClientAccDialogProps) {
    const [submitting, setSubmitting] = useState(false);

    function confirm() {
        setSubmitting(true);
        router.post(
            route('design.clientAcc', { design: design.id }),
            {},
            {
                onFinish: () => setSubmitting(false),
                onSuccess: () => onOpenChange(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Konfirmasi Client ACC</DialogTitle>
                    <DialogDescription>
                        Konfirmasi bahwa klien <span className="font-medium text-daiku-dark">{clientName}</span>{' '}
                        telah menyetujui desain ini. Status akan pindah ke <span className="font-medium">GAMBAR_RAB</span> dan
                        Quotation baru akan langsung dibuka untuk Estimator.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button type="button" variant="outline">
                            Batal
                        </Button>
                    </DialogClose>
                    <Button onClick={confirm} disabled={submitting}>
                        Konfirmasi ACC
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
