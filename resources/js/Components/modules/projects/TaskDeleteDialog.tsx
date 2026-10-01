import { Notice } from '@/Components/shared/Notice';
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
import type { Task } from '@/types';
import { router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';

interface TaskDeleteDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    task: Pick<Task, 'id' | 'title'> | null;
}

/**
 * "Hapus Task" confirmation (Sprint 9) — PM only (TaskPolicy::delete()).
 * The server refuses a task that already has history (daily form,
 * overtime, penalty, wage payment) or is DONE; that message is shown here
 * instead of closing the dialog.
 */
export function TaskDeleteDialog({ open, onOpenChange, task }: TaskDeleteDialogProps) {
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (open) {
            setError(null);
        }
    }, [open, task]);

    function onConfirm() {
        if (!task) return;

        router.delete(route('tasks.destroy', { task: task.id }), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onOpenChange(false),
            onError: (errors) => setError(Object.values(errors)[0] ?? 'Task gagal dihapus.'),
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Hapus Task</DialogTitle>
                    <DialogDescription>
                        Hapus task <span className="font-medium text-foreground">"{task?.title}"</span>? Tindakan ini tidak
                        bisa dibatalkan.
                    </DialogDescription>
                </DialogHeader>
                <p className="text-sm text-daiku-muted">
                    Hanya task yang belum punya riwayat — form harian, lembur, penalti, atau pembayaran upah — dan belum DONE
                    yang bisa dihapus. Task lain cukup diubah.
                </p>
                {error && <Notice tone="error">{error}</Notice>}
                <DialogFooter>
                    <DialogClose asChild>
                        <Button type="button" variant="outline">
                            Batal
                        </Button>
                    </DialogClose>
                    <Button type="button" variant="destructive" disabled={processing || !task} onClick={onConfirm}>
                        <Trash2 className="size-4" />
                        Hapus Task
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
