import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { Button } from '@/Components/ui/button';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { formatQty, formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Quotation, QuotationItem, QuotationItemReview } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { Check, X } from 'lucide-react';
import { Fragment, useMemo } from 'react';
import { useForm, useWatch } from 'react-hook-form';
import { z } from 'zod';

type Stage = 'PM' | 'CEO';
type Verdict = '' | 'OK' | 'SALAH';

// Mirrors ReviewQuotationRequest + QuotationService::review().
function buildSchema(stage: Stage) {
    return z
        .object({
            decision: z.enum(['approve', 'return']),
            note: z.string().max(1000, 'Catatan maksimal 1000 karakter'),
            items: z.array(
                z.object({
                    item_id: z.number(),
                    verdict: z.enum(['', 'OK', 'SALAH']),
                    note: z.string().max(500, 'Catatan item maksimal 500 karakter'),
                }),
            ),
        })
        .superRefine((values, ctx) => {
            values.items.forEach((item, index) => {
                if (stage === 'PM' && item.verdict === '') {
                    ctx.addIssue({ code: 'custom', path: ['items', index, 'verdict'], message: 'Tandai ✔ atau ✘' });
                }
                if (item.verdict === 'SALAH' && !item.note.trim()) {
                    ctx.addIssue({ code: 'custom', path: ['items', index, 'note'], message: 'Catatan wajib untuk item ✘' });
                }
            });

            const wrong = values.items.filter((item) => item.verdict === 'SALAH').length;

            if (values.decision === 'approve' && wrong > 0) {
                ctx.addIssue({ code: 'custom', path: ['note'], message: 'RAB dengan item ✘ tidak bisa disetujui — kembalikan ke Estimator.' });
            }
            if (values.decision === 'return' && wrong === 0 && !values.note.trim()) {
                ctx.addIssue({ code: 'custom', path: ['note'], message: 'Tandai item yang salah (✘) atau tulis catatan alasan RAB dikembalikan.' });
            }
        });
}

type ReviewValues = z.infer<ReturnType<typeof buildSchema>>;

interface QuotationReviewPanelProps {
    quotation: Quotation;
    stage: Stage;
    /** Marks already made on this version — the CEO sees the PM's. */
    reviews: QuotationItemReview[];
}

/**
 * Sprint 12 decision #8 — PM / Asisten PM mark every item ✔ cocok / ✘
 * kurang cocok (✘ needs a note), then "Setujui RAB" (all ✔) or
 * "Kembalikan ke Estimator". The CEO (RAB Proyek) may mark only the items
 * they object to. Items are never edited here — that stays the Estimator's.
 */
export function QuotationReviewPanel({ quotation, stage, reviews }: QuotationReviewPanelProps) {
    const items = quotation.items ?? [];
    const schema = useMemo(() => buildSchema(stage), [stage]);
    const form = useForm<ReviewValues>({
        resolver: zodResolver(schema),
        defaultValues: {
            decision: 'approve',
            note: '',
            items: items.map((item) => ({ item_id: item.id, verdict: '' as Verdict, note: '' })),
        },
    });

    const marks = useWatch({ control: form.control, name: 'items' }) ?? [];
    const ok = marks.filter((mark) => mark.verdict === 'OK').length;
    const wrong = marks.filter((mark) => mark.verdict === 'SALAH').length;
    const unmarked = marks.length - ok - wrong;
    const canApprove = wrong === 0 && (stage === 'CEO' || unmarked === 0);

    const pmMarks = useMemo(
        () => new Map(reviews.filter((review) => review.stage === 'PM').map((review) => [review.quotation_item_id, review])),
        [reviews],
    );

    const groups = useMemo(() => {
        const sections = quotation.sections ?? [];
        const loose = items.filter((item) => item.section_id === null);

        return [
            ...(loose.length > 0 ? [{ name: 'Umum', items: loose }] : []),
            ...sections.map((section) => ({ name: section.name, items: items.filter((item) => item.section_id === section.id) })),
        ].filter((group) => group.items.length > 0);
    }, [quotation.sections, items]);

    const indexOf = (item: QuotationItem) => items.findIndex((candidate) => candidate.id === item.id);

    function submit(decision: ReviewValues['decision']) {
        form.setValue('decision', decision);
        form.handleSubmit((values) => {
            router.post(
                route('quotations.review', { quotation: quotation.id }),
                {
                    decision: values.decision,
                    note: values.note.trim() || null,
                    items: values.items
                        .filter((item) => item.verdict !== '')
                        .map((item) => ({ item_id: item.item_id, verdict: item.verdict, note: item.note.trim() || null })),
                },
                {
                    preserveScroll: true,
                    // Server errors (status, items.{id}, decision, note) all surface under the general note.
                    onError: (errors) => form.setError('note', { message: Object.values(errors).join(' ') }),
                },
            );
        })();
    }

    function setAll(verdict: Verdict) {
        items.forEach((_, index) => form.setValue(`items.${index}.verdict`, verdict, { shouldValidate: form.formState.isSubmitted }));
    }

    return (
        <Form {...form}>
            <form onSubmit={(event) => event.preventDefault()} className="space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                    <p className="text-daiku-muted">
                        <span className="font-medium text-success-ink">{ok} ✔</span> ·{' '}
                        <span className="font-medium text-error-ink">{wrong} ✘</span> · {unmarked} belum ditandai
                        {stage === 'CEO' && ' — CEO cukup menandai item yang salah.'}
                    </p>
                    <Button type="button" variant="outline" size="sm" onClick={() => setAll('OK')}>
                        <Check className="size-4" />
                        Tandai semua ✔
                    </Button>
                </div>

                <TableCard>
                    <table className="w-full min-w-[48rem] text-sm">
                        <thead className={TABLE_HEAD_CLASS}>
                            <tr>
                                <th className="w-10 px-3 py-2.5 text-left font-semibold">No</th>
                                <th className="px-3 py-2.5 text-left font-semibold">Item</th>
                                <th className="w-28 px-3 py-2.5 text-right font-semibold">Volume</th>
                                <th className="w-36 px-3 py-2.5 text-right font-semibold">Subtotal</th>
                                {stage === 'CEO' && <th className="w-16 px-3 py-2.5 text-center font-semibold">PM</th>}
                                <th className="w-28 px-3 py-2.5 text-center font-semibold">Tanda</th>
                                <th className="w-64 px-3 py-2.5 text-left font-semibold">Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                            {groups.map((group) => (
                                <Fragment key={group.name}>
                                    <tr className="border-t border-daiku-border bg-daiku-gray/60">
                                        <td colSpan={stage === 'CEO' ? 7 : 6} className="px-3 py-2 text-xs font-semibold tracking-wide text-daiku-dark uppercase">
                                            {group.name}
                                        </td>
                                    </tr>
                                    {group.items.map((item, number) => {
                                        const index = indexOf(item);
                                        const verdict = marks[index]?.verdict ?? '';
                                        const pm = pmMarks.get(item.id);

                                        return (
                                            <tr key={item.id} className={cn('border-t border-daiku-border align-top', verdict === 'SALAH' && 'bg-error/5')}>
                                                <td className="px-3 py-3 text-daiku-muted">{number + 1}</td>
                                                <td className="px-3 py-3 font-medium text-daiku-dark">{item.description}</td>
                                                <td className="px-3 py-3 text-right">
                                                    {formatQty(item.qty)} {item.unit?.code}
                                                </td>
                                                <td className="px-3 py-3 text-right">{formatRupiah(item.total_price)}</td>
                                                {stage === 'CEO' && (
                                                    <td className="px-3 py-3 text-center">
                                                        {pm ? (
                                                            <span className={pm.verdict === 'OK' ? 'text-success-ink' : 'text-error-ink'}>{pm.verdict === 'OK' ? '✔' : '✘'}</span>
                                                        ) : (
                                                            '—'
                                                        )}
                                                    </td>
                                                )}
                                                <td className="px-3 py-2">
                                                    <FormField
                                                        control={form.control}
                                                        name={`items.${index}.verdict`}
                                                        render={({ field }) => (
                                                            <FormItem>
                                                                <div className="flex justify-center gap-1">
                                                                    <VerdictButton
                                                                        active={field.value === 'OK'}
                                                                        tone="ok"
                                                                        label="Cocok"
                                                                        onClick={() => field.onChange(field.value === 'OK' ? '' : 'OK')}
                                                                    />
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
                                                            name={`items.${index}.note`}
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
                                            </tr>
                                        );
                                    })}
                                </Fragment>
                            ))}
                        </tbody>
                    </table>
                </TableCard>

                <FormField
                    control={form.control}
                    name="note"
                    render={({ field }) => (
                        <FormItem>
                            <FormLabel>Catatan umum (opsional)</FormLabel>
                            <FormControl>
                                <Textarea {...field} rows={2} placeholder="mis. Skema termin perlu DP lebih besar." />
                            </FormControl>
                            <FormMessage />
                        </FormItem>
                    )}
                />

                <div className="flex flex-wrap justify-end gap-2">
                    <Button type="button" variant="outline" onClick={() => submit('return')} disabled={form.formState.isSubmitting}>
                        Kembalikan ke Estimator
                    </Button>
                    <Button type="button" onClick={() => submit('approve')} disabled={!canApprove || form.formState.isSubmitting}>
                        <Check className="size-4" />
                        {stage === 'PM' && quotation.type === 'PROYEK' ? 'Setujui & Teruskan ke CEO' : 'Setujui RAB'}
                    </Button>
                </div>
            </form>
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
