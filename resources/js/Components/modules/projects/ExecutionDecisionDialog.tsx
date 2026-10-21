import { MoneyInput } from '@/Components/shared/MoneyInput';
import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogClose, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Textarea } from '@/Components/ui/textarea';
import { formatRupiah } from '@/lib/format';
import { projectVisit, RELOAD } from '@/lib/projectReload';
import { cn } from '@/lib/utils';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

// Mirrors DecideExecutionRequest (note required when rejecting).
const schema = z
    .object({
        decision: z.enum(['approve', 'reject']),
        amount: z.string().optional(),
        note: z.string().max(1000, 'Catatan maksimal 1000 karakter.').optional(),
    })
    .refine((values) => values.decision === 'approve' || (values.note ?? '').trim().length > 0, {
        message: 'Alasan penolakan wajib diisi.',
        path: ['note'],
    });

type FormValues = z.infer<typeof schema>;

export interface ExecutionDecisionTarget {
    /** Route name + params the decision posts to. */
    routeName: 'supervisor-payments.decide' | 'weekly-reports.decide';
    params: Record<string, number>;
    title: string;
    description: string;
    /** Default amount (the request's, or empty for a weekly report). */
    amount: number | null;
    /** "Nominal disetujui" vs "Bayar jasa pengawas minggu ini". */
    amountLabel: string;
    amountRequired: boolean;
    /** What is left of the fee — shown as the ceiling. */
    remaining: number | null;
}

/**
 * Sprint 24 D4/D10 — the PM's decision on a Pengawas payment request or a
 * weekly report: approve (with the amount — editable, never above what is
 * left of the fee; the server checks it) or reject with a reason.
 */
export function ExecutionDecisionDialog({ target, onOpenChange }: { target: ExecutionDecisionTarget | null; onOpenChange: (open: boolean) => void }) {
    const [saving, setSaving] = useState(false);
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { decision: 'approve', amount: '', note: '' } });
    const decision = form.watch('decision');

    useEffect(() => {
        if (target) {
            form.reset({ decision: 'approve', amount: target.amount !== null ? String(target.amount) : '', note: '' });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [target]);

    function onSubmit(values: FormValues) {
        if (!target) return;

        if (values.decision === 'approve' && target.amountRequired && !(Number(values.amount) > 0)) {
            form.setError('amount', { message: 'Nominal wajib diisi.' });
            return;
        }

        router.post(
            route(target.routeName, target.params),
            { decision: values.decision, amount: values.amount ? Number(values.amount) : null, note: values.note || null },
            {
                // A decision (DP approved = Start Produksi) — keeps the pop-up; reloads the Eksekusi side only.
                ...projectVisit(RELOAD.supervision),
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => onOpenChange(false),
                onError: (errors) => {
                    Object.entries(errors).forEach(([key, message]) =>
                        form.setError((['amount', 'note', 'decision'].includes(key) ? key : 'root') as keyof FormValues | 'root', { message }),
                    );
                },
            },
        );
    }

    return (
        <Dialog open={target !== null} onOpenChange={onOpenChange}>
            <ResponsiveDialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{target?.title}</DialogTitle>
                    <DialogDescription>{target?.description}</DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <div role="radiogroup" aria-label="Keputusan" className="grid grid-cols-2 gap-2">
                            {(['approve', 'reject'] as const).map((value) => (
                                <button
                                    key={value}
                                    type="button"
                                    role="radio"
                                    aria-checked={decision === value}
                                    onClick={() => form.setValue('decision', value)}
                                    className={cn(
                                        'min-h-11 rounded-lg px-3 text-sm font-medium ring-1 transition-colors',
                                        decision === value
                                            ? value === 'approve'
                                                ? 'bg-success/10 text-success-ink ring-success/40'
                                                : 'bg-error/10 text-error-ink ring-error/40'
                                            : 'bg-background text-foreground ring-border',
                                    )}
                                >
                                    {value === 'approve' ? 'Setujui' : 'Tolak'}
                                </button>
                            ))}
                        </div>

                        {decision === 'approve' && (
                            <FormField
                                control={form.control}
                                name="amount"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required={target?.amountRequired}>{target?.amountLabel}</FormLabel>
                                        <FormControl>
                                            <MoneyInput value={field.value} onChange={field.onChange} placeholder="0" />
                                        </FormControl>
                                        {target?.remaining !== null && target?.remaining !== undefined && (
                                            <p className="text-xs text-muted-foreground">Sisa upah tukang {formatRupiah(target.remaining)}.</p>
                                        )}
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
                                    <FormLabel required={decision === 'reject'}>{decision === 'reject' ? 'Alasan penolakan' : 'Catatan'}</FormLabel>
                                    <FormControl>
                                        <Textarea {...field} rows={3} maxLength={1000} />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        {form.formState.errors.root?.message && <p className="text-sm text-error-ink">{form.formState.errors.root.message}</p>}

                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    Batal
                                </Button>
                            </DialogClose>
                            <Button type="submit" variant={decision === 'reject' ? 'destructive' : 'default'} disabled={saving}>
                                {saving && <Loader2 className="size-4 animate-spin" />}
                                {decision === 'reject' ? 'Tolak' : 'Setujui'}
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </ResponsiveDialogContent>
        </Dialog>
    );
}
