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
import type { Quotation } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

// Mirrors CancelQuotationRequest.
const schema = z.object({
    reason: z.string().trim().min(1, 'Alasan pembatalan wajib diisi').max(1000, 'Alasan maksimal 1000 karakter'),
});

type FormValues = z.infer<typeof schema>;

interface CancelQuotationDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    quotation: Pick<Quotation, 'id'>;
    label: string;
}

/** Sprint 12 — Marketing / CEO drop a RAB that is still running (QuotationService::cancel()). */
export function CancelQuotationDialog({ open, onOpenChange, quotation, label }: CancelQuotationDialogProps) {
    const [processing, setProcessing] = useState(false);
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { reason: '' } });

    useEffect(() => {
        if (open) {
            form.reset({ reason: '' });
        }
    }, [open]);

    function onSubmit(values: FormValues) {
        router.post(route('quotations.cancel', { quotation: quotation.id }), values, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: (errors) => form.setError('reason', { message: Object.values(errors).join(' ') }),
            onSuccess: () => onOpenChange(false),
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Batalkan {label}</DialogTitle>
                    <DialogDescription>RAB yang dibatalkan tidak bisa dilanjutkan; minta RAB baru dari halaman lead bila perlu.</DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="reason"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Alasan pembatalan</FormLabel>
                                    <FormControl>
                                        <Textarea {...field} rows={3} autoFocus placeholder="mis. Klien batal survey, jadwal diundur ke tahun depan." />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    Kembali
                                </Button>
                            </DialogClose>
                            <Button type="submit" variant="destructive" disabled={processing}>
                                Batalkan RAB
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
