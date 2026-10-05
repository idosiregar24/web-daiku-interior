import { Notice } from '@/Components/shared/Notice';
import { VendorSelect } from '@/Components/shared/VendorSelect';
import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { formatRupiah } from '@/lib/format';
import type { BudgetLine, VendorOption } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const decimal = (message: string) => z.string().trim().regex(/^\d+([.,]\d{1,2})?$/, message);

// Mirrors RecordRealizationRequest.
const schema = z.object({
    qty_actual: decimal('Qty riil berupa angka, maks. 2 desimal.').refine((value) => Number(value.replace(',', '.')) >= 0.01, 'Qty riil minimal 0,01.'),
    unit_cost: decimal('Harga modal berupa angka, maks. 2 desimal.'),
    vendor_id: z.string(),
    note: z.string().max(1000, 'Catatan maksimal 1000 karakter.'),
    reason: z.string().max(2000),
});

type FormValues = z.infer<typeof schema>;

interface RealizationDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    projectId: number;
    postName: string;
    line: BudgetLine;
    vendors: VendorOption[];
}

const toNumber = (value: string) => Number(value.replace(',', '.'));

/**
 * Sprint 12 decisions #27–#28 — the PM records an item's real cost (qty
 * riil × harga modal, optional vendor). When it would push the post over
 * its budget the server refuses it; the dialog then turns into "Ajukan ke
 * CEO" with the same figures plus a required reason.
 */
export function RealizationDialog({ open, onOpenChange, projectId, postName, line, vendors }: RealizationDialogProps) {
    const [overrun, setOverrun] = useState<string | null>(null);
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { qty_actual: '', unit_cost: '', vendor_id: '', note: '', reason: '' },
    });

    useEffect(() => {
        if (open) {
            setOverrun(null);
            form.reset({ qty_actual: String(line.qty), unit_cost: '', vendor_id: '', note: '', reason: '' });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, line.id]);

    const qty = toNumber(form.watch('qty_actual') || '0');
    const cost = toNumber(form.watch('unit_cost') || '0');
    const total = Number.isFinite(qty * cost) ? Math.round(qty * cost * 100) / 100 : 0;

    function onSubmit(values: FormValues) {
        if (overrun && values.reason.trim().length < 5) {
            form.setError('reason', { message: 'Alasan minimal 5 karakter.' });
            return;
        }

        const payload = {
            qty_actual: toNumber(values.qty_actual),
            unit_cost: toNumber(values.unit_cost),
            vendor_id: values.vendor_id ? Number(values.vendor_id) : null,
            note: values.note.trim() || null,
            ...(overrun ? { reason: values.reason.trim() } : {}),
        };

        router.post(
            route(overrun ? 'projects.budget.overruns.store' : 'projects.budget.realizations.store', { project: projectId, line: line.id }),
            payload,
            {
                preserveScroll: true,
                onError: (errors) => {
                    if (errors.overrun) {
                        setOverrun(errors.overrun);
                        return;
                    }
                    Object.entries(errors).forEach(([field, message]) =>
                        form.setError((field in values ? field : 'qty_actual') as keyof FormValues, { message }),
                    );
                },
                onSuccess: () => onOpenChange(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{overrun ? 'Ajukan Overrun ke CEO' : 'Catat Realisasi'}</DialogTitle>
                    <DialogDescription>
                        {line.description} · pos {postName} · anggaran {formatRupiah(line.sell_price)}
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <FormField
                                control={form.control}
                                name="qty_actual"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Qty Riil{line.unit ? ` (${line.unit})` : ''}</FormLabel>
                                        <FormControl>
                                            <Input inputMode="decimal" {...field} disabled={!!overrun} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="unit_cost"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Harga Modal / Satuan</FormLabel>
                                        <FormControl>
                                            <Input inputMode="decimal" placeholder="mis. 250000" {...field} disabled={!!overrun} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <p className="text-sm text-daiku-muted">
                            Total realisasi: <span className="font-medium text-daiku-dark">{formatRupiah(total)}</span>
                        </p>
                        <FormField
                            control={form.control}
                            name="vendor_id"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Vendor (opsional)</FormLabel>
                                    <VendorSelect value={field.value} onChange={field.onChange} vendors={vendors} allowEmpty disabled={!!overrun} />
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="note"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Catatan (opsional)</FormLabel>
                                    <FormControl>
                                        <Textarea rows={2} maxLength={1000} {...field} disabled={!!overrun} />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />

                        {overrun && (
                            <>
                                <Notice tone="warning">{overrun}</Notice>
                                <FormField
                                    control={form.control}
                                    name="reason"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Alasan untuk CEO</FormLabel>
                                            <FormControl>
                                                <Textarea rows={3} maxLength={2000} autoFocus placeholder="mis. Panjang LED strip riil 7,5 m, RAB 4,15 m." {...field} />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                            </>
                        )}

                        <DialogFooter>
                            {overrun ? (
                                <Button type="button" variant="outline" onClick={() => setOverrun(null)}>
                                    Ubah Angka
                                </Button>
                            ) : (
                                <DialogClose asChild>
                                    <Button type="button" variant="outline">
                                        Batal
                                    </Button>
                                </DialogClose>
                            )}
                            <Button type="submit" disabled={form.formState.isSubmitting}>
                                {overrun ? 'Ajukan ke CEO' : 'Simpan'}
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
