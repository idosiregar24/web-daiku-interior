import { Notice } from '@/Components/shared/Notice';
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
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { cn } from '@/lib/utils';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useEffect, useMemo } from 'react';
import { useFieldArray, useForm, type FieldPath } from 'react-hook-form';
import { z } from 'zod';
import { KPI_DIRECTION_LABEL, KPI_SOURCE_LABEL, type KpiMetricOption, type KpiTemplatePosition } from './KpiTypes';

const numeric = (message: string) => z.string().refine((v) => v.trim() !== '' && !isNaN(Number(v)) && Number(v) > 0, message);

// Mirrors App\Http\Requests\HR\SaveKpiTemplateRequest.
const schema = z
    .object({
        indicators: z
            .array(
                z.object({
                    id: z.number().nullable(),
                    name: z.string().trim().min(1, 'Nama indikator wajib diisi.').max(150, 'Nama indikator maksimal 150 karakter.'),
                    source: z.enum(['AUTO', 'MANUAL']),
                    metric_key: z.string().nullable(),
                    target: numeric('Target harus lebih dari 0.'),
                    weight: numeric('Bobot harus lebih dari 0.').refine((v) => Number(v) <= 100, 'Bobot maksimal 100%.'),
                    direction: z.enum(['HIGHER_BETTER', 'LOWER_BETTER']),
                }),
            )
            .min(1, 'Template KPI minimal berisi 1 indikator.')
            .max(20, 'Template KPI maksimal berisi 20 indikator.'),
    })
    .superRefine((values, ctx) => {
        values.indicators.forEach((row, i) => {
            if (row.source === 'AUTO' && !row.metric_key) {
                ctx.addIssue({ code: 'custom', path: ['indicators', i, 'metric_key'], message: 'Indikator otomatis wajib memilih metrik.' });
            }
        });
        const total = values.indicators.reduce((sum, row) => sum + (Number(row.weight) || 0), 0);
        if (Math.abs(total - 100) > 0.001) {
            ctx.addIssue({ code: 'custom', path: ['indicators', 'root'], message: `Total bobot harus tepat 100% (sekarang ${total.toLocaleString('id-ID')}%).` });
        }
    });

type FormValues = z.infer<typeof schema>;
type Row = FormValues['indicators'][number];

const NONE = 'none';

const emptyRow = (): Row => ({ id: null, name: '', source: 'MANUAL', metric_key: null, target: '', weight: '', direction: 'HIGHER_BETTER' });

interface KpiTemplateDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    position: KpiTemplatePosition | null;
    divisionName: string;
    metrics: KpiMetricOption[];
}

/**
 * HR edits a position's KPI indicators (§3.3, decision #9): source,
 * metric (AUTO), target, weight, direction. Weights must total 100 — the
 * running total is shown live. Metrics are suggested from the position's
 * name and its employees' roles, but any metric may be picked.
 */
export function KpiTemplateDialog({ open, onOpenChange, position, divisionName, metrics }: KpiTemplateDialogProps) {
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { indicators: [emptyRow()] } });
    const { fields, append, remove } = useFieldArray({ control: form.control, name: 'indicators' });

    useEffect(() => {
        if (!open || !position) return;
        form.reset({
            indicators: position.template?.indicators.length
                ? position.template.indicators.map((row) => ({
                      id: row.id,
                      name: row.name,
                      source: row.source,
                      metric_key: row.metric_key,
                      target: String(row.target),
                      weight: String(row.weight),
                      direction: row.direction,
                  }))
                : [emptyRow()],
        });
    }, [open, position]);

    const { suggested, others } = useMemo(() => {
        const name = position?.name.toLowerCase() ?? '';
        const isSuggested = (metric: KpiMetricOption) =>
            metric.roles.some((role) => position?.roles.includes(role)) || metric.positionHints.some((hint) => name.includes(hint));
        return { suggested: metrics.filter(isSuggested), others: metrics.filter((metric) => !isSuggested(metric)) };
    }, [metrics, position]);

    const metricByKey = useMemo(() => Object.fromEntries(metrics.map((metric) => [metric.key, metric])), [metrics]);

    const rows = form.watch('indicators');
    const total = rows.reduce((sum, row) => sum + (Number(row.weight) || 0), 0);
    const hasAuto = rows.some((row) => row.source === 'AUTO');
    const rootError = form.formState.errors.indicators?.root?.message ?? form.formState.errors.indicators?.message;

    function onSubmit(values: FormValues) {
        if (!position) return;

        router.put(
            route('hr.kpi.templates.save', { position: position.id }),
            {
                indicators: values.indicators.map((row) => ({
                    id: row.id,
                    name: row.name.trim(),
                    source: row.source,
                    metric_key: row.source === 'AUTO' ? row.metric_key : null,
                    target: Number(row.target),
                    weight: Number(row.weight),
                    direction: row.direction,
                })),
            },
            {
                preserveScroll: true,
                onError: (errors) =>
                    Object.entries(errors).forEach(([field, message]) =>
                        field === 'indicators'
                            ? form.setError('indicators', { message })
                            : form.setError(field as FieldPath<FormValues>, { message }),
                    ),
                onSuccess: () => onOpenChange(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-4xl">
                <DialogHeader>
                    <DialogTitle>Template KPI — {position?.name}</DialogTitle>
                    <DialogDescription>
                        {divisionName} · {position?.employees ?? 0} karyawan aktif. Skor per indikator = aktual ÷ target × 100 (dibalik untuk
                        "makin rendah makin baik"), dibatasi 0–120, lalu dikali bobot.
                    </DialogDescription>
                </DialogHeader>

                {position && hasAuto && position.withoutAccount > 0 && (
                    <Notice tone="warning">
                        {position.withoutAccount} karyawan di jabatan ini tidak punya akun sistem — indikator otomatis tidak bisa dihitung untuk
                        mereka, bobotnya akan dibagi ke indikator lain.
                    </Notice>
                )}

                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <div className="space-y-3">
                            {fields.map((field, index) => {
                                const row = rows[index];
                                const metric = row?.metric_key ? metricByKey[row.metric_key] : undefined;

                                return (
                                    <div key={field.id} className="rounded-xl p-3 ring-1 ring-border">
                                        <div className="grid gap-3 sm:grid-cols-12">
                                            <FormField
                                                control={form.control}
                                                name={`indicators.${index}.name`}
                                                render={({ field }) => (
                                                    <FormItem className="sm:col-span-5">
                                                        <FormLabel>Nama indikator</FormLabel>
                                                        <FormControl>
                                                            <Input placeholder="mis. Kualitas gambar" {...field} />
                                                        </FormControl>
                                                        <FormMessage />
                                                    </FormItem>
                                                )}
                                            />
                                            <FormField
                                                control={form.control}
                                                name={`indicators.${index}.source`}
                                                render={({ field }) => (
                                                    <FormItem className="sm:col-span-3">
                                                        <FormLabel>Sumber</FormLabel>
                                                        <Select
                                                            value={field.value}
                                                            onValueChange={(value) => {
                                                                field.onChange(value);
                                                                if (value === 'MANUAL') form.setValue(`indicators.${index}.metric_key`, null);
                                                            }}
                                                        >
                                                            <FormControl>
                                                                <SelectTrigger className="w-full">
                                                                    <SelectValue />
                                                                </SelectTrigger>
                                                            </FormControl>
                                                            <SelectContent>
                                                                <SelectItem value="AUTO">{KPI_SOURCE_LABEL.AUTO}</SelectItem>
                                                                <SelectItem value="MANUAL">{KPI_SOURCE_LABEL.MANUAL}</SelectItem>
                                                            </SelectContent>
                                                        </Select>
                                                        <FormMessage />
                                                    </FormItem>
                                                )}
                                            />
                                            <FormField
                                                control={form.control}
                                                name={`indicators.${index}.direction`}
                                                render={({ field }) => (
                                                    <FormItem className="sm:col-span-4">
                                                        <FormLabel>Arah</FormLabel>
                                                        <Select value={field.value} onValueChange={field.onChange}>
                                                            <FormControl>
                                                                <SelectTrigger className="w-full">
                                                                    <SelectValue />
                                                                </SelectTrigger>
                                                            </FormControl>
                                                            <SelectContent>
                                                                <SelectItem value="HIGHER_BETTER">{KPI_DIRECTION_LABEL.HIGHER_BETTER}</SelectItem>
                                                                <SelectItem value="LOWER_BETTER">{KPI_DIRECTION_LABEL.LOWER_BETTER}</SelectItem>
                                                            </SelectContent>
                                                        </Select>
                                                        <FormMessage />
                                                    </FormItem>
                                                )}
                                            />

                                            {row?.source === 'AUTO' && (
                                                <FormField
                                                    control={form.control}
                                                    name={`indicators.${index}.metric_key`}
                                                    render={({ field }) => (
                                                        <FormItem className="sm:col-span-12">
                                                            <FormLabel>Metrik otomatis</FormLabel>
                                                            <Select
                                                                value={field.value ?? NONE}
                                                                onValueChange={(value) => {
                                                                    const picked = value === NONE ? null : value;
                                                                    field.onChange(picked);
                                                                    const option = picked ? metricByKey[picked] : undefined;
                                                                    if (option) {
                                                                        form.setValue(`indicators.${index}.direction`, option.direction);
                                                                        if (!form.getValues(`indicators.${index}.name`).trim()) {
                                                                            form.setValue(`indicators.${index}.name`, option.label);
                                                                        }
                                                                    }
                                                                }}
                                                            >
                                                                <FormControl>
                                                                    <SelectTrigger className="w-full">
                                                                        <SelectValue placeholder="Pilih metrik" />
                                                                    </SelectTrigger>
                                                                </FormControl>
                                                                <SelectContent>
                                                                    <SelectItem value={NONE} disabled>
                                                                        Pilih metrik
                                                                    </SelectItem>
                                                                    {suggested.length > 0 && (
                                                                        <SelectGroup>
                                                                            <SelectLabel>Disarankan untuk jabatan ini</SelectLabel>
                                                                            {suggested.map((option) => (
                                                                                <SelectItem key={option.key} value={option.key}>
                                                                                    {option.label} ({option.unit})
                                                                                </SelectItem>
                                                                            ))}
                                                                        </SelectGroup>
                                                                    )}
                                                                    {others.length > 0 && (
                                                                        <SelectGroup>
                                                                            <SelectLabel>Metrik lain</SelectLabel>
                                                                            {others.map((option) => (
                                                                                <SelectItem key={option.key} value={option.key}>
                                                                                    {option.label} ({option.unit})
                                                                                </SelectItem>
                                                                            ))}
                                                                        </SelectGroup>
                                                                    )}
                                                                </SelectContent>
                                                            </Select>
                                                            {metric && <p className="text-xs text-daiku-muted">{metric.description}</p>}
                                                            <FormMessage />
                                                        </FormItem>
                                                    )}
                                                />
                                            )}

                                            <FormField
                                                control={form.control}
                                                name={`indicators.${index}.target`}
                                                render={({ field }) => (
                                                    <FormItem className="sm:col-span-5">
                                                        <FormLabel>
                                                            Target{metric ? ` (${metric.unit})` : ''}
                                                        </FormLabel>
                                                        <FormControl>
                                                            <Input type="number" min="0" step="any" inputMode="decimal" {...field} />
                                                        </FormControl>
                                                        <FormMessage />
                                                    </FormItem>
                                                )}
                                            />
                                            <FormField
                                                control={form.control}
                                                name={`indicators.${index}.weight`}
                                                render={({ field }) => (
                                                    <FormItem className="sm:col-span-5">
                                                        <FormLabel>Bobot (%)</FormLabel>
                                                        <FormControl>
                                                            <Input type="number" min="0" max="100" step="any" inputMode="decimal" {...field} />
                                                        </FormControl>
                                                        <FormMessage />
                                                    </FormItem>
                                                )}
                                            />
                                            <div className="flex items-end sm:col-span-2 sm:justify-end">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    disabled={fields.length === 1}
                                                    onClick={() => remove(index)}
                                                    aria-label={`Hapus indikator ${index + 1}`}
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>

                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <Button type="button" variant="outline" onClick={() => append(emptyRow())} disabled={fields.length >= 20}>
                                <Plus className="size-4" />
                                Tambah indikator
                            </Button>
                            <p
                                className={cn(
                                    'text-sm font-medium tabular-nums',
                                    Math.abs(total - 100) < 0.001 ? 'text-success-ink' : 'text-error-ink',
                                )}
                            >
                                Total bobot: {total.toLocaleString('id-ID')}% / 100%
                            </p>
                        </div>

                        {rootError && <p className="text-sm text-destructive">{rootError}</p>}

                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    Batal
                                </Button>
                            </DialogClose>
                            <Button type="submit" disabled={form.formState.isSubmitting}>
                                Simpan Template
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
