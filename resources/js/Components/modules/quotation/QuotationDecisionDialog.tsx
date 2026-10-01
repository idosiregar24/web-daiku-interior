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
import {
    Form,
    FormControl,
    FormField,
    FormItem,
    FormLabel,
    FormMessage,
} from '@/Components/ui/form';
import { Textarea } from '@/Components/ui/textarea';
import type { Quotation } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const schema = z.object({
    note: z.string().max(1000, 'Catatan maksimal 1000 karakter').optional(),
});

type FormValues = z.infer<typeof schema>;

/** Which gate the dialog acts on — QuotationService's ceoDecision()/pmDecision()/clientReject(). */
export type QuotationDecisionGate = 'CEO' | 'PM' | 'CLIENT';

interface QuotationDecisionDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    quotation: Pick<Quotation, 'id' | 'version'>;
    role: QuotationDecisionGate;
    /** CLIENT only ever rejects here — the client's acceptance is "Konfirmasi Deal" on the lead. */
    decision: 'approve' | 'reject';
    /** Shown on the client dialog (CRM pages, where the quotation page's title isn't on screen). */
    clientName?: string;
    /** QuotationService::VALIDITY_DAYS — mentioned when PM's approval sends the offer. */
    validityDays?: number;
}

const ROUTE_NAME: Record<QuotationDecisionGate, string> = {
    CEO: 'quotations.ceoDecision',
    PM: 'quotations.pmDecision',
    CLIENT: 'quotations.clientReject',
};

/**
 * "Quotation approval UI: tombol approve/reject + catatan"
 * (.claude/plan/sprint-03.md Week 5), extended in Sprint 9 with the
 * client's rejection (PRD §6.2 "SENT TO CLIENT → REJECTED (klien) → DRAFT
 * (revisi)"). Every reject requires a note (server-side enforced by the
 * Form Request + QuotationService, mirrored here) and closes the current
 * version into the revision history.
 */
export function QuotationDecisionDialog({
    open,
    onOpenChange,
    quotation,
    role,
    decision,
    clientName,
    validityDays,
}: QuotationDecisionDialogProps) {
    const isReject = decision === 'reject';
    const isClient = role === 'CLIENT';
    const [processing, setProcessing] = useState(false);

    const form = useForm<FormValues>({
        resolver: zodResolver(
            isReject
                ? schema.extend({
                      note: z
                          .string()
                          .trim()
                          .min(1, isClient ? 'Alasan penolakan klien wajib diisi' : 'Catatan alasan reject wajib diisi')
                          .max(1000, 'Catatan maksimal 1000 karakter'),
                  })
                : schema,
        ),
        defaultValues: { note: '' },
    });

    useEffect(() => {
        if (open) {
            form.reset({ note: '' });
        }
    }, [open]);

    function onSubmit(values: FormValues) {
        const onError = (errors: Record<string, string>) => {
            Object.entries(errors).forEach(([field, message]) => {
                form.setError(field as keyof FormValues, { message });
            });
        };

        const payload = isClient ? { note: values.note } : { decision, note: values.note || null };

        router.post(route(ROUTE_NAME[role], { quotation: quotation.id }), payload, {
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError,
            onSuccess: () => onOpenChange(false),
        });
    }

    const title = isClient ? 'Klien Menolak Penawaran' : `${isReject ? 'Tolak Quotation' : 'Setujui Quotation'} (${role})`;

    let description: string;
    if (isReject) {
        description =
            `Versi ${quotation.version} disimpan di Riwayat Revisi, lalu quotation kembali ke DRAFT sebagai ` +
            `versi ${quotation.version + 1} untuk direvisi Estimator.`;
    } else if (role === 'CEO') {
        description = 'Quotation akan lanjut ke review PM.';
    } else {
        description = `Quotation akan ditandai SENT_TO_CLIENT${validityDays ? ` dan berlaku ${validityDays} hari sejak hari ini` : ''}.`;
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>
                        {isClient && clientName && (
                            <>
                                Catat bahwa klien <span className="font-medium text-foreground">{clientName}</span> menolak
                                penawaran ini dan meminta revisi.{' '}
                            </>
                        )}
                        {description}
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="note"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>
                                        {isClient ? 'Alasan penolakan klien' : `Catatan${isReject ? '' : ' (opsional)'}`}
                                    </FormLabel>
                                    <FormControl>
                                        <Textarea
                                            {...field}
                                            rows={3}
                                            autoFocus
                                            placeholder={isClient ? 'mis. Klien minta harga turun 10% dan material diganti HPL.' : undefined}
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
                            <Button type="submit" variant={isReject ? 'destructive' : 'default'} disabled={processing}>
                                {isClient ? 'Catat Penolakan' : isReject ? 'Tolak' : 'Setujui'}
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
