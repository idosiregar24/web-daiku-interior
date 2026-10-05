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
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

// Mirrors RequestDesignRevisionRequest.
const schema = z.object({
    note: z.string().trim().min(5, 'Catatan revisi minimal 5 karakter.').max(2000, 'Catatan revisi maksimal 2000 karakter.'),
});

type FormValues = z.infer<typeof schema>;

interface DesignRevisionDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    designId: number;
    clientName: string;
    /** The number this revision will get (revision_count + 1). */
    nextNumber: number;
}

/** Sprint 12 decision #17 — Marketing's "Minta Revisi": what the client wants changed, counted per design. */
export function DesignRevisionDialog({ open, onOpenChange, designId, clientName, nextNumber }: DesignRevisionDialogProps) {
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { note: '' } });

    useEffect(() => {
        if (open) form.reset({ note: '' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    function onSubmit(values: FormValues) {
        router.post(route('design.requestRevision', { design: designId }), values, {
            preserveScroll: true,
            onError: (errors) => form.setError('note', { message: errors.note ?? errors.status }),
            onSuccess: () => onOpenChange(false),
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Minta Revisi #{nextNumber}</DialogTitle>
                    <DialogDescription>
                        Tulis apa yang ingin diubah klien <span className="font-medium text-foreground">{clientName}</span>. Arsitek akan
                        menerima catatan ini, dan jumlah revisi tercatat.
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="note"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Catatan Revisi</FormLabel>
                                    <FormControl>
                                        <Textarea {...field} rows={4} maxLength={2000} placeholder="mis. Warna kitchen set diganti putih doff…" />
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
                            <Button type="submit" disabled={form.formState.isSubmitting}>
                                Kirim Revisi
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
