import { DatePicker } from '@/Components/shared/DatePicker';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Form, FormControl, FormDescription, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Switch } from '@/Components/ui/switch';
import { Textarea } from '@/Components/ui/textarea';
import { formatRupiah } from '@/lib/format';
import type { Asset, AssetCondition } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { useEffect, useMemo } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

export const CONDITION_LABELS: Record<AssetCondition, string> = {
    GOOD: 'Baik',
    FAIR: 'Cukup',
    DAMAGED: 'Rusak',
};

const isPositiveNumber = (v: string) => v !== '' && !isNaN(Number(v)) && Number(v) > 0;

/**
 * Mirrors App\Http\Requests\Logistics\StoreAssetRequest. `paid` is what
 * Finance already paid on the plan: the total can't go below it and the
 * plan can't be switched off (AssetInstallmentService re-checks both
 * under a row lock).
 */
function buildSchema(paid: number) {
    return z
        .object({
            name: z.string().min(1, 'Nama aset wajib diisi').max(150),
            category: z.string().min(1, 'Kategori wajib diisi').max(50),
            purchase_date: z.date({ message: 'Tanggal pembelian wajib diisi' }).max(new Date(), 'Tidak boleh di masa depan'),
            value: z
                .string()
                .min(1, 'Nilai aset wajib diisi')
                .refine((v) => !isNaN(Number(v)) && Number(v) >= 0, 'Nilai tidak valid'),
            condition: z.enum(['GOOD', 'FAIR', 'DAMAGED']),
            location: z.string().max(100).optional(),
            notes: z.string().max(2000).optional(),
            // PRD §4.7 "Aset & Cicilan" — only validated while the plan is on.
            has_installment: z.boolean(),
            total_install: z.string(),
            installment_amount: z.string(),
            installment_due_day: z.string(),
        })
        .superRefine((values, ctx) => {
            if (!values.has_installment) {
                if (paid > 0) {
                    ctx.addIssue({
                        code: 'custom',
                        path: ['has_installment'],
                        message: 'Cicilan aset ini sudah dibayar sebagian — rencana cicilan tidak bisa dihapus.',
                    });
                }

                return;
            }

            if (!isPositiveNumber(values.total_install)) {
                ctx.addIssue({
                    code: 'custom',
                    path: ['total_install'],
                    message: values.total_install === '' ? 'Total cicilan wajib diisi untuk aset bercicilan.' : 'Total cicilan harus lebih dari 0.',
                });
            } else if (Number(values.total_install) < paid) {
                ctx.addIssue({
                    code: 'custom',
                    path: ['total_install'],
                    message: `Total cicilan tidak boleh lebih kecil dari yang sudah dibayar (${formatRupiah(paid)}).`,
                });
            }

            if (values.installment_amount !== '') {
                if (!isPositiveNumber(values.installment_amount)) {
                    ctx.addIssue({ code: 'custom', path: ['installment_amount'], message: 'Cicilan per bulan harus lebih dari 0.' });
                } else if (isPositiveNumber(values.total_install) && Number(values.installment_amount) > Number(values.total_install)) {
                    ctx.addIssue({
                        code: 'custom',
                        path: ['installment_amount'],
                        message: 'Cicilan per bulan tidak boleh melebihi total cicilan.',
                    });
                }
            }

            if (values.installment_due_day !== '') {
                const day = Number(values.installment_due_day);

                if (!Number.isInteger(day) || day < 1 || day > 28) {
                    ctx.addIssue({ code: 'custom', path: ['installment_due_day'], message: 'Tanggal jatuh tempo harus antara 1 dan 28.' });
                }
            }
        });
}

type FormValues = z.infer<ReturnType<typeof buildSchema>>;

const EMPTY: FormValues = {
    name: '',
    category: '',
    purchase_date: new Date(),
    value: '',
    condition: 'GOOD',
    location: '',
    notes: '',
    has_installment: false,
    total_install: '',
    installment_amount: '',
    installment_due_day: '',
};

/** Decimal string from the API → form input ("" when empty). */
function amountInput(value: string | null): string {
    return value === null ? '' : String(Number(value));
}

interface AssetFormDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    asset?: Asset | null;
    categories: string[];
}

/** PRD §4.8 "Aset Inventaris" create/edit — Logistics only — incl. the §4.7 installment plan. */
export function AssetFormDialog({ open, onOpenChange, asset, categories }: AssetFormDialogProps) {
    const paid = Number(asset?.paid_install ?? 0);
    const schema = useMemo(() => buildSchema(paid), [paid]);
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: EMPTY });
    const hasInstallment = form.watch('has_installment');

    useEffect(() => {
        if (!open) {
            return;
        }

        form.reset(
            asset
                ? {
                      name: asset.name,
                      category: asset.category ?? '',
                      purchase_date: asset.purchase_date ? new Date(asset.purchase_date) : new Date(),
                      value: asset.value ? String(Number(asset.value)) : '',
                      condition: asset.condition,
                      location: asset.location ?? '',
                      notes: asset.notes ?? '',
                      has_installment: asset.has_installment,
                      total_install: amountInput(asset.total_install),
                      installment_amount: amountInput(asset.installment_amount),
                      installment_due_day: asset.installment_due_day ? String(asset.installment_due_day) : '',
                  }
                : { ...EMPTY, purchase_date: new Date() },
        );
    }, [open, asset]);

    function onSubmit(values: FormValues) {
        const payload = {
            name: values.name,
            category: values.category,
            condition: values.condition,
            purchase_date: format(values.purchase_date, 'yyyy-MM-dd'),
            value: Number(values.value),
            location: values.location || null,
            notes: values.notes || null,
            has_installment: values.has_installment,
            ...(values.has_installment
                ? {
                      total_install: Number(values.total_install),
                      installment_amount: values.installment_amount === '' ? null : Number(values.installment_amount),
                      installment_due_day: values.installment_due_day === '' ? null : Number(values.installment_due_day),
                  }
                : {}),
        };
        const options = {
            onError: (errors: Record<string, string>) =>
                Object.entries(errors).forEach(([field, message]) => form.setError(field as keyof FormValues, { message })),
            onSuccess: () => onOpenChange(false),
        };

        if (asset) {
            router.put(route('logistics.assets.update', { asset: asset.id }), payload, options);
        } else {
            router.post(route('logistics.assets.store'), payload, options);
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] max-w-lg overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{asset ? 'Edit Aset' : 'Tambah Aset'}</DialogTitle>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="name"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Nama Aset</FormLabel>
                                    <FormControl>
                                        <Input {...field} placeholder="mis. Mobil Pickup L300" />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                control={form.control}
                                name="category"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Kategori</FormLabel>
                                        <FormControl>
                                            <Input {...field} list="asset-categories" placeholder="Alat, Mesin, Kendaraan…" />
                                        </FormControl>
                                        <datalist id="asset-categories">
                                            {categories.map((category) => (
                                                <option key={category} value={category} />
                                            ))}
                                        </datalist>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="condition"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Kondisi</FormLabel>
                                        <Select value={field.value} onValueChange={field.onChange}>
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                {(Object.keys(CONDITION_LABELS) as AssetCondition[]).map((condition) => (
                                                    <SelectItem key={condition} value={condition}>
                                                        {CONDITION_LABELS[condition]}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                control={form.control}
                                name="purchase_date"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Tanggal Beli</FormLabel>
                                        <FormControl>
                                            <DatePicker value={field.value} onChange={field.onChange} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="value"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Nilai (Rp)</FormLabel>
                                        <FormControl>
                                            <Input type="number" min="0" step="any" inputMode="decimal" {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <FormField
                            control={form.control}
                            name="location"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Lokasi</FormLabel>
                                    <FormControl>
                                        <Input {...field} placeholder="mis. Gudang Workshop" />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="notes"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Catatan</FormLabel>
                                    <FormControl>
                                        <Textarea {...field} rows={3} />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />

                        <div className="space-y-4 rounded-lg border border-daiku-border p-3">
                            <FormField
                                control={form.control}
                                name="has_installment"
                                render={({ field }) => (
                                    <FormItem className="flex flex-row items-center justify-between gap-4">
                                        <div>
                                            <FormLabel className="cursor-pointer">Aset dalam cicilan</FormLabel>
                                            <FormDescription>
                                                {paid > 0
                                                    ? `Sudah dibayar ${formatRupiah(paid)} — rencana cicilan tidak bisa dimatikan.`
                                                    : 'Pembayaran cicilannya dicatat Finance di menu Cicilan Aset.'}
                                            </FormDescription>
                                            <FormMessage />
                                        </div>
                                        <FormControl>
                                            <Switch checked={field.value} onCheckedChange={field.onChange} disabled={paid > 0} />
                                        </FormControl>
                                    </FormItem>
                                )}
                            />
                            {hasInstallment && (
                                <>
                                    <FormField
                                        control={form.control}
                                        name="total_install"
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormLabel required>Total Cicilan (Rp)</FormLabel>
                                                <FormControl>
                                                    <Input type="number" min="0" step="any" inputMode="decimal" {...field} />
                                                </FormControl>
                                                <FormDescription>Seluruh nominal yang harus dibayar sampai lunas.</FormDescription>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                    <div className="grid grid-cols-2 gap-4">
                                        <FormField
                                            control={form.control}
                                            name="installment_amount"
                                            render={({ field }) => (
                                                <FormItem>
                                                    <FormLabel>Cicilan per Bulan</FormLabel>
                                                    <FormControl>
                                                        <Input type="number" min="0" step="any" inputMode="decimal" {...field} />
                                                    </FormControl>
                                                    <FormMessage />
                                                </FormItem>
                                            )}
                                        />
                                        <FormField
                                            control={form.control}
                                            name="installment_due_day"
                                            render={({ field }) => (
                                                <FormItem>
                                                    <FormLabel>Jatuh Tempo Tgl</FormLabel>
                                                    <FormControl>
                                                        <Input type="number" min="1" max="28" step="1" inputMode="numeric" placeholder="1–28" {...field} />
                                                    </FormControl>
                                                    <FormMessage />
                                                </FormItem>
                                            )}
                                        />
                                    </div>
                                </>
                            )}
                        </div>

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
