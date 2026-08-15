import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import {
    Form,
    FormControl,
    FormField,
    FormItem,
    FormMessage,
} from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import AppLayout from '@/Layouts/AppLayout';
import type { Quotation } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useFieldArray, useForm } from 'react-hook-form';
import { z } from 'zod';

interface QuotationShowProps {
    quotation: Quotation & { lead: { id: number; client_name: string } };
    canManage: boolean;
}

const itemSchema = z.object({
    description: z.string().min(1, 'Deskripsi wajib diisi'),
    qty: z
        .string()
        .min(1, 'Qty wajib diisi')
        .refine((v) => !isNaN(Number(v)) && Number(v) >= 1, 'Qty minimal 1'),
    unit: z.string().min(1, 'Satuan wajib diisi'),
    unit_price: z
        .string()
        .min(1, 'Harga wajib diisi')
        .refine((v) => !isNaN(Number(v)) && Number(v) >= 0, 'Harga tidak valid'),
});

const schema = z.object({
    items: z.array(itemSchema).min(1, 'Tambahkan minimal satu item RAB'),
});

type FormValues = z.infer<typeof schema>;

function formatRupiah(value: string | number) {
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        maximumFractionDigits: 0,
    }).format(Number(value));
}

/**
 * RAB builder — add/remove item + auto-calculated totals
 * (.claude/plan/sprint-02.md Week 4, Ido task 5). Reached from the Design
 * page's Client ACC trigger. Only editable while DRAFT (see
 * QuotationService::replaceItems()) — CEO/PM approval UI is Week 5.
 */
export default function QuotationShow({ quotation, canManage }: QuotationShowProps) {
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: {
            items: (quotation.items ?? []).map((item) => ({
                description: item.description,
                qty: String(item.qty),
                unit: item.unit,
                unit_price: String(item.unit_price),
            })),
        },
    });

    const { fields, append, remove } = useFieldArray({ control: form.control, name: 'items' });
    const watchedItems = form.watch('items');

    const total = watchedItems.reduce((sum, item) => {
        const qty = Number(item.qty) || 0;
        const price = Number(item.unit_price) || 0;

        return sum + qty * price;
    }, 0);

    const isDraft = quotation.status === 'DRAFT';
    const editable = canManage && isDraft;

    function onError(errors: Record<string, string>) {
        Object.entries(errors).forEach(([field, message]) => {
            form.setError(field as keyof FormValues, { message });
        });
    }

    function onSave(values: FormValues) {
        router.put(
            route('quotations.items.update', { quotation: quotation.id }),
            {
                items: values.items.map((item) => ({
                    description: item.description,
                    qty: Number(item.qty),
                    unit: item.unit,
                    unit_price: Number(item.unit_price),
                })),
            },
            { onError },
        );
    }

    function onSubmitForReview() {
        router.post(route('quotations.submit', { quotation: quotation.id }), {}, { onError });
    }

    return (
        <AppLayout
            breadcrumbs={[
                { label: 'CRM', routeName: 'crm.leads.index' },
                { label: `Quotation — ${quotation.lead.client_name}` },
            ]}
        >
            <Head title={`Quotation — ${quotation.lead.client_name}`} />

            <PageHeader
                title={`Quotation: ${quotation.lead.client_name}`}
                description={`Versi ${quotation.version} · dibuat dari desain yang sudah di-ACC klien.`}
                actions={<StatusChip status={quotation.status} />}
            />

            <Card>
                <CardContent className="pt-6">
                    <Form {...form}>
                        <form onSubmit={form.handleSubmit(onSave)} className="space-y-4">
                            <div className="overflow-x-auto rounded-lg border border-daiku-border">
                                <table className="w-full text-sm">
                                    <thead className="bg-daiku-yellow-light">
                                        <tr>
                                            <th className="p-2 text-left font-medium">Deskripsi</th>
                                            <th className="w-24 p-2 text-left font-medium">Qty</th>
                                            <th className="w-28 p-2 text-left font-medium">Satuan</th>
                                            <th className="w-40 p-2 text-left font-medium">Harga Satuan</th>
                                            <th className="w-40 p-2 text-right font-medium">Total</th>
                                            {editable && <th className="w-12 p-2" />}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {fields.length === 0 ? (
                                            <tr>
                                                <td colSpan={6} className="p-6 text-center text-daiku-muted">
                                                    Belum ada item RAB.
                                                </td>
                                            </tr>
                                        ) : (
                                            fields.map((item, index) => {
                                                const qty = Number(watchedItems[index]?.qty) || 0;
                                                const price = Number(watchedItems[index]?.unit_price) || 0;

                                                return (
                                                    <tr key={item.id} className="border-t border-daiku-border align-top">
                                                        <td className="p-2">
                                                            <FormField
                                                                control={form.control}
                                                                name={`items.${index}.description`}
                                                                render={({ field }) => (
                                                                    <FormItem>
                                                                        <FormControl>
                                                                            <Input {...field} disabled={!editable} placeholder="mis. Kitchen Set Custom" />
                                                                        </FormControl>
                                                                        <FormMessage />
                                                                    </FormItem>
                                                                )}
                                                            />
                                                        </td>
                                                        <td className="p-2">
                                                            <FormField
                                                                control={form.control}
                                                                name={`items.${index}.qty`}
                                                                render={({ field }) => (
                                                                    <FormItem>
                                                                        <FormControl>
                                                                            <Input type="number" min="1" {...field} disabled={!editable} />
                                                                        </FormControl>
                                                                        <FormMessage />
                                                                    </FormItem>
                                                                )}
                                                            />
                                                        </td>
                                                        <td className="p-2">
                                                            <FormField
                                                                control={form.control}
                                                                name={`items.${index}.unit`}
                                                                render={({ field }) => (
                                                                    <FormItem>
                                                                        <FormControl>
                                                                            <Input {...field} disabled={!editable} placeholder="unit" />
                                                                        </FormControl>
                                                                        <FormMessage />
                                                                    </FormItem>
                                                                )}
                                                            />
                                                        </td>
                                                        <td className="p-2">
                                                            <FormField
                                                                control={form.control}
                                                                name={`items.${index}.unit_price`}
                                                                render={({ field }) => (
                                                                    <FormItem>
                                                                        <FormControl>
                                                                            <Input type="number" min="0" step="0.01" {...field} disabled={!editable} />
                                                                        </FormControl>
                                                                        <FormMessage />
                                                                    </FormItem>
                                                                )}
                                                            />
                                                        </td>
                                                        <td className="p-2 text-right font-medium text-daiku-dark">
                                                            {formatRupiah(qty * price)}
                                                        </td>
                                                        {editable && (
                                                            <td className="p-2">
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="icon-sm"
                                                                    onClick={() => remove(index)}
                                                                >
                                                                    <Trash2 className="size-4 text-error" />
                                                                </Button>
                                                            </td>
                                                        )}
                                                    </tr>
                                                );
                                            })
                                        )}
                                    </tbody>
                                    <tfoot>
                                        <tr className="border-t border-daiku-border bg-daiku-gray">
                                            <td colSpan={4} className="p-2 text-right font-semibold">
                                                Total
                                            </td>
                                            <td className="p-2 text-right font-semibold text-daiku-dark">
                                                {formatRupiah(total)}
                                            </td>
                                            {editable && <td />}
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>

                            {form.formState.errors.items?.message && (
                                <p className="text-sm text-destructive">{form.formState.errors.items.message}</p>
                            )}

                            {editable && (
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() => append({ description: '', qty: '1', unit: '', unit_price: '0' })}
                                    >
                                        <Plus className="size-4" />
                                        Tambah Item
                                    </Button>

                                    <div className="flex gap-2">
                                        <Button type="submit" variant="outline" disabled={form.formState.isSubmitting}>
                                            Simpan RAB
                                        </Button>
                                        <Button
                                            type="button"
                                            onClick={onSubmitForReview}
                                            disabled={fields.length === 0}
                                        >
                                            Submit ke CEO
                                        </Button>
                                    </div>
                                </div>
                            )}

                            {!isDraft && (
                                <p className="text-sm text-daiku-muted">
                                    Quotation sudah disubmit — item RAB tidak bisa diubah lagi. Alur approval CEO → PM
                                    menyusul pada sprint berikutnya.
                                </p>
                            )}
                        </form>
                    </Form>
                </CardContent>
            </Card>
        </AppLayout>
    );
}
