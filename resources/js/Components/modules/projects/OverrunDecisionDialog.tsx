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
import { formatRupiah } from '@/lib/format';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

export type OverrunDecision = 'approve' | 'reject';

const schema = z.object({ note: z.string().max(1000, 'Catatan maksimal 1000 karakter.') });

type FormValues = z.infer<typeof schema>;

interface OverrunDecisionDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    decision: OverrunDecision;
    request: { id: number; item: string | null; post?: string; amount_over: number; reason: string };
}

/** Sprint 12 decision #28 — the CEO approves (the held realisation is recorded) or rejects (note required). */
export function OverrunDecisionDialog({ open, onOpenChange, decision, request }: OverrunDecisionDialogProps) {
    const approve = decision === 'approve';
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { note: '' } });

    useEffect(() => {
        if (open) form.reset({ note: '' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, request.id, decision]);

    function onSubmit(values: FormValues) {
        // Mirrors DecideOverrunRequest: a rejection needs a note.
        if (!approve && values.note.trim() === '') {
            form.setError('note', { message: 'Catatan penolakan wajib diisi.' });
            return;
        }

        router.post(
            route('projects.budget.overruns.decide', { overrun: request.id }),
            { decision, note: values.note.trim() || null },
            {
                preserveScroll: true,
                onError: (errors) => form.setError('note', { message: errors.note ?? errors.decision ?? errors.post }),
                onSuccess: () => onOpenChange(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{approve ? 'Setujui Overrun' : 'Tolak Overrun'}</DialogTitle>
                    <DialogDescription>
                        {request.item}
                        {request.post ? ` · pos ${request.post}` : ''} — melebihi anggaran {formatRupiah(request.amount_over)}.
                        Alasan PM: {request.reason}
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="note"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>{approve ? 'Catatan (opsional)' : 'Catatan penolakan'}</FormLabel>
                                    <FormControl>
                                        <Textarea rows={3} maxLength={1000} {...field} />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    Batal
                                </Button>
                            </DialogClose>
                            <Button type="submit" variant={approve ? 'default' : 'destructive'} disabled={form.formState.isSubmitting}>
                                {approve ? 'Setujui Overrun' : 'Tolak Overrun'}
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
