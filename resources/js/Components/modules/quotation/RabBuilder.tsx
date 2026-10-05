import { EmptyState } from "@/Components/shared/EmptyState";
import { TableCard, TABLE_HEAD_CLASS } from "@/Components/shared/TableCard";
import { UnitSelect } from "@/Components/shared/UnitSelect";
import { Button } from "@/Components/ui/button";
import {
    Form,
    FormControl,
    FormField,
    FormItem,
    FormLabel,
    FormMessage,
} from "@/Components/ui/form";
import { Input } from "@/Components/ui/input";
import { formatRupiah } from "@/lib/format";
import { cn } from "@/lib/utils";
import { parseQty, quantityField } from "@/lib/quantity";
import type {
    Quotation,
    QuotationItem,
    QuotationItemReview,
    UnitOption,
} from "@/types";
import { zodResolver } from "@hookform/resolvers/zod";
import { router } from "@inertiajs/react";
import { Plus, Trash2 } from "lucide-react";
import {
    type Control,
    type UseFormReturn,
    useFieldArray,
    useForm,
    useWatch,
} from "react-hook-form";
import { z } from "zod";

const MONEY = /^\d+(\.\d{1,2})?$/;

const optionalNumber = (label: string) =>
    z
        .string()
        .trim()
        .refine(
            (v) =>
                v === "" ||
                (!isNaN(Number(v.replace(",", "."))) &&
                    Number(v.replace(",", ".")) >= 0),
            `${label} tidak valid`,
        );

const itemSchema = z.object({
    description: z.string().trim().min(1, "Nama item wajib diisi").max(255),
    dim_length: optionalNumber("P"),
    dim_width_height: optionalNumber("T/L"),
    qty: quantityField("Volume"),
    unit_id: z.string().min(1, "Satuan wajib dipilih"),
    unit_price: z
        .string()
        .trim()
        .min(1, "Harga wajib diisi")
        .refine(
            (v) => !isNaN(Number(v)) && Number(v) >= 0,
            "Harga tidak valid",
        ),
});

const moneyField = (label: string) =>
    z
        .string()
        .trim()
        .refine(
            (v) => v === "" || MONEY.test(v),
            `${label} harus angka positif, maksimal 2 angka di belakang koma`,
        );

// Mirrors UpdateQuotationItemsRequest + QuotationService::saveRab() (discount ≤ total).
const schema = z
    .object({
        sections: z
            .array(
                z.object({
                    name: z
                        .string()
                        .trim()
                        .min(1, "Nama bagian pekerjaan wajib diisi")
                        .max(150),
                    items: z
                        .array(itemSchema)
                        .min(
                            1,
                            "Setiap bagian pekerjaan berisi minimal satu item",
                        ),
                }),
            )
            .min(1, "Tambahkan minimal satu bagian pekerjaan"),
        discount_amount: moneyField("Diskon"),
        rounded_total: moneyField("Pembulatan"),
    })
    .refine(
        (values) =>
            toCents(values.discount_amount) <= itemsTotalCents(values.sections),
        {
            path: ["discount_amount"],
            message: "Diskon tidak boleh melebihi total RAB.",
        },
    );

type RabValues = z.infer<typeof schema>;
type ItemValues = z.infer<typeof itemSchema>;

const EMPTY_ITEM: ItemValues = {
    description: "",
    dim_length: "",
    dim_width_height: "",
    qty: "1",
    unit_id: "",
    unit_price: "0",
};

function toCents(value: string | number | null | undefined): number {
    return Math.round((Number(value) || 0) * 100);
}

/** Same as the server: each line rounded to the cent, then summed. */
function lineCents(item: Pick<ItemValues, "qty" | "unit_price">): number {
    return Math.round(
        (parseQty(item.qty ?? "") || 0) * (Number(item.unit_price) || 0) * 100,
    );
}

function itemsTotalCents(
    sections: { items: Pick<ItemValues, "qty" | "unit_price">[] }[],
): number {
    return sections.reduce(
        (sum, section) =>
            sum + section.items.reduce((s, item) => s + lineCents(item), 0),
        0,
    );
}

function sectionLetter(index: number): string {
    return index < 26 ? String.fromCharCode(65 + index) : String(index + 1);
}

function toItemValues(item: QuotationItem): ItemValues {
    return {
        description: item.description,
        dim_length: item.dim_length === null ? "" : String(item.dim_length),
        dim_width_height:
            item.dim_width_height === null ? "" : String(item.dim_width_height),
        qty: String(item.qty),
        unit_id: String(item.unit_id),
        unit_price: String(Number(item.unit_price)),
    };
}

/** Items without a section (pre-Sprint-12 RABs) become an editable "Umum" section, as the PDF/Excel print them. */
function initialSections(quotation: Quotation): RabValues["sections"] {
    const items = quotation.items ?? [];
    const loose = items.filter((item) => item.section_id === null);
    const sections = (quotation.sections ?? []).map((section) => ({
        name: section.name,
        items: items
            .filter((item) => item.section_id === section.id)
            .map(toItemValues),
    }));

    return [
        ...(loose.length > 0
            ? [{ name: "Umum", items: loose.map(toItemValues) }]
            : []),
        ...sections,
    ];
}

interface RabBuilderProps {
    quotation: Quotation;
    units: UnitOption[];
    editable: boolean;
    onSubmitForReview?: () => void;
    /** Sprint 12 #8 — items marked ✘ on the previous version, highlighted while the Estimator revises. */
    findings?: QuotationItemReview[];
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
 */
export function RabBuilder({
    quotation,
    units,
    editable,
    onSubmitForReview,
    findings = [],
}: RabBuilderProps) {
    const flagged = new Map(
        findings.map((finding) => [
            findingKey(finding.item_description),
            finding.note ?? "",
        ]),
    );

    const form = useForm<RabValues>({
        resolver: zodResolver(schema),
        defaultValues: {
            sections: initialSections(quotation),
            discount_amount:
                Number(quotation.discount_amount) > 0
                    ? String(Number(quotation.discount_amount))
                    : "",
            rounded_total:
                quotation.rounded_total === null
                    ? ""
                    : String(Number(quotation.rounded_total)),
        },
    });

    const { fields, append, remove } = useFieldArray({
        control: form.control,
        name: "sections",
    });
    const sections =
        useWatch({ control: form.control, name: "sections" }) ?? [];
    const discount = useWatch({
        control: form.control,
        name: "discount_amount",
    });
    const rounded = useWatch({ control: form.control, name: "rounded_total" });

    const totalCents = itemsTotalCents(sections);
    const afterDiscountCents = totalCents - toCents(discount);
    const grandTotalCents = rounded?.trim()
        ? toCents(rounded)
        : afterDiscountCents;

    function onSave(values: RabValues) {
        router.put(
            route("quotations.items.update", { quotation: quotation.id }),
            {
                sections: values.sections.map((section) => ({
                    name: section.name,
                    items: section.items.map((item) => ({
                        description: item.description,
                        dim_length: item.dim_length
                            ? Number(item.dim_length.replace(",", "."))
                            : null,
                        dim_width_height: item.dim_width_height
                            ? Number(item.dim_width_height.replace(",", "."))
                            : null,
                        qty: parseQty(item.qty),
                        unit_id: Number(item.unit_id),
                        unit_price: Number(item.unit_price),
                    })),
                })),
                discount_amount: values.discount_amount
                    ? Number(values.discount_amount)
                    : 0,
                rounded_total: values.rounded_total
                    ? Number(values.rounded_total)
                    : null,
            },
            {
                preserveScroll: true,
                onError: (errors) =>
                    Object.entries(errors).forEach(([field, message]) =>
                        form.setError(field as keyof RabValues, { message }),
                    ),
            },
        );
    }

    return (
        <Form {...form}>
            <form onSubmit={form.handleSubmit(onSave)} className="space-y-4">
                {fields.length === 0 ? (
                    <TableCard>
                        <EmptyState
                            title="Belum ada bagian pekerjaan."
                            description={
                                editable
                                    ? "Tambah bagian pekerjaan (mis. Dapur, Kamar Utama), lalu isi item di dalamnya."
                                    : undefined
                            }
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
                            quotation={quotation}
                            editable={editable}
                            flagged={flagged}
                            onRemove={() => remove(index)}
                        />
                    ))
                )}

                {form.formState.errors.sections?.message && (
                    <p className="text-sm text-destructive">
                        {form.formState.errors.sections.message}
                    </p>
                )}
                {form.formState.errors.sections?.root?.message && (
                    <p className="text-sm text-destructive">
                        {form.formState.errors.sections.root.message}
                    </p>
                )}

                {editable && (
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            append({ name: "", items: [EMPTY_ITEM] })
                        }
                    >
                        <Plus className="size-4" />
                        Tambah Bagian Pekerjaan
                    </Button>
                )}

                <div className="ml-auto grid max-w-md gap-3 rounded-xl border border-border bg-daiku-gray/60 p-4 text-sm">
                    <SummaryRow
                        label="Total item"
                        value={formatRupiah(totalCents / 100)}
                    />
                    <FormField
                        control={form.control}
                        name="discount_amount"
                        render={({ field }) => (
                            <FormItem className="grid grid-cols-[1fr_11rem] items-center gap-x-3 gap-y-1">
                                <FormLabel className="font-normal text-daiku-muted">
                                    Diskon
                                </FormLabel>
                                <FormControl>
                                    <Input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        placeholder="0"
                                        {...field}
                                        disabled={!editable}
                                        className="text-right"
                                    />
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
                                <FormLabel className="font-normal text-daiku-muted">
                                    Pembulatan
                                </FormLabel>
                                <FormControl>
                                    <Input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        placeholder={String(
                                            Math.max(afterDiscountCents, 0) /
                                                100,
                                        )}
                                        {...field}
                                        disabled={!editable}
                                        className="text-right"
                                    />
                                </FormControl>
                                <FormMessage className="col-span-2 text-right" />
                            </FormItem>
                        )}
                    />
                    <div className="border-t border-border pt-3">
                        <SummaryRow
                            label="Grand total"
                            value={formatRupiah(grandTotalCents / 100)}
                            strong
                        />
                    </div>
                    {editable && (
                        <p className="text-xs text-daiku-muted">
                            Kosongkan pembulatan untuk memakai total setelah
                            diskon.
                        </p>
                    )}
                </div>

                {editable && (
                    <div className="flex flex-wrap justify-end gap-2">
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={form.formState.isSubmitting}
                        >
                            Simpan RAB
                        </Button>
                        {onSubmitForReview && (
                            <Button
                                type="button"
                                onClick={onSubmitForReview}
                                disabled={(quotation.items ?? []).length === 0}
                            >
                                Kirim ke PM
                            </Button>
                        )}
                    </div>
                )}
            </form>
        </Form>
    );
}

function SummaryRow({
    label,
    value,
    strong,
}: {
    label: string;
    value: string;
    strong?: boolean;
}) {
    return (
        <div className="flex items-center justify-between gap-3">
            <span
                className={
                    strong
                        ? "font-semibold text-daiku-dark"
                        : "text-daiku-muted"
                }
            >
                {label}
            </span>
            <span
                className={
                    strong
                        ? "text-base font-semibold text-daiku-dark"
                        : "font-medium text-daiku-dark"
                }
            >
                {value}
            </span>
        </div>
    );
}

interface RabSectionProps {
    index: number;
    form: UseFormReturn<RabValues>;
    control: Control<RabValues>;
    units: UnitOption[];
    quotation: Quotation;
    editable: boolean;
    flagged: Map<string, string>;
    onRemove: () => void;
}

function RabSection({
    index,
    form,
    control,
    units,
    quotation,
    editable,
    flagged,
    onRemove,
}: RabSectionProps) {
    const { fields, append, remove } = useFieldArray({
        control,
        name: `sections.${index}.items`,
    });
    const items = useWatch({ control, name: `sections.${index}.items` }) ?? [];
    const subtotalCents = items.reduce((sum, item) => sum + lineCents(item), 0);
    const itemsError = form.formState.errors.sections?.[index]?.items;

    return (
        <TableCard
            toolbar={
                <div className="flex w-full items-start gap-3">
                    <span className="mt-2 text-sm font-semibold text-daiku-dark">
                        {sectionLetter(index)}.
                    </span>
                    <FormField
                        control={control}
                        name={`sections.${index}.name`}
                        render={({ field }) => (
                            <FormItem className="flex-1">
                                <FormControl>
                                    <Input
                                        {...field}
                                        disabled={!editable}
                                        placeholder="Nama bagian pekerjaan, mis. Dapur"
                                        className="font-medium"
                                    />
                                </FormControl>
                                <FormMessage />
                            </FormItem>
                        )}
                    />
                    {editable && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            className="mt-1"
                            onClick={onRemove}
                            aria-label="Hapus bagian"
                        >
                            <Trash2 className="size-4 text-error-ink" />
                        </Button>
                    )}
                </div>
            }
            footer={
                itemsError?.message ? (
                    <p className="text-sm text-destructive">
                        {itemsError.message}
                    </p>
                ) : undefined
            }
        >
            <table className="w-full min-w-[56rem] text-sm">
                <thead className={TABLE_HEAD_CLASS}>
                    <tr>
                        <th className="w-12 px-3 py-2.5 text-left font-semibold">
                            No
                        </th>
                        <th className="px-3 py-2.5 text-left font-semibold">
                            Item
                        </th>
                        <th className="w-24 px-3 py-2.5 text-left font-semibold">
                            P
                        </th>
                        <th className="w-24 px-3 py-2.5 text-left font-semibold">
                            T/L
                        </th>
                        <th className="w-28 px-3 py-2.5 text-left font-semibold">
                            Volume
                        </th>
                        <th className="w-28 px-3 py-2.5 text-left font-semibold">
                            Satuan
                        </th>
                        <th className="w-40 px-3 py-2.5 text-left font-semibold">
                            Harga
                        </th>
                        <th className="w-36 px-3 py-2.5 text-right font-semibold">
                            Subtotal
                        </th>
                        {editable && <th className="w-12 px-3 py-2.5" />}
                    </tr>
                </thead>
                <tbody>
                    {fields.map((item, itemIndex) => {
                        const finding = flagged.get(
                            findingKey(items[itemIndex]?.description ?? ""),
                        );

                        return (
                            <tr
                                key={item.id}
                                className={cn(
                                    "border-t border-daiku-border align-top",
                                    finding !== undefined && "bg-error/5",
                                )}
                            >
                                <td className="px-3 py-3.5 text-daiku-muted">
                                    {itemIndex + 1}
                                </td>
                                <td className="px-3 py-2">
                                    <FormField
                                        control={control}
                                        name={`sections.${index}.items.${itemIndex}.description`}
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormControl>
                                                    <Input
                                                        {...field}
                                                        disabled={!editable}
                                                        placeholder="mis. Kitchen set bawah"
                                                    />
                                                </FormControl>
                                                {finding !== undefined && (
                                                    <p className="text-xs text-error-ink">
                                                        ✘ {finding}
                                                    </p>
                                                )}
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                </td>
                                {(
                                    ["dim_length", "dim_width_height"] as const
                                ).map((dim) => (
                                    <td key={dim} className="px-3 py-2">
                                        <FormField
                                            control={control}
                                            name={`sections.${index}.items.${itemIndex}.${dim}`}
                                            render={({ field }) => (
                                                <FormItem>
                                                    <FormControl>
                                                        <Input
                                                            type="number"
                                                            min="0"
                                                            step="0.01"
                                                            inputMode="decimal"
                                                            {...field}
                                                            disabled={!editable}
                                                            placeholder="—"
                                                        />
                                                    </FormControl>
                                                    <FormMessage />
                                                </FormItem>
                                            )}
                                        />
                                    </td>
                                ))}
                                <td className="px-3 py-2">
                                    <FormField
                                        control={control}
                                        name={`sections.${index}.items.${itemIndex}.qty`}
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormControl>
                                                    <Input
                                                        type="number"
                                                        min="0.01"
                                                        step="0.01"
                                                        inputMode="decimal"
                                                        {...field}
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
                                        name={`sections.${index}.items.${itemIndex}.unit_id`}
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormControl>
                                                    <UnitSelect
                                                        value={field.value}
                                                        onChange={
                                                            field.onChange
                                                        }
                                                        units={units}
                                                        current={
                                                            quotation.items?.find(
                                                                (line) =>
                                                                    String(
                                                                        line.unit_id,
                                                                    ) ===
                                                                    field.value,
                                                            )?.unit
                                                        }
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
                                                    <Input
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        {...field}
                                                        disabled={!editable}
                                                    />
                                                </FormControl>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                </td>
                                <td className="px-3 py-3.5 text-right font-medium text-daiku-dark">
                                    {formatRupiah(
                                        lineCents(
                                            items[itemIndex] ?? EMPTY_ITEM,
                                        ) / 100,
                                    )}
                                </td>
                                {editable && (
                                    <td className="px-3 py-2">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            onClick={() => remove(itemIndex)}
                                            aria-label="Hapus item"
                                        >
                                            <Trash2 className="size-4 text-error-ink" />
                                        </Button>
                                    </td>
                                )}
                            </tr>
                        );
                    })}
                </tbody>
                <tfoot>
                    <tr className="border-t border-border bg-daiku-gray/70">
                        <td
                            colSpan={7}
                            className="px-3 py-3 text-right font-semibold"
                        >
                            {editable ? (
                                <span className="flex items-center justify-between gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => append(EMPTY_ITEM)}
                                    >
                                        <Plus className="size-4" />
                                        Tambah Item
                                    </Button>
                                    Subtotal
                                </span>
                            ) : (
                                "Subtotal"
                            )}
                        </td>
                        <td className="px-3 py-3 text-right font-semibold text-daiku-dark">
                            {formatRupiah(subtotalCents / 100)}
                        </td>
                        {editable && <td />}
                    </tr>
                </tfoot>
            </table>
        </TableCard>
    );
}
