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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import type { Lead } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

// CLOSING is excluded — that transition only happens through the
// "Konfirmasi Deal" action (ConfirmDealDialog), which also creates the
// Project. See LeadService::changeStatus()/UpdateLeadStatusRequest.
const STATUS_OPTIONS = ['FOLLOW_UP', 'DEAL_DESAIN', 'LOST'] as const;

const schema = z.object({
    status: z.enum(STATUS_OPTIONS),
    lost_reason: z.string().optional(),
    note: z.string().optional(),
}).refine((data) => data.status !== 'LOST' || !!data.lost_reason?.trim(), {
    message: 'Alasan lost wajib diisi.',
    path: ['lost_reason'],
});

type FormValues = z.infer<typeof schema>;

interface LeadStatusDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    lead: Lead | null;
}

/**
 * Pipeline status change modal (.claude/plan/sprint-02.md Week 3, Ido task
 * 2) — every submit writes a PipelineLog entry server-side
 * (LeadService::changeStatus()).
 */
export function LeadStatusDialog({ open, onOpenChange, lead }: LeadStatusDialogProps) {
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { status: 'FOLLOW_UP', lost_reason: '', note: '' },
    });

    const status = form.watch('status');

    useEffect(() => {
        if (open && lead) {
            form.reset({
                status: lead.status === 'CLOSING' ? 'FOLLOW_UP' : lead.status,
                lost_reason: '',
                note: '',
            });
        }
    }, [open, lead]);

    function onSubmit(values: FormValues) {
        if (!lead) return;

        const onError = (errors: Record<string, string>) => {
            Object.entries(errors).forEach(([field, message]) => {
                form.setError(field as keyof FormValues, { message });
            });
        };

        router.patch(route('crm.leads.updateStatus', { lead: lead.id }), values, {
            onError,
            onSuccess: () => onOpenChange(false),
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Ubah Status Lead</DialogTitle>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="status"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Status Baru</FormLabel>
                                    <Select value={field.value} onValueChange={field.onChange}>
                                        <FormControl>
                                            <SelectTrigger className="w-full">
                                                <SelectValue />
                                            </SelectTrigger>
                                        </FormControl>
                                        <SelectContent>
                                            {STATUS_OPTIONS.map((option) => (
                                                <SelectItem key={option} value={option}>
                                                    {option.replace('_', ' ')}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        {status === 'LOST' && (
                            <FormField
                                control={form.control}
                                name="lost_reason"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Alasan Lost</FormLabel>
                                        <FormControl>
                                            <Textarea {...field} rows={2} autoFocus />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        )}
                        <FormField
                            control={form.control}
                            name="note"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Catatan (opsional)</FormLabel>
                                    <FormControl>
                                        <Textarea {...field} rows={2} />
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
                                Simpan
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
