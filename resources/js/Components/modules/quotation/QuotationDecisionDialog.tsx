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

// Mirrors ClientRejectQuotationRequest.
const schema = z.object({
    note: z.string().trim().min(1, 'Alasan penolakan klien wajib diisi').max(1000, 'Catatan maksimal 1000 karakter'),
});

type FormValues = z.infer<typeof schema>;

/**
 * The only decision left outside the item review (Sprint 12): the client's
 * rejection. CEO / PM decisions are QuotationReviewPanel's.
 */
export type QuotationDecisionGate = 'CLIENT';

interface QuotationDecisionDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    quotation: Pick<Quotation, 'id' | 'version'>;
    role: QuotationDecisionGate;
    /** The client's acceptance is "Konfirmasi Deal" on the lead — only the rejection is recorded here. */
    decision: 'reject';
    /** Shown on CRM pages, where the quotation page's title isn't on screen. */
    clientName?: string;
}

/**
 * PRD §6.2 "SENT TO CLIENT → REJECTED (klien) → DRAFT (revisi)" (Sprint 9):
 * the client turned the offer down and wants a revision. A note is
 * required (server-side enforced by the Form Request + QuotationService);
 * the version is closed into the revision history.
 */
export function QuotationDecisionDialog({ open, onOpenChange, quotation, clientName }: QuotationDecisionDialogProps) {
    const [processing, setProcessing] = useState(false);
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { note: '' } });

    useEffect(() => {
        if (open) {
            form.reset({ note: '' });
        }
    }, [open]);

    function onSubmit(values: FormValues) {
        router.post(route('quotations.clientReject', { quotation: quotation.id }), values, {
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: (errors) => Object.entries(errors).forEach(([field, message]) => form.setError(field as keyof FormValues, { message })),
            onSuccess: () => onOpenChange(false),
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Klien Menolak Penawaran</DialogTitle>
                    <DialogDescription>
                        {clientName && (
                            <>
                                Catat bahwa klien <span className="font-medium text-foreground">{clientName}</span> menolak
                                penawaran ini dan meminta revisi.{' '}
                            </>
                        )}
                        Versi {quotation.version} disimpan di Riwayat Revisi, lalu RAB kembali ke DRAFT sebagai versi{' '}
                        {quotation.version + 1} untuk direvisi Estimator.
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="note"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Alasan penolakan klien</FormLabel>
                                    <FormControl>
                                        <Textarea {...field} rows={3} autoFocus placeholder="mis. Klien minta harga turun 10% dan material diganti HPL." />
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
                            <Button type="submit" variant="destructive" disabled={processing}>
                                Catat Penolakan
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
