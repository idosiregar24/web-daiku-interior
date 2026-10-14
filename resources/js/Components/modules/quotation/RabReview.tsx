import { Button } from '@/Components/ui/button';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { cn } from '@/lib/utils';
import type { Quotation } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { Check, Lock, X } from 'lucide-react';
import { useMemo } from 'react';
import { type UseFormReturn, useForm, useWatch } from 'react-hook-form';
import { z } from 'zod';

type Verdict = '' | 'OK' | 'SALAH';

/** Sprint 23 K6–K7 — what RabBuilder needs to show the review inside the RAB table. */
export interface RabReviewState {
    /** The viewer is PM / Asisten PM and it's their turn (QuotationService::reviewStage()). */
    canDecide: boolean;
    /** Item id → the version whose ✔ it carries (QuotationService::carriedMarks()) — locked. */
    carried: Record<number, number>;
}

const markSchema = z.object({
    verdict: z.enum(['', 'OK', 'SALAH']),
    note: z.string().max(500, 'Catatan item maksimal 500 karakter'),
});

// Mirrors ReviewQuotationRequest + QuotationService::review(): every item not
// carried gets ✔/✘, a ✘ needs a note, approve needs no ✘, return needs a ✘ or a note.
const schema = z
    .object({
        decision: z.enum(['approve', 'return']),
        note: z.string().max(1000, 'Catatan maksimal 1000 karakter'),
        marks: z.record(z.string(), markSchema),
    })
    .superRefine((values, ctx) => {
        const marks = Object.entries(values.marks);

        marks.forEach(([id, mark]) => {
            if (mark.verdict === '') {
                ctx.addIssue({ code: 'custom', path: ['marks', id, 'verdict'], message: 'Tandai ✔ atau ✘' });
            }
            if (mark.verdict === 'SALAH' && !mark.note.trim()) {
                ctx.addIssue({ code: 'custom', path: ['marks', id, 'note'], message: 'Catatan wajib untuk item ✘' });
            }
        });

        const wrong = marks.filter(([, mark]) => mark.verdict === 'SALAH').length;

        if (values.decision === 'approve' && wrong > 0) {
            ctx.addIssue({ code: 'custom', path: ['note'], message: 'RAB dengan item ✘ tidak bisa disetujui — kembalikan ke Estimator.' });
        }
        if (values.decision === 'return' && wrong === 0 && !values.note.trim()) {
            ctx.addIssue({ code: 'custom', path: ['note'], message: 'Tandai item yang salah (✘) atau tulis catatan alasan RAB dikembalikan.' });
        }
    });

export type RabReviewValues = z.infer<typeof schema>;
export type RabReviewForm = UseFormReturn<RabReviewValues>;

/**
 * Sprint 23 K6 — the PM / Asisten PM's ✔/✘ form, rendered as two extra
 * columns of the "Rincian RAB" table (RabReviewCells) plus a footer
 * (RabReviewFooter). Items carrying an earlier ✔ (K7) are left out of the
 * form: the server refuses marks on them.
 */
export function useRabReview(quotation: Quotation, carried: Record<number, number>): RabReviewForm {
    const defaultMarks = useMemo(
        () =>
            Object.fromEntries(
                (quotation.items ?? [])
                    .filter((item) => carried[item.id] === undefined)
                    .map((item) => [String(item.id), { verdict: '' as Verdict, note: '' }]),
            ),
        [quotation.items, carried],
    );

    return useForm<RabReviewValues>({
        resolver: zodResolver(schema),
        defaultValues: { decision: 'approve', note: '', marks: defaultMarks },
    });
}

interface RabReviewCellsProps {
    itemId: number | undefined;
    review: RabReviewState;
    form: RabReviewForm;
}

/** The "Review" and "Catatan Review" cells of one RAB row. */
export function RabReviewCells({ itemId, review, form }: RabReviewCellsProps) {
    const carriedFrom = itemId === undefined ? undefined : review.carried[itemId];
    const key = String(itemId);
    const verdict = useWatch({ control: form.control, name: `marks.${key}.verdict` }) as Verdict | undefined;

    if (carriedFrom !== undefined) {
        return (
            <>
                <td className="px-3 py-3 text-center">
                    <span
                        className="inline-flex items-center gap-1 rounded-md bg-success/10 px-2 py-0.5 text-xs font-medium text-success-ink"
                        title={`Sudah ✔ di versi ${carriedFrom} dan tidak berubah — tidak perlu dicek ulang`}
                    >
                        <Lock className="size-3" aria-hidden />✔ versi {carriedFrom}
                    </span>
                </td>
                <td className="px-3 py-3 text-xs text-daiku-muted">Tidak berubah</td>
            </>
        );
    }

    if (!review.canDecide || itemId === undefined) {
        return (
            <>
                <td className="px-3 py-3 text-center text-xs text-daiku-muted">Menunggu PM</td>
                <td className="px-3 py-3 text-xs text-daiku-muted">—</td>
            </>
        );
    }

    // Its own provider: the cells sit inside the RAB builder's form, and FormMessage reads the nearest one.
    return (
        <Form {...form}>
            <td className="px-3 py-2">
                <FormField
                    control={form.control}
                    name={`marks.${key}.verdict`}
                    render={({ field }) => (
                        <FormItem>
                            <div className="flex justify-center gap-1">
                                <VerdictButton active={field.value === 'OK'} tone="ok" label="Cocok" onClick={() => field.onChange(field.value === 'OK' ? '' : 'OK')} />
                                <VerdictButton
                                    active={field.value === 'SALAH'}
                                    tone="wrong"
                                    label="Kurang cocok"
                                    onClick={() => field.onChange(field.value === 'SALAH' ? '' : 'SALAH')}
                                />
                            </div>
                            <FormMessage className="text-center" />
                        </FormItem>
                    )}
                />
            </td>
            <td className="px-3 py-2">
                {verdict === 'SALAH' ? (
                    <FormField
                        control={form.control}
                        name={`marks.${key}.note`}
                        render={({ field }) => (
                            <FormItem>
                                <FormControl>
                                    <Input {...field} placeholder="Apa yang kurang cocok?" />
                                </FormControl>
                                <FormMessage />
                            </FormItem>
                        )}
                    />
                ) : (
                    <span className="block py-1.5 text-xs text-daiku-muted">—</span>
                )}
            </td>
        </Form>
    );
}

interface RabReviewFooterProps {
    quotation: Quotation;
    review: RabReviewState;
    form: RabReviewForm;
}

/**
 * Below the RAB table: the count of marks, "Tandai semua ✔", the general
 * note and the two decisions — "Setujui RAB" (straight to Marketing,
 * Sprint 23 K8) and "Kembalikan ke Estimator".
 */
export function RabReviewFooter({ quotation, review, form }: RabReviewFooterProps) {
    const marks = useWatch({ control: form.control, name: 'marks' }) ?? {};
    const values = Object.values(marks);
    const ok = values.filter((mark) => mark.verdict === 'OK').length;
    const wrong = values.filter((mark) => mark.verdict === 'SALAH').length;
    const unmarked = values.length - ok - wrong;
    const carriedCount = Object.keys(review.carried).length;

    if (!review.canDecide) {
        return carriedCount > 0 ? (
            <p className="text-sm text-daiku-muted">
                {carriedCount} item tidak berubah sejak ✔ di versi sebelumnya — PM hanya mengecek item yang baru atau berubah.
            </p>
        ) : null;
    }

    function submit(decision: RabReviewValues['decision']) {
        form.setValue('decision', decision);
        form.handleSubmit((data) => {
            router.post(
                route('quotations.review', { quotation: quotation.id }),
                {
                    decision: data.decision,
                    note: data.note.trim() || null,
                    items: Object.entries(data.marks).map(([id, mark]) => ({
                        item_id: Number(id),
                        verdict: mark.verdict,
                        note: mark.note.trim() || null,
                    })),
                },
                {
                    preserveScroll: true,
                    // Server errors (status, items.{id}, decision, note) all surface under the general note.
                    onError: (errors) => form.setError('note', { message: Object.values(errors).join(' ') }),
                },
            );
        })();
    }

    function markAllOk() {
        Object.keys(marks).forEach((id) =>
            form.setValue(`marks.${id}.verdict`, 'OK', { shouldValidate: form.formState.isSubmitted }),
        );
    }

    return (
        <Form {...form}>
            <div className="space-y-4 rounded-xl border border-daiku-border p-4">
                <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                    <p className="text-daiku-muted">
                        <span className="font-medium text-success-ink">{ok} ✔</span> · <span className="font-medium text-error-ink">{wrong} ✘</span> ·{' '}
                        {unmarked} belum ditandai
                        {carriedCount > 0 && ` · ${carriedCount} item ✔ terbawa dari versi sebelumnya`}
                    </p>
                    {values.length > 0 && (
                        <Button type="button" variant="outline" size="sm" onClick={markAllOk}>
                            <Check className="size-4" />
                            Tandai semua ✔
                        </Button>
                    )}
                </div>

                <FormField
                    control={form.control}
                    name="note"
                    render={({ field }) => (
                        <FormItem>
                            <FormLabel>Catatan umum</FormLabel>
                            <FormControl>
                                <Textarea {...field} rows={2} placeholder="mis. Skema termin perlu DP lebih besar." />
                            </FormControl>
                            <FormMessage />
                        </FormItem>
                    )}
                />

                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <Button type="button" variant="outline" onClick={() => submit('return')} disabled={form.formState.isSubmitting}>
                        Kembalikan ke Estimator
                    </Button>
                    <Button type="button" onClick={() => submit('approve')} disabled={wrong > 0 || unmarked > 0 || form.formState.isSubmitting}>
                        <Check className="size-4" />
                        Setujui RAB
                    </Button>
                </div>
            </div>
        </Form>
    );
}

function VerdictButton({ active, tone, label, onClick }: { active: boolean; tone: 'ok' | 'wrong'; label: string; onClick: () => void }) {
    const Icon = tone === 'ok' ? Check : X;

    return (
        <Button
            type="button"
            variant="outline"
            size="icon-sm"
            aria-label={label}
            aria-pressed={active}
            title={label}
            onClick={onClick}
            className={cn(
                active && tone === 'ok' && 'border-success bg-success/10 text-success-ink hover:bg-success/15',
                active && tone === 'wrong' && 'border-error bg-error/10 text-error-ink hover:bg-error/15',
            )}
        >
            <Icon className="size-4" />
        </Button>
    );
}
