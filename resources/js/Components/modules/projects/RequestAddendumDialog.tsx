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

// Mirrors RequestAddendumRequest.
const schema = z.object({
    note: z.string().trim().min(5, 'Catatan minimal 5 karakter.').max(2000, 'Catatan maksimal 2000 karakter.'),
});

type FormValues = z.infer<typeof schema>;

interface RequestAddendumDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    projectId: number;
    projectName: string;
}

/**
 * Sprint 12 decision #29 — "Minta RAB Tambahan" (pekerjaan tambah): the
 * Estimator builds it, PM → CEO review it, the client approves it on its
 * link, and it adds to this project (contract value, termins, items).
 */
export function RequestAddendumDialog({ open, onOpenChange, projectId, projectName }: RequestAddendumDialogProps) {
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { note: '' } });

    useEffect(() => {
        if (open) form.reset({ note: '' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    function onSubmit(values: FormValues) {
        router.post(route('projects.addenda.store', { project: projectId }), values, {
            onError: (errors) => form.setError('note', { message: errors.note }),
            onSuccess: () => onOpenChange(false),
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Minta RAB Tambahan</DialogTitle>
                    <DialogDescription>
                        Pekerjaan tambah untuk proyek <span className="font-medium text-foreground">{projectName}</span>. Estimator menyusun
                        RAB-nya, lalu direview PM dan CEO sebelum dikirim ke klien.
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="note"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Pekerjaan yang ditambah</FormLabel>
                                    <FormControl>
                                        <Textarea {...field} rows={4} maxLength={2000} placeholder="mis. Plafon membran 8,84 m² dan plafon topian meja bar." />
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
                                Minta RAB
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
