import { PAYMENT_TRIGGER_LABEL } from '@/Components/modules/quotation/labels';
import { EmptyState } from '@/Components/shared/EmptyState';
import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { Button } from '@/Components/ui/button';
import { Form, FormControl, FormField, FormItem, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { formatDate, formatRupiah } from '@/lib/format';
import type { PaymentTermTrigger, Quotation } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useMemo } from 'react';
import { useFieldArray, useForm, useWatch } from 'react-hook-form';
import { z } from 'zod';

const PERCENT = /^\d+(\.\d{1,2})?$/;

function hundredths(value: string | undefined): number {
    return Math.round((Number(value) || 0) * 100);
}

// Mirrors SavePaymentTermsRequest + QuotationService::savePaymentTerms().
function buildSchema(maxTerms: number) {
    return z.object({
        terms: z
            .array(
                z
                    .object({
                        label: z.string().trim().min(1, 'Nama termin wajib diisi').max(100),
                        percentage: z
                            .string()
                            .trim()
                            .min(1, 'Persentase wajib diisi')
                            .refine((v) => PERCENT.test(v) && Number(v) > 0 && Number(v) <= 100, 'Persentase 0,01–100, maksimal 2 angka di belakang koma'),
                        trigger: z.enum(['DI_MUKA', 'TANGGAL', 'MILESTONE', 'PROYEK_SELESAI']),
                        due_date: z.string(),
                        milestone_name: z.string().max(150),
                    })
                    .superRefine((term, ctx) => {
                        if (term.trigger === 'TANGGAL' && !term.due_date) {
                            ctx.addIssue({ code: 'custom', path: ['due_date'], message: 'Tanggal jatuh tempo wajib diisi' });
                        }
                        if (term.trigger === 'MILESTONE' && !term.milestone_name.trim()) {
                            ctx.addIssue({ code: 'custom', path: ['milestone_name'], message: 'Nama milestone wajib diisi' });
                        }
                    }),
            )
            .min(1, 'Skema pembayaran berisi minimal satu baris.')
            .max(maxTerms, `Skema pembayaran maksimal ${maxTerms} baris (termasuk DP).`)
            .refine((terms) => terms.reduce((sum, term) => sum + hundredths(term.percentage), 0) === 10000, {
                message: 'Total persentase skema pembayaran harus tepat 100%.',
            }),
    });
}

type TermsValues = z.infer<ReturnType<typeof buildSchema>>;

/** QuotationService::recalculatePaymentTerms(): floor per row, the last row takes the remainder. */
function previewAmounts(totalCents: number, percentages: string[]): number[] {
    let assigned = 0;

    return percentages.map((percentage, index) => {
        const cents = index === percentages.length - 1 ? totalCents - assigned : Math.floor((totalCents * (Number(percentage) || 0)) / 100);
        assigned += cents;

        return cents;
    });
}

interface PaymentTermsEditorProps {
    quotation: Quotation;
    editable: boolean;
    /** QuotationService::MAX_PAYMENT_TERMS */
    maxTerms: number;
}

/**
 * Sprint 12 decision #12 — the DP/termin scheme: 1–6 rows (DP included),
 * percentages adding up to 100%. Amounts are derived from the saved grand
 * total (save the RAB first when it changed); the preview mirrors the
 * server's rounding so the rows always add up to the total.
 */
export function PaymentTermsEditor({ quotation, editable, maxTerms }: PaymentTermsEditorProps) {
    const schema = useMemo(() => buildSchema(maxTerms), [maxTerms]);
    const form = useForm<TermsValues>({
        resolver: zodResolver(schema),
        defaultValues: {
            terms: (quotation.payment_terms ?? []).map((term) => ({
                label: term.label,
                percentage: String(Number(term.percentage)),
                trigger: term.trigger,
                due_date: term.due_date ?? '',
                milestone_name: term.milestone_name ?? '',
            })),
        },
    });

    const { fields, append, remove } = useFieldArray({ control: form.control, name: 'terms' });
    const terms = useWatch({ control: form.control, name: 'terms' }) ?? [];
    const totalPercent = terms.reduce((sum, term) => sum + hundredths(term.percentage), 0) / 100;
    const amounts = previewAmounts(Math.round(Number(quotation.total_amount) * 100), terms.map((term) => term.percentage));

    function onSave(values: TermsValues) {
        router.put(
            route('quotations.paymentTerms.update', { quotation: quotation.id }),
            {
                terms: values.terms.map((term) => ({
                    label: term.label,
                    percentage: Number(term.percentage),
                    trigger: term.trigger,
                    due_date: term.trigger === 'TANGGAL' ? term.due_date : null,
                    milestone_name: term.trigger === 'MILESTONE' ? term.milestone_name : null,
                })),
            },
            {
                preserveScroll: true,
                onError: (errors) =>
                    Object.entries(errors).forEach(([field, message]) => form.setError(field as keyof TermsValues, { message })),
            },
        );
    }

    if (!editable) {
        const saved = quotation.payment_terms ?? [];

        return (
            <TableCard>
                {saved.length === 0 ? (
                    <EmptyState title="Skema pembayaran belum diisi." />
                ) : (
                    <table className="w-full text-sm">
                        <thead className={TABLE_HEAD_CLASS}>
                            <tr>
                                <th className="w-12 px-3 py-2.5 text-left font-semibold">No</th>
                                <th className="px-3 py-2.5 text-left font-semibold">Termin</th>
                                <th className="w-20 px-3 py-2.5 text-right font-semibold">%</th>
                                <th className="w-40 px-3 py-2.5 text-right font-semibold">Nominal</th>
                                <th className="px-3 py-2.5 text-left font-semibold">Pemicu</th>
                            </tr>
                        </thead>
                        <tbody>
                            {saved.map((term) => (
                                <tr key={term.id} className="border-t border-daiku-border">
                                    <td className="px-3 py-2.5 text-daiku-muted">{term.sequence}</td>
                                    <td className="px-3 py-2.5 font-medium text-daiku-dark">{term.label}</td>
                                    <td className="px-3 py-2.5 text-right">{Number(term.percentage).toLocaleString('id-ID')}%</td>
                                    <td className="px-3 py-2.5 text-right">{formatRupiah(term.amount)}</td>
                                    <td className="px-3 py-2.5 text-daiku-muted">
                                        {PAYMENT_TRIGGER_LABEL[term.trigger]}
                                        {term.due_date && ` — ${formatDate(term.due_date)}`}
                                        {term.milestone_name && ` — ${term.milestone_name}`}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </TableCard>
        );
    }

    return (
        <Form {...form}>
            <form onSubmit={form.handleSubmit(onSave)} className="space-y-4">
                <TableCard
                    footer={
                        <div className="flex items-center justify-between gap-3 text-sm">
                            <span className={totalPercent === 100 ? 'text-success-ink' : 'font-medium text-error-ink'}>
                                Total {totalPercent.toLocaleString('id-ID')}% dari 100%
                            </span>
                            <span className="text-daiku-muted">
                                Dihitung dari grand total tersimpan {formatRupiah(quotation.total_amount)}
                            </span>
                        </div>
                    }
                >
                    <table className="w-full min-w-[52rem] text-sm">
                        <thead className={TABLE_HEAD_CLASS}>
                            <tr>
                                <th className="w-12 px-3 py-2.5 text-left font-semibold">No</th>
                                <th className="px-3 py-2.5 text-left font-semibold">Termin</th>
                                <th className="w-24 px-3 py-2.5 text-left font-semibold">%</th>
                                <th className="w-36 px-3 py-2.5 text-right font-semibold">Nominal</th>
                                <th className="w-52 px-3 py-2.5 text-left font-semibold">Pemicu</th>
                                <th className="w-48 px-3 py-2.5 text-left font-semibold">Detail</th>
                                <th className="w-12 px-3 py-2.5" />
                            </tr>
                        </thead>
                        <tbody>
                            {fields.map((field, index) => {
                                const trigger = terms[index]?.trigger;

                                return (
                                    <tr key={field.id} className="border-t border-daiku-border align-top">
                                        <td className="px-3 py-3.5 text-daiku-muted">{index + 1}</td>
                                        <td className="px-3 py-2">
                                            <FormField
                                                control={form.control}
                                                name={`terms.${index}.label`}
                                                render={({ field: input }) => (
                                                    <FormItem>
                                                        <FormControl>
                                                            <Input {...input} placeholder={index === 0 ? 'mis. DP' : `mis. Termin ${index}`} />
                                                        </FormControl>
                                                        <FormMessage />
                                                    </FormItem>
                                                )}
                                            />
                                        </td>
                                        <td className="px-3 py-2">
                                            <FormField
                                                control={form.control}
                                                name={`terms.${index}.percentage`}
                                                render={({ field: input }) => (
                                                    <FormItem>
                                                        <FormControl>
                                                            <Input type="number" min="0.01" max="100" step="0.01" inputMode="decimal" {...input} />
                                                        </FormControl>
                                                        <FormMessage />
                                                    </FormItem>
                                                )}
                                            />
                                        </td>
                                        <td className="px-3 py-3.5 text-right font-medium text-daiku-dark">
                                            {formatRupiah((amounts[index] ?? 0) / 100)}
                                        </td>
                                        <td className="px-3 py-2">
                                            <FormField
                                                control={form.control}
                                                name={`terms.${index}.trigger`}
                                                render={({ field: input }) => (
                                                    <FormItem>
                                                        <Select value={input.value} onValueChange={input.onChange}>
                                                            <FormControl>
                                                                <SelectTrigger className="w-full">
                                                                    <SelectValue />
                                                                </SelectTrigger>
                                                            </FormControl>
                                                            <SelectContent>
                                                                {(Object.keys(PAYMENT_TRIGGER_LABEL) as PaymentTermTrigger[]).map((value) => (
                                                                    <SelectItem key={value} value={value}>
                                                                        {PAYMENT_TRIGGER_LABEL[value]}
                                                                    </SelectItem>
                                                                ))}
                                                            </SelectContent>
                                                        </Select>
                                                        <FormMessage />
                                                    </FormItem>
                                                )}
                                            />
                                        </td>
                                        <td className="px-3 py-2">
                                            {trigger === 'TANGGAL' && (
                                                <FormField
                                                    control={form.control}
                                                    name={`terms.${index}.due_date`}
                                                    render={({ field: input }) => (
                                                        <FormItem>
                                                            <FormControl>
                                                                <Input type="date" {...input} />
                                                            </FormControl>
                                                            <FormMessage />
                                                        </FormItem>
                                                    )}
                                                />
                                            )}
                                            {trigger === 'MILESTONE' && (
                                                <FormField
                                                    control={form.control}
                                                    name={`terms.${index}.milestone_name`}
                                                    render={({ field: input }) => (
                                                        <FormItem>
                                                            <FormControl>
                                                                <Input {...input} placeholder="mis. Pemasangan selesai" />
                                                            </FormControl>
                                                            <FormMessage />
                                                        </FormItem>
                                                    )}
                                                />
                                            )}
                                            {(trigger === 'DI_MUKA' || trigger === 'PROYEK_SELESAI') && (
                                                <p className="py-1.5 text-xs text-daiku-muted">—</p>
                                            )}
                                        </td>
                                        <td className="px-3 py-2">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon-sm"
                                                disabled={fields.length === 1}
                                                onClick={() => remove(index)}
                                                aria-label="Hapus termin"
                                            >
                                                <Trash2 className="size-4 text-error-ink" />
                                            </Button>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </TableCard>

                {(form.formState.errors.terms?.message || form.formState.errors.terms?.root?.message) && (
                    <p className="text-sm text-destructive">
                        {form.formState.errors.terms?.message ?? form.formState.errors.terms?.root?.message}
                    </p>
                )}

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={fields.length >= maxTerms}
                        onClick={() =>
                            append({
                                label: fields.length === 0 ? 'DP' : `Termin ${fields.length}`,
                                percentage: '',
                                trigger: 'MILESTONE',
                                due_date: '',
                                milestone_name: '',
                            })
                        }
                    >
                        <Plus className="size-4" />
                        Tambah Termin ({fields.length}/{maxTerms})
                    </Button>
                    <Button type="submit" variant="outline" disabled={form.formState.isSubmitting}>
                        Simpan Skema Pembayaran
                    </Button>
                </div>
            </form>
        </Form>
    );
}
