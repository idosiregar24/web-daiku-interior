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
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Textarea } from '@/Components/ui/textarea';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

// Mirrors ReturnPerformanceReviewRequest (note only matters when returning).
const schema = z.object({ note: z.string().max(1000, 'Catatan maksimal 1000 karakter.') });
const returnSchema = z.object({
    note: z.string().trim().min(1, 'Catatan pengembalian wajib diisi.').max(1000, 'Catatan maksimal 1000 karakter.'),
});

type FormValues = z.infer<typeof schema>;

interface ReviewDecisionDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reviewId: number;
    employeeName: string;
    periodLabel: string;
    decision: 'approve' | 'return';
}

/**
 * SDM (Sprint 10, §3.4) — the CEO approves a submitted review (it is then
 * locked and shown to the employee) or returns it to HR with a required
 * note.
 */
export function ReviewDecisionDialog({ open, onOpenChange, reviewId, employeeName, periodLabel, decision }: ReviewDecisionDialogProps) {
    const isReturn = decision === 'return';
    const [processing, setProcessing] = useState(false);
    const form = useForm<FormValues>({ resolver: zodResolver(isReturn ? returnSchema : schema), defaultValues: { note: '' } });

    useEffect(() => {
        if (open) form.reset({ note: '' });
    }, [open]);

    function onSubmit(values: FormValues) {
        router.post(
            route(isReturn ? 'hr.reviews.return' : 'hr.reviews.approve', { performance_review: reviewId }),
            isReturn ? { note: values.note } : {},
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (errors: Record<string, string>) =>
                    Object.entries(errors).forEach(([field, message]) => form.setError(field === 'note' ? 'note' : 'root', { message })),
                onSuccess: () => onOpenChange(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{isReturn ? 'Kembalikan Evaluasi' : 'Setujui Evaluasi'}</DialogTitle>
                    <DialogDescription>
                        {isReturn
                            ? `Evaluasi ${periodLabel} untuk ${employeeName} kembali ke SDM sebagai draf untuk diperbaiki.`
                            : `Evaluasi ${periodLabel} untuk ${employeeName} dikunci dan bisa dibaca karyawan. Rekomendasi tidak mengubah gaji secara otomatis.`}
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        {isReturn && (
                            <FormField
                                control={form.control}
                                name="note"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Catatan untuk SDM</FormLabel>
                                        <FormControl>
                                            <Textarea {...field} rows={3} autoFocus placeholder="mis. Nilai inisiatif perlu ditinjau ulang." />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        )}
                        {form.formState.errors.root && <p className="text-sm text-error-ink">{form.formState.errors.root.message}</p>}
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    Batal
                                </Button>
                            </DialogClose>
                            <Button type="submit" variant={isReturn ? 'destructive' : 'default'} disabled={processing}>
                                {isReturn ? 'Kembalikan' : 'Setujui'}
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
