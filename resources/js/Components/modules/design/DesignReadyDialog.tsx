import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogClose, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Textarea } from '@/Components/ui/textarea';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

// Mirrors MarkDesignReadyRequest.
const schema = z.object({
    note: z.string().trim().max(500, 'Catatan untuk Marketing maksimal 500 karakter.'),
});

type FormValues = z.infer<typeof schema>;

interface DesignReadyDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    designId: number;
    clientName: string;
    /** The revision being handed over, 0 for the first design. */
    revisionNumber: number;
    linkCount: number;
}

/**
 * Sprint 22 — the architect hands the finished design (or revision) to
 * Marketing, who is notified and sends it to the client. The architect
 * never contacts the client (Sprint 12 decision #17).
 */
export function DesignReadyDialog({ open, onOpenChange, designId, clientName, revisionNumber, linkCount }: DesignReadyDialogProps) {
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { note: '' } });

    useEffect(() => {
        if (open) form.reset({ note: '' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    function onSubmit(values: FormValues) {
        router.post(route('design.markReady', { design: designId }), { note: values.note || null }, {
            preserveScroll: true,
            onError: (errors) => form.setError('note', { message: errors.note ?? errors.status }),
            onSuccess: () => onOpenChange(false),
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <ResponsiveDialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{revisionNumber > 0 ? `Revisi #${revisionNumber} Siap Dikirim` : 'Desain Siap Dikirim'}</DialogTitle>
                    <DialogDescription>
                        {linkCount} link desain <span className="font-medium text-foreground">{clientName}</span> diserahkan ke Marketing.
                        Marketing mendapat notifikasi lalu mengirimkannya ke klien.
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="note"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Catatan untuk Marketing</FormLabel>
                                    <FormControl>
                                        <Textarea
                                            {...field}
                                            rows={3}
                                            maxLength={500}
                                            placeholder="mis. Warna bar sudah diganti jati gelap, kursi outdoor ada di halaman 4."
                                        />
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
                                Serahkan ke Marketing
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </ResponsiveDialogContent>
        </Dialog>
    );
}
