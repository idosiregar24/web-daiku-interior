import { DatePicker } from '@/Components/shared/DatePicker';
import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Dialog, DialogClose, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { projectVisit, RELOAD } from '@/lib/projectReload';
import { cn } from '@/lib/utils';
import type { WorkSchedule } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { format, parseISO } from 'date-fns';
import { Loader2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { useForm, type FieldValues, type Path, type UseFormReturn } from 'react-hook-form';
import { z } from 'zod';
import { dayLabel, endOf, lengthOf, plusDays, rangeLabel, suggestStart } from './workSchedule';

type Item = WorkSchedule['items'][number];

const ymd = (value?: Date) => (value ? format(value, 'yyyy-MM-dd') : null);
const days = (value: string | undefined) => {
    const parsed = Number((value ?? '').trim());

    return Number.isInteger(parsed) && parsed > 0 ? parsed : null;
};

/** A start date + a length in days, required together; the end is shown as it is computed. */
function windowIssues(values: { start?: Date; days?: string }, label: string, path: [string, string], context: z.RefinementCtx) {
    const length = days(values.days);
    const typed = (values.days ?? '').trim() !== '';

    if (values.start && !typed)
        context.addIssue({
            code: 'custom',
            path: [path[1]],
            message: `Isi lama ${label} (hari).`,
        });
    if (!values.start && typed)
        context.addIssue({
            code: 'custom',
            path: [path[0]],
            message: `Isi tanggal mulai ${label}.`,
        });
    if (typed && length === null)
        context.addIssue({
            code: 'custom',
            path: [path[1]],
            message: 'Lama harus angka hari, minimal 1.',
        });
}

// Mirrors UpdateWorkItemScheduleRequest (dates); the project's dates are checked by the server.
const itemSchema = z
    .object({
        production_start: z.date().optional(),
        production_days: z.string().optional(),
        installation_start: z.date().optional(),
        installation_days: z.string().optional(),
    })
    .superRefine((values, context) => {
        windowIssues({ start: values.production_start, days: values.production_days }, 'produksi', ['production_start', 'production_days'], context);
        windowIssues(
            {
                start: values.installation_start,
                days: values.installation_days,
            },
            'pemasangan',
            ['installation_start', 'installation_days'],
            context,
        );
        if (values.production_start && values.installation_start && values.installation_start < values.production_start) {
            context.addIssue({
                code: 'custom',
                path: ['installation_start'],
                message: 'Pemasangan tidak boleh mulai sebelum produksi.',
            });
        }
    });

type ItemValues = z.infer<typeof itemSchema>;

function DateField<T extends FieldValues>({
    form,
    name,
    label,
    required,
    schedule,
}: {
    form: UseFormReturn<T>;
    name: Path<T>;
    label: string;
    required: boolean;
    schedule?: WorkSchedule;
}) {
    return (
        <FormField
            control={form.control}
            name={name}
            render={({ field }) => (
                <FormItem>
                    <FormLabel required={required}>{label}</FormLabel>
                    <FormControl>
                        <DatePicker
                            value={field.value}
                            onChange={field.onChange}
                            placeholder="Pilih tanggal"
                            clearable
                            min={schedule?.project_start ? parseISO(schedule.project_start) : undefined}
                            max={schedule?.project_end ? parseISO(schedule.project_end) : undefined}
                            defaultMonth={schedule?.project_start ? parseISO(schedule.project_start) : undefined}
                        />
                    </FormControl>
                    <FormMessage />
                </FormItem>
            )}
        />
    );
}

function DaysField<T extends FieldValues>({
    form,
    name,
    label,
    required,
    hint,
}: {
    form: UseFormReturn<T>;
    name: Path<T>;
    label: string;
    required: boolean;
    hint?: ReactNode;
}) {
    return (
        <FormField
            control={form.control}
            name={name}
            render={({ field }) => (
                <FormItem>
                    <FormLabel required={required}>{label}</FormLabel>
                    <FormControl>
                        <Input {...field} value={field.value ?? ''} inputMode="numeric" placeholder="mis. 4" className="w-28" />
                    </FormControl>
                    {hint}
                    <FormMessage />
                </FormItem>
            )}
        />
    );
}

/** Small one-tap choices under a field ("Awal proyek · 27 Okt", "4 hari"). */
function QuickPicks({ options }: { options: { label: string; active: boolean; onPick: () => void }[] }) {
    if (options.length === 0) return null;

    return (
        <div className="flex flex-wrap gap-1.5">
            {options.map((option) => (
                <button
                    key={option.label}
                    type="button"
                    onClick={option.onPick}
                    className={cn(
                        'rounded-full px-2.5 py-1 text-xs ring-1 transition-colors',
                        option.active
                            ? 'bg-daiku-yellow text-daiku-dark ring-daiku-yellow'
                            : 'bg-background text-foreground ring-border hover:bg-daiku-yellow-light',
                    )}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}

const LENGTHS = [1, 2, 3, 4, 5, 7, 10, 14];

/** Start + length + quick picks + the computed window, for production or installation. */
function WindowFields({
    form,
    prefix,
    label,
    optional,
    starts,
    schedule,
}: {
    form: UseFormReturn<ItemValues>;
    prefix: 'production' | 'installation';
    label: string;
    optional: boolean;
    starts: { label: string; date: string }[];
    schedule: WorkSchedule;
}) {
    const start = form.watch(`${prefix}_start`);
    const length = form.watch(`${prefix}_days`);
    const startYmd = ymd(start);
    const count = days(length);
    const end = startYmd && count ? endOf(startYmd, count) : null;
    const pastEnd = end && schedule.project_end && end > schedule.project_end;
    // Only days the calendar allows (inside the project).
    const inProject = starts.filter(
        (option) =>
            (!schedule.project_start || option.date >= schedule.project_start) && (!schedule.project_end || option.date <= schedule.project_end),
    );
    const set = (name: `${typeof prefix}_start` | `${typeof prefix}_days`, value: Date | string) =>
        form.setValue(name, value as never, {
            shouldDirty: true,
            shouldValidate: form.formState.isSubmitted,
        });

    return (
        <fieldset className="space-y-3 rounded-lg bg-daiku-gray/50 p-3">
            <legend className="sr-only">{label}</legend>
            <p className="text-sm font-semibold text-foreground">
                {label}
                {optional && <span className="ml-1.5 font-normal text-daiku-muted">— boleh kosong</span>}
            </p>
            <div className="grid gap-3 sm:grid-cols-[1fr_auto]">
                <FormField
                    control={form.control}
                    name={`${prefix}_start`}
                    render={({ field }) => (
                        <FormItem>
                            <FormLabel required={!optional || Boolean(length)}>Mulai</FormLabel>
                            <FormControl>
                                <DatePicker
                                    value={field.value}
                                    onChange={field.onChange}
                                    placeholder="Pilih tanggal"
                                    clearable={optional}
                                    min={schedule.project_start ? parseISO(schedule.project_start) : undefined}
                                    max={schedule.project_end ? parseISO(schedule.project_end) : undefined}
                                    defaultMonth={starts[0] ? parseISO(starts[0].date) : undefined}
                                />
                            </FormControl>
                            <QuickPicks
                                options={inProject.map((option) => ({
                                    label: `${option.label} · ${rangeLabel(option.date, option.date)}`,
                                    active: startYmd === option.date,
                                    onPick: () => set(`${prefix}_start`, parseISO(option.date)),
                                }))}
                            />
                            <FormMessage />
                        </FormItem>
                    )}
                />
                <FormField
                    control={form.control}
                    name={`${prefix}_days`}
                    render={({ field }) => (
                        <FormItem>
                            <FormLabel required={!optional || Boolean(start)}>Lama (hari)</FormLabel>
                            <FormControl>
                                <Input {...field} value={field.value ?? ''} inputMode="numeric" placeholder="mis. 4" className="w-28" />
                            </FormControl>
                            <FormMessage />
                        </FormItem>
                    )}
                />
            </div>
            <QuickPicks
                options={LENGTHS.map((value) => ({
                    label: `${value} hari`,
                    active: count === value,
                    onPick: () => set(`${prefix}_days`, String(value)),
                }))}
            />
            {end && startYmd && (
                <p className={cn('text-sm', pastEnd ? 'text-error-ink' : 'text-foreground')}>
                    {dayLabel(startYmd)} → <span className="font-medium">{dayLabel(end)}</span> · {count} hari
                    {pastEnd && ` — melewati tanggal selesai proyek (${rangeLabel(schedule.project_end, schedule.project_end)})`}
                </p>
            )}
        </fieldset>
    );
}

/**
 * Sprint 31 Sub 07 / Sprint 33 — "Atur Jadwal" for one item, the guided way:
 * a new item opens with a suggested start (the day after the previous item
 * ends, else the project's start) and the previous item's length; one-tap
 * picks for the start and the length; the window is spelled out with its
 * weekday; the calendar opens on the project and greys out days outside it.
 * "Simpan & lanjut" goes straight to the next unscheduled item.
 */
export function ItemScheduleDialog({
    item,
    schedule,
    onOpenChange,
    next = null,
    onNext,
}: {
    item: Item | null;
    schedule: WorkSchedule;
    onOpenChange: (open: boolean) => void;
    /** The next item without a schedule (RAB order) — "Simpan & lanjut". */
    next?: Item | null;
    onNext?: (item: Item) => void;
}) {
    const [saving, setSaving] = useState(false);
    const [suggested, setSuggested] = useState<string | null>(null);
    const thenNext = useRef(false);
    const form = useForm<ItemValues>({
        resolver: zodResolver(itemSchema),
        defaultValues: {},
    });
    const production = ymd(form.watch('production_start'));
    const productionDays = days(form.watch('production_days'));
    const productionEnd = production && productionDays ? endOf(production, productionDays) : null;
    const index = item ? schedule.items.findIndex((row) => row.id === item.id) : -1;

    useEffect(() => {
        if (!item) return;

        if (item.production_start) {
            setSuggested(null);
            form.reset({
                production_start: parseISO(item.production_start),
                production_days: String(lengthOf(item.production_start, item.production_end) ?? ''),
                installation_start: item.installation_start ? parseISO(item.installation_start) : undefined,
                installation_days: String(lengthOf(item.installation_start, item.installation_end) ?? ''),
            });
            return;
        }

        // A new item: filled in already, the Pengawas only checks or adjusts.
        const guess = suggestStart(schedule.items, item.id, schedule);
        setSuggested(guess.reason);
        form.reset({
            production_start: parseISO(guess.date),
            production_days: String(guess.length ?? 4),
            installation_start: undefined,
            installation_days: '',
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [item]);

    const productionStarts = useMemo(() => {
        if (!item) return [];
        const options: { label: string; date: string }[] = [];
        const guess = suggestStart(schedule.items, item.id, schedule);
        if (guess.reason.startsWith('setelah'))
            options.push({
                label: 'Setelah item sebelumnya',
                date: guess.date,
            });
        if (schedule.project_start)
            options.push({
                label: 'Awal proyek',
                date: schedule.project_start,
            });
        if (schedule.today >= (schedule.project_start ?? '') && (!schedule.project_end || schedule.today <= schedule.project_end)) {
            options.push({ label: 'Hari ini', date: schedule.today });
        }

        return options.filter((option, i, all) => all.findIndex((other) => other.date === option.date) === i);
    }, [item, schedule]);

    const installationStarts = productionEnd
        ? [
              {
                  label: 'Setelah produksi selesai',
                  date: plusDays(productionEnd, 1),
              },
          ]
        : [];

    function onSubmit(data: ItemValues) {
        if (!item) return;
        const productionStart = ymd(data.production_start);
        const installation = ymd(data.installation_start);

        router.put(
            route('projects.work-items.schedule', { workItem: item.id }),
            {
                production_start: productionStart,
                production_end: productionStart ? endOf(productionStart, days(data.production_days)!) : null,
                installation_start: installation,
                installation_end: installation ? endOf(installation, days(data.installation_days)!) : null,
            },
            {
                // Inline input — quiet, the sheet is the feedback (Sprint 29 Sub 01).
                ...projectVisit(RELOAD.wages, { quiet: true }),
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => {
                    if (thenNext.current && next && onNext) onNext(next);
                    else onOpenChange(false);
                    thenNext.current = false;
                },
                onError: (errors) => {
                    thenNext.current = false;
                    Object.entries(errors).forEach(([field, message]) =>
                        form.setError((field.startsWith('installation') ? 'installation_start' : 'production_start') as keyof ItemValues, {
                            message,
                        }),
                    );
                },
            },
        );
    }

    return (
        <Dialog open={item !== null} onOpenChange={(open) => !saving && onOpenChange(open)}>
            <ResponsiveDialogContent className="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>
                        Atur Jadwal · {item?.description}
                        {index >= 0 && (
                            <span className="ml-2 text-sm font-normal text-daiku-muted">
                                item {index + 1} dari {schedule.items.length}
                            </span>
                        )}
                    </DialogTitle>
                    <DialogDescription>
                        {item?.volume_label} · {item?.team_lead}
                        {schedule.project_start && schedule.project_end && <> — proyek {rangeLabel(schedule.project_start, schedule.project_end)}</>}
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-3">
                        {suggested && (
                            <p className="rounded-md bg-daiku-yellow-light px-3 py-2 text-sm text-foreground">
                                Sudah diisi otomatis: mulai <span className="font-medium">{suggested}</span>. Periksa, ubah bila perlu, lalu simpan.
                            </p>
                        )}
                        <WindowFields
                            form={form}
                            prefix="production"
                            label="Produksi"
                            optional={false}
                            starts={productionStarts}
                            schedule={schedule}
                        />
                        <WindowFields form={form} prefix="installation" label="Pemasangan" optional starts={installationStarts} schedule={schedule} />
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button type="button" variant="outline" disabled={saving}>
                                    Batal
                                </Button>
                            </DialogClose>
                            <Button type="submit" variant={next ? 'outline' : 'default'} disabled={saving} onClick={() => (thenNext.current = false)}>
                                {saving && !thenNext.current && <Loader2 className="size-4 animate-spin" />}
                                Simpan
                            </Button>
                            {next && onNext && (
                                <Button type="submit" disabled={saving} onClick={() => (thenNext.current = true)}>
                                    {saving && thenNext.current && <Loader2 className="size-4 animate-spin" />}
                                    Simpan & lanjut: {next.description}
                                </Button>
                            )}
                        </DialogFooter>
                    </form>
                </Form>
            </ResponsiveDialogContent>
        </Dialog>
    );
}

// Mirrors ScheduleWorkItemsSequenceRequest.
const sequenceSchema = z
    .object({
        production_start: z.date({
            message: 'Isi tanggal mulai produksi item pertama.',
        }),
        production_days: z.string(),
        gap_days: z.string(),
        installation_start: z.date().optional(),
        installation_days: z.string().optional(),
    })
    .superRefine((values, context) => {
        if (days(values.production_days) === null)
            context.addIssue({
                code: 'custom',
                path: ['production_days'],
                message: 'Isi lama produksi tiap item (hari).',
            });
        const gap = Number(values.gap_days.trim());
        if (values.gap_days.trim() === '' || !Number.isInteger(gap) || gap < 0) {
            context.addIssue({
                code: 'custom',
                path: ['gap_days'],
                message: 'Jeda harus angka hari (0 = bersamaan).',
            });
        }
        windowIssues(
            {
                start: values.installation_start,
                days: values.installation_days,
            },
            'pemasangan',
            ['installation_start', 'installation_days'],
            context,
        );
        if (values.installation_start && values.installation_start < values.production_start) {
            context.addIssue({
                code: 'custom',
                path: ['installation_start'],
                message: 'Pemasangan tidak boleh mulai sebelum produksi.',
            });
        }
    });

type SequenceValues = z.infer<typeof sequenceSchema>;
const SEQUENCE_FIELDS: string[] = ['production_start', 'production_days', 'gap_days', 'installation_start', 'installation_days'];

/**
 * Sprint 31 Sub 07 — "Jadwalkan berurutan": File C's staircase in one step.
 * The chosen items (RAB order) each get N days of production, every next
 * item `jeda` days after the previous one; installation the same way when
 * filled. A preview lists the windows before saving.
 */
export function SequenceScheduleDialog({
    open,
    onOpenChange,
    projectId,
    schedule,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    projectId: number;
    schedule: WorkSchedule;
}) {
    const [saving, setSaving] = useState(false);
    const [selected, setSelected] = useState<number[]>([]);
    const form = useForm<SequenceValues>({
        resolver: zodResolver(sequenceSchema),
        defaultValues: {
            production_days: '4',
            gap_days: '1',
            installation_days: '',
        },
    });
    const values = form.watch();

    useEffect(() => {
        if (open) {
            // The unscheduled items first; when everything has a schedule, all of them (to re-plan).
            const unscheduled = schedule.items.filter((item) => item.status === 'BELUM_DIJADWALKAN').map((item) => item.id);
            const picked = unscheduled.length > 0 ? unscheduled : schedule.items.filter((item) => item.status !== 'SELESAI').map((item) => item.id);
            setSelected(picked);
            const first = picked[0];
            form.reset({
                production_start: first ? parseISO(suggestStart(schedule.items, first, schedule).date) : undefined,
                production_days: '4',
                gap_days: '1',
                installation_start: undefined,
                installation_days: '',
            });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const chosen = schedule.items.filter((item) => selected.includes(item.id));
    const preview = useMemo(() => {
        const start = ymd(values.production_start);
        const length = days(values.production_days);
        const gap = Number((values.gap_days ?? '').trim());
        if (!start || length === null || !Number.isInteger(gap) || gap < 0) return [];
        const install = ymd(values.installation_start);
        const installLength = days(values.installation_days);

        return chosen.map((item, index) => {
            const productionStart = endOf(start, index * gap + 1);
            const installationStart = install && installLength ? endOf(install, index * gap + 1) : null;

            return {
                item,
                production: rangeLabel(productionStart, endOf(productionStart, length)),
                installation: installationStart && installLength ? rangeLabel(installationStart, endOf(installationStart, installLength)) : null,
            };
        });
    }, [chosen, values.production_start, values.production_days, values.gap_days, values.installation_start, values.installation_days]);

    function onSubmit(data: SequenceValues) {
        router.post(
            route('projects.work-items.scheduleSequence', {
                project: projectId,
            }),
            {
                item_ids: chosen.map((item) => item.id),
                production_start: ymd(data.production_start),
                production_days: days(data.production_days),
                gap_days: Number(data.gap_days.trim()),
                installation_start: ymd(data.installation_start),
                installation_days: days(data.installation_days),
            },
            {
                // Several items at once — a step: keeps the pop-up.
                ...projectVisit(RELOAD.wages),
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => onOpenChange(false),
                onError: (errors) =>
                    Object.entries(errors).forEach(([field, message]) =>
                        form.setError((SEQUENCE_FIELDS.includes(field) ? field : 'production_start') as keyof SequenceValues, { message }),
                    ),
            },
        );
    }

    const allSelected = selected.length === schedule.items.length;

    return (
        <Dialog open={open} onOpenChange={(next) => !saving && onOpenChange(next)}>
            <ResponsiveDialogContent className="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Jadwalkan Berurutan</DialogTitle>
                    <DialogDescription>
                        Seperti sel biru di lembar Simulasi Persentasi: tiap item dikerjakan sekian hari, item berikutnya mulai sekian hari (jeda)
                        setelahnya.
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <div className="space-y-2">
                            <Label className="flex items-center gap-2 text-sm font-medium">
                                <Checkbox
                                    checked={allSelected}
                                    onCheckedChange={(checked) => setSelected(checked ? schedule.items.map((item) => item.id) : [])}
                                    aria-label="Pilih semua item"
                                />
                                Item ({selected.length} dipilih, urut seperti RAB)
                            </Label>
                            <ul className="max-h-40 space-y-1 overflow-y-auto rounded-md border border-border p-2 text-sm">
                                {schedule.items.map((item) => (
                                    <li key={item.id}>
                                        <Label className="flex items-center gap-2 font-normal">
                                            <Checkbox
                                                checked={selected.includes(item.id)}
                                                onCheckedChange={(checked) =>
                                                    setSelected((rows) => (checked ? [...rows, item.id] : rows.filter((id) => id !== item.id)))
                                                }
                                            />
                                            <span className="min-w-0 flex-1 truncate">{item.description}</span>
                                            <span className="text-xs text-daiku-muted">{rangeLabel(item.production_start, item.production_end)}</span>
                                        </Label>
                                    </li>
                                ))}
                            </ul>
                        </div>

                        <div className="grid gap-3 sm:grid-cols-[1fr_auto_auto]">
                            <DateField form={form} name="production_start" label="Mulai produksi item pertama" required schedule={schedule} />
                            <DaysField form={form} name="production_days" label="Lama (hari)" required />
                            <DaysField form={form} name="gap_days" label="Jeda (hari)" required />
                        </div>
                        <div className="grid gap-3 sm:grid-cols-[1fr_auto]">
                            <DateField
                                form={form}
                                name="installation_start"
                                label="Mulai pemasangan item pertama"
                                required={Boolean(values.installation_days)}
                                schedule={schedule}
                            />
                            <DaysField form={form} name="installation_days" label="Lama (hari)" required={Boolean(values.installation_start)} />
                        </div>

                        {preview.length > 0 && (
                            <div className="rounded-md bg-daiku-gray/60 p-3 text-sm">
                                <p className="mb-1 text-xs font-medium text-daiku-muted">Hasilnya</p>
                                <ul className="max-h-40 space-y-0.5 overflow-y-auto">
                                    {preview.map((row) => (
                                        <li key={row.item.id} className="flex flex-wrap justify-between gap-x-3">
                                            <span className="min-w-0 truncate text-foreground">{row.item.description}</span>
                                            <span className="text-daiku-muted tabular-nums">
                                                Produksi {row.production}
                                                {row.installation && ` · Pasang ${row.installation}`}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}

                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline" disabled={saving}>
                                    Batal
                                </Button>
                            </DialogClose>
                            <Button type="submit" disabled={saving || chosen.length === 0}>
                                {saving && <Loader2 className="size-4 animate-spin" />}
                                Jadwalkan {chosen.length} item
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </ResponsiveDialogContent>
        </Dialog>
    );
}
