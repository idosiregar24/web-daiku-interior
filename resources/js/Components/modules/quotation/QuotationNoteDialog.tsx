import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogClose, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Textarea } from '@/Components/ui/textarea';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { type ReactNode, useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

/** App\Models\QuotationNote::MAX_LENGTH. */
const MAX_LENGTH = 1000;

// Mirrors StoreQuotationNoteRequest / ForwardClientRejectionRequest.
const schema = z.object({
    note: z.string().trim().min(1, 'Catatan wajib diisi').max(MAX_LENGTH, `Catatan maksimal ${MAX_LENGTH} karakter`),
});

type FormValues = z.infer<typeof schema>;

interface QuotationNoteDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** POST target — `quotations.notes.store` (CEO note) or `quotations.forwardToEstimator` (Marketing). */
    action: string;
    title: string;
    description: ReactNode;
    label: string;
    placeholder: string;
    submitLabel: string;
}

/**
 * Sprint 23 — one note, one action: the CEO's information-only note (K9)
 * and Marketing's "Teruskan ke Estimator" after the client's "Tidak
 * Setuju" (K11). The note is required in both.
 */
export function QuotationNoteDialog({ open, onOpenChange, action, title, description, label, placeholder, submitLabel }: QuotationNoteDialogProps) {
    const [processing, setProcessing] = useState(false);
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { note: '' } });

    useEffect(() => {
        if (open) {
            form.reset({ note: '' });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    function onSubmit(values: FormValues) {
        router.post(action, values, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: (errors) => form.setError('note', { message: Object.values(errors).join(' ') }),
            onSuccess: () => onOpenChange(false),
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <ResponsiveDialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="note"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>{label}</FormLabel>
                                    <FormControl>
                                        <Textarea {...field} rows={4} autoFocus placeholder={placeholder} maxLength={MAX_LENGTH} />
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
                            <Button type="submit" disabled={processing}>
                                {submitLabel}
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </ResponsiveDialogContent>
        </Dialog>
    );
}
