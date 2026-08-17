import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
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
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const schema = z.object({
    note: z.string().optional(),
});

type FormValues = z.infer<typeof schema>;

interface QuotationDecisionDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    quotation: Quotation;
    /** Which of the two sequential approval gates this dialog is acting on — QuotationService's ceoDecision()/pmDecision(). */
    role: 'CEO' | 'PM';
    decision: 'approve' | 'reject';
}

/**
 * "Quotation approval UI: tombol approve/reject + catatan"
 * (.claude/plan/sprint-03.md Week 5). Reject requires a note (server-side
 * enforced by QuotationService::recordDecision(), mirrored here).
 */
export function QuotationDecisionDialog({ open, onOpenChange, quotation, role, decision }: QuotationDecisionDialogProps) {
    const isReject = decision === 'reject';

    const form = useForm<FormValues>({
        resolver: zodResolver(
            isReject ? schema.extend({ note: z.string().min(1, 'Catatan alasan reject wajib diisi') }) : schema,
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

        const routeName = role === 'CEO' ? 'quotations.ceoDecision' : 'quotations.pmDecision';

        router.post(
            route(routeName, { quotation: quotation.id }),
            { decision, note: values.note || null },
            { onError, onSuccess: () => onOpenChange(false) },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {isReject ? 'Tolak Quotation' : 'Setujui Quotation'} ({role})
                    </DialogTitle>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <p className="text-sm text-daiku-muted">
                            {isReject
                                ? 'Quotation akan dikembalikan ke status DRAFT untuk direvisi Estimator.'
                                : role === 'CEO'
                                  ? 'Quotation akan lanjut ke review PM.'
                                  : 'Quotation akan ditandai SENT_TO_CLIENT.'}
                        </p>
                        <FormField
                            control={form.control}
                            name="note"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Catatan {isReject ? '' : '(opsional)'}</FormLabel>
                                    <FormControl>
                                        <Textarea {...field} rows={3} autoFocus />
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
                            <Button type="submit" variant={isReject ? 'destructive' : 'default'} disabled={form.formState.isSubmitting}>
                                {isReject ? 'Tolak' : 'Setujui'}
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
