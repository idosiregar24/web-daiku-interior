import { EmptyState } from '@/Components/shared/EmptyState';
import { MoneyInput } from '@/Components/shared/MoneyInput';
import { RequiredMark } from '@/Components/shared/RequiredMark';
import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { UnitSelect } from '@/Components/shared/UnitSelect';
import { Button } from '@/Components/ui/button';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Popover, PopoverAnchor, PopoverContent } from '@/Components/ui/popover';
import { RabReviewCells, RabReviewFooter, type RabReviewState, useRabReview, type RabReviewForm } from '@/Components/modules/quotation/RabReview';
import { formatQty, formatRupiah } from '@/lib/format';
import { fromHundredths, parseQty, quantityField, toHundredths } from '@/lib/quantity';
import { cn } from '@/lib/utils';
import type { Quotation, QuotationItem, QuotationItemReview, QuotationQtySource, UnitOption } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { Plus, RotateCcw, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { type Control, type UseFormReturn, useFieldArray, useForm, useWatch } from 'react-hook-form';
import { z } from 'zod';

const MONEY = /^\d+(\.\d{1,2})?$/;

/** Sprint 23 K5 — App\Models\Unit::METER_LARI: the only unit whose volume comes from P or T/L. */
const METER_LARI = 'm/lari';

const optionalNumber = (label: string) =>
    z
        .string()
        .trim()
        .refine((v) => v === '' || (!isNaN(Number(v.replace(',', '.'))) && Number(v.replace(',', '.')) >= 0), `${label} tidak valid`);

const itemSchema = z.object({
    /** The saved line's id (review columns only — never sent; items are rewritten on save). */
    item_id: z.number().optional(),
    description: z.string().trim().min(1, 'Nama item wajib diisi').max(255),
    dim_length: optionalNumber('P'),
    dim_width_height: optionalNumber('T/L'),
    qty: quantityField('Volume'),
    /** Sprint 23 K5 — '' = typed by hand; P / TL (m/lari) or PxTL (other units) = from the dimensions. */
    qty_source: z.enum(['', 'P', 'TL', 'PxTL']),
    unit_id: z.string().min(1, 'Satuan wajib dipilih'),
    unit_price: z
        .string()
        .trim()
        .min(1, 'Harga wajib diisi')
        .refine((v) => !isNaN(Number(v)) && Number(v) >= 0, 'Harga tidak valid'),
});

const moneyField = (label: string) =>
    z
        .string()
        .trim()
        .refine((v) => v === '' || MONEY.test(v), `${label} harus angka positif, maksimal 2 angka di belakang koma`);

// Mirrors UpdateQuotationItemsRequest + QuotationService::saveRab() (discount ≤ total).
const schema = z
    .object({
        sections: z
            .array(
                z.object({
                    name: z.string().trim().min(1, 'Nama bagian pekerjaan wajib diisi').max(150),
                    items: z.array(itemSchema).min(1, 'Setiap bagian pekerjaan berisi minimal satu item'),
                }),
            )
            .min(1, 'Tambahkan minimal satu bagian pekerjaan'),
        discount_amount: moneyField('Diskon'),
        rounded_total: moneyField('Pembulatan'),
    })
    .refine((values) => toCents(values.discount_amount) <= itemsTotalCents(values.sections), {
        path: ['discount_amount'],
        message: 'Diskon tidak boleh melebihi total RAB.',
    });

type RabValues = z.infer<typeof schema>;
type ItemValues = z.infer<typeof itemSchema>;
type SourceValue = ItemValues['qty_source'];

/** A new line suggests P × T/L (or asks P / T/L for m/lari) as soon as its dimensions are filled. */
const EMPTY_ITEM: ItemValues = {
    description: '',
    dim_length: '',
    dim_width_height: '',
    qty: '1',
    qty_source: 'PxTL',
    unit_id: '',
    unit_price: '0',
};

function toCents(value: string | number | null | undefined): number {
    return Math.round((Number(value) || 0) * 100);
}

/** Same as the server: each line rounded to the cent, then summed. */
function lineCents(item: Pick<ItemValues, 'qty' | 'unit_price'>): number {
    return Math.round((parseQty(item.qty ?? '') || 0) * (Number(item.unit_price) || 0) * 100);
}

function itemsTotalCents(sections: { items: Pick<ItemValues, 'qty' | 'unit_price'>[] }[]): number {
    return sections.reduce((sum, section) => sum + section.items.reduce((s, item) => s + lineCents(item), 0), 0);
}

function sectionLetter(index: number): string {
    return index < 26 ? String.fromCharCode(65 + index) : String(index + 1);
}

/**
 * Sprint 23 K5 — the volume (in hundredths) the dimensions give for a
 * source, mirroring QuotationItem::derivedQtyHundredths(); null when the
 * dimension(s) it needs are empty.
 */
function derivedHundredths(source: SourceValue, length: string, widthHeight: string): number | null {
    const p = toHundredths(length);
    const tl = toHundredths(widthHeight);

    switch (source) {
        case 'P':
            return p;
        case 'TL':
            return tl;
        case 'PxTL':
            return p === null || tl === null ? null : Math.round((p * tl) / 100);
        default:
            return null;
    }
}

function toItemValues(item: QuotationItem): ItemValues {
    return {
        item_id: item.id,
        description: item.description,
        dim_length: item.dim_length === null ? '' : String(item.dim_length),
        dim_width_height: item.dim_width_height === null ? '' : String(item.dim_width_height),
        qty: String(item.qty),
        qty_source: item.qty_source ?? '',
        unit_id: String(item.unit_id),
        unit_price: String(Number(item.unit_price)),
    };
}

/** Items without a section (pre-Sprint-12 RABs) become an editable "Umum" section, as the PDF/Excel print them. */
function initialSections(quotation: Quotation): RabValues['sections'] {
    const items = quotation.items ?? [];
    const loose = items.filter((item) => item.section_id === null);
    const sections = (quotation.sections ?? []).map((section) => ({
        name: section.name,
        items: items.filter((item) => item.section_id === section.id).map(toItemValues),
    }));

    return [...(loose.length > 0 ? [{ name: 'Umum', items: loose.map(toItemValues) }] : []), ...sections];
}

interface RabBuilderProps {
    quotation: Quotation;
    units: UnitOption[];
    editable: boolean;
    onSubmitForReview?: () => void;
    /** Sprint 12 #8 — items marked ✘ on the previous version, highlighted while the Estimator revises. */
    findings?: QuotationItemReview[];
    /** Sprint 23 K6 — while SUBMITTED: the ✔/✘ columns inside this table (decidable by PM / Asisten PM). */
    review?: RabReviewState | null;
}

/** Items are rewritten on every save, so a ✘ is matched to a line by its text. */
function findingKey(description: string): string {
    return description.trim().toLowerCase();
}

/**
 * Sprint 12 decision #11 — the RAB as the Estimator's Excel lays it out:
 * bagian pekerjaan (A, B, …), each with numbered items (Item, P, T/L,
 * Volume, Satuan, Harga, Subtotal), then Total → Diskon → Pembulatan.
 * Everything is sent on every save (QuotationService::saveRab()); totals
 * shown here are a preview, the server recomputes them.
 *
 * Sprint 23: the volume follows P / T/L (K5 — m/lari asks P or T/L, other
 * units P × T/L, typing a volume makes it manual); "Simpan sebagai Draft"
 * + "Kirim ke PM" sit under the table, Kirim disabled while changes are
 * unsaved (K5b); while SUBMITTED the PM's ✔/✘ live in this same table
 * (K6) and unchanged ✔ items are locked (K7).
 */
export function RabBuilder({ quotation, units, editable, onSubmitForReview, findings = [], review = null }: RabBuilderProps) {
    const flagged = new Map(findings.map((finding) => [findingKey(finding.item_description), finding.note ?? '']));

    // Unit code by id: the active units while editing, plus whatever the saved lines use.
    const unitCodes = useMemo(() => {
        const codes = new Map<string, string>();
        (quotation.items ?? []).forEach((item) => item.unit && codes.set(String(item.unit_id), item.unit.code));
        units.forEach((unit) => codes.set(String(unit.id), unit.code));

        return codes;
    }, [units, quotation.items]);

    const form = useForm<RabValues>({
        resolver: zodResolver(schema),
        defaultValues: {
            sections: initialSections(quotation),
            discount_amount: Number(quotation.discount_amount) > 0 ? String(Number(quotation.discount_amount)) : '',
            rounded_total: quotation.rounded_total === null ? '' : String(Number(quotation.rounded_total)),
        },
    });
    const reviewForm = useRabReview(quotation, review?.carried ?? {});

    const { fields, append, remove } = useFieldArray({ control: form.control, name: 'sections' });
    const sections = useWatch({ control: form.control, name: 'sections' }) ?? [];
    const discount = useWatch({ control: form.control, name: 'discount_amount' });
    const rounded = useWatch({ control: form.control, name: 'rounded_total' });

    const totalCents = itemsTotalCents(sections);
    const afterDiscountCents = totalCents - toCents(discount);
    const grandTotalCents = rounded?.trim() ? toCents(rounded) : afterDiscountCents;
    const dirty = form.formState.isDirty;

    function onSave(values: RabValues) {
        router.put(
            route('quotations.items.update', { quotation: quotation.id }),
            {
                sections: values.sections.map((section) => ({
                    name: section.name,
                    items: section.items.map((item) => ({
                        description: item.description,
                        dim_length: item.dim_length ? Number(item.dim_length.replace(',', '.')) : null,
                        dim_width_height: item.dim_width_height ? Number(item.dim_width_height.replace(',', '.')) : null,
                        qty: parseQty(item.qty),
                        // The server keeps it only when it's true (QuotationService::qtySource()).
                        qty_source: item.qty_source === '' ? null : item.qty_source,
                        unit_id: Number(item.unit_id),
                        unit_price: Number(item.unit_price),
                    })),
                })),
                discount_amount: values.discount_amount ? Number(values.discount_amount) : 0,
                rounded_total: values.rounded_total ? Number(values.rounded_total) : null,
            },
            {
                preserveScroll: true,
                // What's on screen is now the saved draft — "Kirim ke PM" may go.
                onSuccess: () => form.reset(form.getValues()),
                onError: (errors) => Object.entries(errors).forEach(([field, message]) => form.setError(field as keyof RabValues, { message })),
            },
        );
    }

    return (
        <div className="space-y-4">
            <Form {...form}>
                <form onSubmit={form.handleSubmit(onSave)} className="space-y-4">
                    {fields.length === 0 ? (
                        <TableCard>
                            <EmptyState
                                title="Belum ada bagian pekerjaan."
                                description={editable ? 'Tambah bagian pekerjaan (mis. Dapur, Kamar Utama), lalu isi item di dalamnya.' : undefined}
                            />
                        </TableCard>
                    ) : (
                        fields.map((section, index) => (
                            <RabSection
                                key={section.id}
                                index={index}
                                form={form}
                                control={form.control}
                                units={units}
                                unitCodes={unitCodes}
                                quotation={quotation}
                                editable={editable}
                                flagged={flagged}
                                review={review}
                                reviewForm={reviewForm}
                                onRemove={() => remove(index)}
                            />
                        ))
                    )}

                    {form.formState.errors.sections?.message && <p className="text-sm text-destructive">{form.formState.errors.sections.message}</p>}
                    {form.formState.errors.sections?.root?.message && (
                        <p className="text-sm text-destructive">{form.formState.errors.sections.root.message}</p>
                    )}

                    {editable && (
                        <Button type="button" variant="outline" size="sm" onClick={() => append({ name: '', items: [EMPTY_ITEM] })}>
                            <Plus className="size-4" />
                            Tambah Bagian Pekerjaan
                        </Button>
                    )}

                    <div className="ml-auto grid max-w-md gap-3 rounded-xl border border-border bg-daiku-gray/60 p-4 text-sm">
                        <SummaryRow label="Total item" value={formatRupiah(totalCents / 100)} />
                        <FormField
                            control={form.control}
                            name="discount_amount"
                            render={({ field }) => (
                                <FormItem className="grid grid-cols-[1fr_11rem] items-center gap-x-3 gap-y-1">
                                    <FormLabel className="font-normal text-daiku-muted">Diskon</FormLabel>
                                    <FormControl>
                                        <MoneyInput {...field} disabled={!editable} className="text-right" />
                                    </FormControl>
                                    <FormMessage className="col-span-2 text-right" />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="rounded_total"
                            render={({ field }) => (
                                <FormItem className="grid grid-cols-[1fr_11rem] items-center gap-x-3 gap-y-1">
                                    <FormLabel className="font-normal text-daiku-muted">Pembulatan</FormLabel>
                                    <FormControl>
                                        <MoneyInput
                                            {...field}
                                            placeholder={formatRupiah(Math.max(afterDiscountCents, 0) / 100).replace(/^Rp\s?/, '')}
                                            disabled={!editable}
                                            className="text-right"
                                        />
                                    </FormControl>
                                    <FormMessage className="col-span-2 text-right" />
                                </FormItem>
                            )}
                        />
                        <div className="border-t border-border pt-3">
                            <SummaryRow label="Grand total" value={formatRupiah(grandTotalCents / 100)} strong />
                        </div>
                        {editable && <p className="text-xs text-daiku-muted">Kosongkan pembulatan untuk memakai total setelah diskon.</p>}
                    </div>

                    {/* Sprint 23 K5b — between the RAB and the payment scheme. */}
                    {editable && (
                        <div className="flex flex-col gap-3 rounded-xl border border-daiku-border bg-daiku-yellow-light/50 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <p className={cn('text-sm', dirty ? 'font-medium text-warning-ink' : 'text-daiku-muted')} aria-live="polite">
                                {dirty ? 'Ada perubahan yang belum disimpan' : 'Simpan dulu sebagai draft, kemudian kirim ke PM.'}
                            </p>
                            <div className="flex flex-col gap-2 sm:flex-row">
                                <Button type="submit" variant="outline" disabled={form.formState.isSubmitting}>
                                    Simpan sebagai Draft
                                </Button>
                                {onSubmitForReview && (
                                    <Button type="button" onClick={onSubmitForReview} disabled={dirty || (quotation.items ?? []).length === 0}>
                                        Kirim ke PM
                                    </Button>
                                )}
                            </div>
                        </div>
                    )}
                </form>
            </Form>

            {/* Sprint 23 K6 — the review decision stays with the table. */}
            {review && <RabReviewFooter quotation={quotation} review={review} form={reviewForm} />}
        </div>
    );
}

function SummaryRow({ label, value, strong }: { label: string; value: string; strong?: boolean }) {
    return (
        <div className="flex items-center justify-between gap-3">
            <span className={strong ? 'font-semibold text-daiku-dark' : 'text-daiku-muted'}>{label}</span>
            <span className={strong ? 'text-base font-semibold text-daiku-dark' : 'font-medium text-daiku-dark'}>{value}</span>
        </div>
    );
}

interface RabSectionProps {
    index: number;
    form: UseFormReturn<RabValues>;
    control: Control<RabValues>;
    units: UnitOption[];
    unitCodes: Map<string, string>;
    quotation: Quotation;
    editable: boolean;
    flagged: Map<string, string>;
    review: RabReviewState | null;
    reviewForm: RabReviewForm;
    onRemove: () => void;
}

function RabSection({ index, form, control, units, unitCodes, quotation, editable, flagged, review, reviewForm, onRemove }: RabSectionProps) {
    const { fields, append, remove } = useFieldArray({ control, name: `sections.${index}.items` });
    const items = useWatch({ control, name: `sections.${index}.items` }) ?? [];
    const subtotalCents = items.reduce((sum, item) => sum + lineCents(item), 0);
    const itemsError = form.formState.errors.sections?.[index]?.items;

    return (
        <TableCard
            toolbar={
                <div className="flex w-full items-start gap-3">
                    <span className="mt-2 text-sm font-semibold text-daiku-dark">{sectionLetter(index)}.</span>
                    <FormField
                        control={control}
                        name={`sections.${index}.name`}
                        render={({ field }) => (
                            <FormItem className="flex-1">
                                <FormControl>
                                    <Input {...field} disabled={!editable} placeholder="Nama bagian pekerjaan, mis. Dapur" className="font-medium" />
                                </FormControl>
                                <FormMessage />
                            </FormItem>
                        )}
                    />
                    {editable && (
                        <Button type="button" variant="ghost" size="icon-sm" className="mt-1" onClick={onRemove} aria-label="Hapus bagian">
                            <Trash2 className="size-4 text-error-ink" />
                        </Button>
                    )}
                </div>
            }
            footer={itemsError?.message ? <p className="text-sm text-destructive">{itemsError.message}</p> : undefined}
        >
            <table className={cn('w-full text-sm', review ? 'min-w-[72rem]' : 'min-w-[56rem]')}>
                <thead className={TABLE_HEAD_CLASS}>
                    <tr>
                        <th className="w-12 px-3 py-2.5 text-left font-semibold">No</th>
                        <th className="px-3 py-2.5 text-left font-semibold">
                            Item
                            {editable && <RequiredMark />}
                        </th>
                        <th className="w-24 px-3 py-2.5 text-left font-semibold">P</th>
                        <th className="w-24 px-3 py-2.5 text-left font-semibold">T/L</th>
                        <th className="w-32 px-3 py-2.5 text-left font-semibold">
                            Volume
                            {editable && <RequiredMark />}
                        </th>
                        <th className="w-28 px-3 py-2.5 text-left font-semibold">
                            Satuan
                            {editable && <RequiredMark />}
                        </th>
                        <th className="w-40 px-3 py-2.5 text-left font-semibold">
                            Harga
                            {editable && <RequiredMark />}
                        </th>
                        <th className="w-36 px-3 py-2.5 text-right font-semibold">Subtotal</th>
                        {editable && <th className="w-12 px-3 py-2.5" />}
                        {review && (
                            <>
                                <th className="w-32 px-3 py-2.5 text-center font-semibold">
                                    Review
                                    {review.canDecide && <RequiredMark />}
                                </th>
                                <th className="w-56 px-3 py-2.5 text-left font-semibold">Catatan Review</th>
                            </>
                        )}
                    </tr>
                </thead>
                <tbody>
                    {fields.map((item, itemIndex) => {
                        const row = items[itemIndex];
                        const finding = flagged.get(findingKey(row?.description ?? ''));

                        return (
                            <tr key={item.id} className={cn('border-t border-daiku-border align-top', finding !== undefined && 'bg-error/5')}>
                                <td className="px-3 py-3.5 text-daiku-muted">{itemIndex + 1}</td>
                                <td className="px-3 py-2">
                                    <FormField
                                        control={control}
                                        name={`sections.${index}.items.${itemIndex}.description`}
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormControl>
                                                    <Input {...field} disabled={!editable} placeholder="mis. Kitchen set bawah" />
                                                </FormControl>
                                                {finding !== undefined && <p className="text-xs text-error-ink">✘ {finding}</p>}
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                </td>
                                {(['dim_length', 'dim_width_height'] as const).map((dim) => (
                                    <td key={dim} className="px-3 py-2">
                                        <FormField
                                            control={control}
                                            name={`sections.${index}.items.${itemIndex}.${dim}`}
                                            render={({ field }) => (
                                                <FormItem>
                                                    <FormControl>
                                                        <Input type="number" min="0" step="0.01" inputMode="decimal" {...field} disabled={!editable} placeholder="—" />
                                                    </FormControl>
                                                    <FormMessage />
                                                </FormItem>
                                            )}
                                        />
                                    </td>
                                ))}
                                <td className="px-3 py-2">
                                    <VolumeCell form={form} path={`sections.${index}.items.${itemIndex}`} unitCodes={unitCodes} editable={editable} />
                                </td>
                                <td className="px-3 py-2">
                                    <FormField
                                        control={control}
                                        name={`sections.${index}.items.${itemIndex}.unit_id`}
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormControl>
                                                    <UnitSelect
                                                        value={field.value}
                                                        onChange={field.onChange}
                                                        units={units}
                                                        current={quotation.items?.find((line) => String(line.unit_id) === field.value)?.unit}
                                                        disabled={!editable}
                                                    />
                                                </FormControl>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                </td>
                                <td className="px-3 py-2">
                                    <FormField
                                        control={control}
                                        name={`sections.${index}.items.${itemIndex}.unit_price`}
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormControl>
                                                    <MoneyInput {...field} disabled={!editable} />
                                                </FormControl>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                </td>
                                <td className="px-3 py-3.5 text-right font-medium text-daiku-dark">{formatRupiah(lineCents(row ?? EMPTY_ITEM) / 100)}</td>
                                {editable && (
                                    <td className="px-3 py-2">
                                        <Button type="button" variant="ghost" size="icon-sm" onClick={() => remove(itemIndex)} aria-label="Hapus item">
                                            <Trash2 className="size-4 text-error-ink" />
                                        </Button>
                                    </td>
                                )}
                                {review && <RabReviewCells itemId={row?.item_id} review={review} form={reviewForm} />}
                            </tr>
                        );
                    })}
                </tbody>
                <tfoot>
                    <tr className="border-t border-border bg-daiku-gray/70">
                        <td colSpan={7} className="px-3 py-3 text-right font-semibold">
                            {editable ? (
                                <span className="flex items-center justify-between gap-2">
                                    <Button type="button" variant="outline" size="sm" onClick={() => append(EMPTY_ITEM)}>
                                        <Plus className="size-4" />
                                        Tambah Item
                                    </Button>
                                    Subtotal
                                </span>
                            ) : (
                                'Subtotal'
                            )}
                        </td>
                        <td className="px-3 py-3 text-right font-semibold text-daiku-dark">{formatRupiah(subtotalCents / 100)}</td>
                        {editable && <td />}
                        {review && <td colSpan={2} />}
                    </tr>
                </tfoot>
            </table>
        </TableCard>
    );
}

const SOURCE_LABEL: Record<QuotationQtySource, string> = { P: 'dari P', TL: 'dari T/L', PxTL: 'P × T/L' };

interface VolumeCellProps {
    form: UseFormReturn<RabValues>;
    path: `sections.${number}.items.${number}`;
    unitCodes: Map<string, string>;
    editable: boolean;
}

/**
 * Sprint 23 K5 — the Volume cell. Satuan m/lari asks (small popover)
 * whether the volume is P or T/L; every other unit takes P × T/L. A
 * suggestion is only made when the needed dimension(s) are filled; typing
 * a volume makes the line manual, "↺ Hitung dari P/T/L" brings the
 * suggestion back. The chosen source travels as `qty_source`.
 */
function VolumeCell({ form, path, unitCodes, editable }: VolumeCellProps) {
    const length = useWatch({ control: form.control, name: `${path}.dim_length` }) ?? '';
    const widthHeight = useWatch({ control: form.control, name: `${path}.dim_width_height` }) ?? '';
    const unitId = useWatch({ control: form.control, name: `${path}.unit_id` }) ?? '';
    const source = (useWatch({ control: form.control, name: `${path}.qty_source` }) ?? '') as SourceValue;
    const qty = useWatch({ control: form.control, name: `${path}.qty` }) ?? '';
    const [asking, setAsking] = useState(false);

    const meterLari = unitCodes.get(unitId) === METER_LARI;
    const hasLength = toHundredths(length) !== null;
    const hasWidthHeight = toHundredths(widthHeight) !== null;
    // m/lari takes P or T/L only; any other unit P × T/L only.
    const applicable = meterLari ? source === 'P' || source === 'TL' : source === 'PxTL';
    const derived = applicable ? derivedHundredths(source, length, widthHeight) : null;
    // m/lari not chosen yet (a new line's default) while a dimension is there → ask.
    const needsChoice = editable && meterLari && source === 'PxTL' && (hasLength || hasWidthHeight);
    const canRecompute = editable && !applicable && (meterLari ? hasLength || hasWidthHeight : hasLength && hasWidthHeight);

    useEffect(() => {
        if (!editable) {
            return;
        }

        // Left m/lari with a P / T/L source → other units use P × T/L.
        if (!meterLari && (source === 'P' || source === 'TL')) {
            form.setValue(`${path}.qty_source`, 'PxTL', { shouldDirty: true });

            return;
        }

        if (derived !== null && derived > 0 && toHundredths(qty) !== derived) {
            form.setValue(`${path}.qty`, fromHundredths(derived), { shouldDirty: true, shouldValidate: form.formState.isSubmitted });
        }
    }, [editable, meterLari, source, derived, qty, form, path]);

    useEffect(() => {
        if (needsChoice) {
            setAsking(true);
        }
    }, [needsChoice]);

    function choose(next: SourceValue) {
        form.setValue(`${path}.qty_source`, next, { shouldDirty: true });
        setAsking(false);
    }

    function closeAsking(open: boolean) {
        setAsking(open);

        // Closed without picking → the Estimator types the volume.
        if (!open && meterLari && source === 'PxTL') {
            form.setValue(`${path}.qty_source`, '', { shouldDirty: true });
        }
    }

    return (
        <Popover open={asking} onOpenChange={closeAsking}>
            <PopoverAnchor asChild>
                <div>
                    <FormField
                        control={form.control}
                        name={`${path}.qty`}
                        render={({ field }) => (
                            <FormItem>
                                <FormControl>
                                    <Input
                                        type="number"
                                        min="0.01"
                                        step="0.01"
                                        inputMode="decimal"
                                        {...field}
                                        onChange={(event) => {
                                            field.onChange(event);
                                            // Typed by hand → manual (Sprint 23 K5).
                                            form.setValue(`${path}.qty_source`, '', { shouldDirty: true });
                                        }}
                                        disabled={!editable}
                                    />
                                </FormControl>
                                <FormMessage />
                            </FormItem>
                        )}
                    />
                    {applicable && derived !== null ? (
                        editable && meterLari ? (
                            <button type="button" className="mt-1 text-xs text-daiku-muted underline decoration-daiku-yellow underline-offset-2" onClick={() => setAsking(true)}>
                                otomatis · {SOURCE_LABEL[source as QuotationQtySource]}
                            </button>
                        ) : (
                            <p className="mt-1 text-xs text-daiku-muted">otomatis · {SOURCE_LABEL[source as QuotationQtySource]}</p>
                        )
                    ) : (
                        canRecompute && (
                            <button
                                type="button"
                                className="mt-1 inline-flex items-center gap-1 text-xs text-daiku-muted underline decoration-daiku-yellow underline-offset-2"
                                onClick={() => (meterLari ? setAsking(true) : choose('PxTL'))}
                            >
                                <RotateCcw className="size-3" aria-hidden />
                                Hitung dari P/T/L
                            </button>
                        )
                    )}
                </div>
            </PopoverAnchor>
            <PopoverContent align="start" className="w-64 space-y-3 p-3">
                <p className="text-sm font-medium text-foreground">Volume diambil dari:</p>
                <div className="grid grid-cols-2 gap-2">
                    <Button type="button" size="sm" variant={source === 'P' ? 'default' : 'outline'} disabled={!hasLength} onClick={() => choose('P')}>
                        P{hasLength ? ` (${formatQty(length.replace(',', '.'))})` : ''}
                    </Button>
                    <Button type="button" size="sm" variant={source === 'TL' ? 'default' : 'outline'} disabled={!hasWidthHeight} onClick={() => choose('TL')}>
                        T/L{hasWidthHeight ? ` (${formatQty(widthHeight.replace(',', '.'))})` : ''}
                    </Button>
                </div>
                <p className="text-xs text-daiku-muted">Satuan meter lari. Volume tetap bisa diubah manual.</p>
            </PopoverContent>
        </Popover>
    );
}
