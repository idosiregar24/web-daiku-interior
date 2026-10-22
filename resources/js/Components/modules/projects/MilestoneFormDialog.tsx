import { Button } from '@/Components/ui/button';
import { DatePicker } from '@/Components/shared/DatePicker';
import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import {
    Dialog,
    DialogClose,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import {
    Form,
    FormControl,
    FormField,
    FormItem,
    FormLabel,
    FormMessage,
} from '@/Components/ui/form';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { projectVisit, RELOAD } from '@/lib/projectReload';
import type { Milestone, PageProps, TimelineItem } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router, usePage } from '@inertiajs/react';
import { format, startOfDay } from 'date-fns';
import { Loader2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

// Mirrors Store/UpdateMilestoneRequest (Sprint 23 K17: start ≤ end).
const schema = z
    .object({
        name: z.string().trim().min(1, 'Nama timeline wajib diisi').max(255, 'Nama timeline maksimal 255 karakter'),
        start_date: z.date({ message: 'Tanggal mulai timeline wajib diisi' }),
        target_date: z.date({ message: 'Tanggal selesai timeline wajib diisi' }),
        work_item_ids: z.array(z.number()),
    })
    .refine((values) => !values.start_date || !values.target_date || startOfDay(values.target_date) >= startOfDay(values.start_date), {
        message: 'Tanggal selesai tidak boleh sebelum tanggal mulai',
        path: ['target_date'],
    });

type FormValues = z.infer<typeof schema>;

interface MilestoneFormDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    projectId: number;
    /** Milestone being edited, or null for create. */
    editing: Milestone | null;
    /** Suggested start for a new timeline — the day after the last one ends (or the project's start). */
    suggestedStart?: Date;
    /**
     * Keep the host page's state (e.g. an unsaved TM form next to it) when the timeline saves.
     * Sprint 29 Sub 01 — every save is now a partial visit that preserves state, so this is always on.
     */
    preserveState?: boolean;
}

/** Sprint 23 K17 — a new timeline starts the day after the last one ends (or at the project's start). */
export function suggestTimelineStart(milestones: Milestone[], projectStart: string | null | undefined): Date {
    const last = [...milestones].sort((a, b) => a.order - b.order || a.target_date.localeCompare(b.target_date)).pop();

    if (last) {
        const next = new Date(last.target_date);
        next.setDate(next.getDate() + 1);

        return next;
    }

    return projectStart ? new Date(projectStart) : new Date();
}

/**
 * Add/edit modal for a project's Timeline (a milestone in code — Sprint 23
 * K16) — PRD §7.1 "Milestone" row (PM CRUD). K17: start and end date.
 */
export function MilestoneFormDialog({ open, onOpenChange, projectId, editing, suggestedStart }: MilestoneFormDialogProps) {
    const [saving, setSaving] = useState(false);
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { name: '', start_date: undefined, target_date: undefined, work_item_ids: [] },
    });
    // Sprint 35 — the RAB items (Detail Proyek's `timelineItems`; empty for a project without RAB items).
    const items = usePage<PageProps<{ timelineItems?: TimelineItem[] }>>().props.timelineItems ?? [];

    useEffect(() => {
        if (!open) return;

        form.reset({
            name: editing?.name ?? '',
            start_date: editing?.start_date ? new Date(editing.start_date) : editing ? undefined : suggestedStart,
            target_date: editing ? new Date(editing.target_date) : undefined,
            work_item_ids: editing?.work_items?.map((item) => item.id) ?? [],
        });
    }, [open, editing]);

    function onSubmit(values: FormValues) {
        const onError = (errors: Record<string, string>) => {
            Object.entries(errors).forEach(([field, message]) => {
                form.setError(field as keyof FormValues, { message });
            });
        };
        const onSuccess = () => onOpenChange(false);
        // Sprint 29 Sub 01 — rendered only on Detail Proyek (Timeline tab, TM card): reload the timeline side only;
        // quiet — the new / changed bar on the timeline is the feedback.
        const options = {
            ...projectVisit(RELOAD.milestones, { quiet: true }),
            onError,
            onSuccess,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
        };
        const payload = {
            name: values.name,
            start_date: format(values.start_date, 'yyyy-MM-dd'),
            target_date: format(values.target_date, 'yyyy-MM-dd'),
            ...(items.length > 0 ? { work_item_ids: values.work_item_ids } : {}),
        };

        if (editing) {
            // UpdateMilestoneRequest requires `status`; this form doesn't change it, so send it back as-is.
            router.put(route('milestones.update', { milestone: editing.id }), { ...payload, status: editing.status }, options);
        } else {
            router.post(route('milestones.store', { project: projectId }), payload, options);
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <ResponsiveDialogContent className={items.length > 0 ? 'sm:max-w-2xl' : 'max-w-md'}>
                <DialogHeader>
                    <DialogTitle>{editing ? 'Ubah Timeline' : 'Tambah Timeline'}</DialogTitle>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="name"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Nama Timeline</FormLabel>
                                    <FormControl>
                                        <Input {...field} autoFocus placeholder="mis. Produksi Kitchen Set" />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <FormField
                                control={form.control}
                                name="start_date"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Tanggal Mulai</FormLabel>
                                        <FormControl>
                                            <DatePicker value={field.value} onChange={field.onChange} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="target_date"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Tanggal Selesai</FormLabel>
                                        <FormControl>
                                            <DatePicker value={field.value} onChange={field.onChange} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        {items.length > 0 && (
                            <FormField
                                control={form.control}
                                name="work_item_ids"
                                render={({ field }) => (
                                    <FormItem>
                                        <ItemPicker items={items} editingId={editing?.id ?? null} value={field.value} onChange={field.onChange} />
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        )}
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    Batal
                                </Button>
                            </DialogClose>
                            <Button type="submit" disabled={saving}>
                                {saving && <Loader2 className="size-4 animate-spin" />}
                                Simpan
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </ResponsiveDialogContent>
        </Dialog>
    );
}

/**
 * Sprint 35 — "Item RAB di timeline ini": the timeline is the items'
 * schedule (their blocks on the Lembar Progres). Items already in another
 * timeline say so (an item may be in several — produksi, pemasangan); items
 * with a wage first, since they make the progress.
 */
function ItemPicker({
    items,
    editingId,
    value,
    onChange,
}: {
    items: TimelineItem[];
    editingId: number | null;
    value: number[];
    onChange: (ids: number[]) => void;
}) {
    const [query, setQuery] = useState('');
    const shown = useMemo(() => {
        const needle = query.trim().toLocaleLowerCase('id-ID');

        return items.filter((item) => needle === '' || item.description.toLocaleLowerCase('id-ID').includes(needle));
    }, [items, query]);
    const unplaced = items.filter((item) => item.has_wage && item.milestone_ids.filter((id) => id !== editingId).length === 0 && !value.includes(item.id));
    const toggle = (id: number, on: boolean) => onChange(on ? [...value, id] : value.filter((row) => row !== id));
    let lastSection = '';

    return (
        <div className="space-y-2">
            <div className="flex flex-wrap items-end justify-between gap-2">
                <div>
                    <FormLabel>Item RAB di timeline ini</FormLabel>
                    <p className="text-xs text-muted-foreground">Jadwal item di Lembar Progres mengikuti tanggal timeline ini.</p>
                </div>
                <span className="text-xs font-medium text-muted-foreground tabular-nums">{value.length} dipilih</span>
            </div>
            <div className="flex flex-wrap gap-2">
                <Input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Cari item…" className="h-8 max-w-60" />
                {unplaced.length > 0 && (
                    <Button type="button" size="sm" variant="outline" onClick={() => onChange([...value, ...unplaced.map((item) => item.id)])}>
                        Pilih {unplaced.length} item yang belum punya timeline
                    </Button>
                )}
            </div>
            <ul className="max-h-64 overflow-y-auto rounded-lg ring-1 ring-border">
                {shown.map((item) => {
                    const elsewhere = item.milestone_ids.filter((id) => id !== editingId).length;
                    const header = item.section !== lastSection ? item.section : null;
                    lastSection = item.section;

                    return (
                        <li key={item.id}>
                            {header && <p className="bg-daiku-yellow-light px-3 py-1 text-[11px] font-semibold tracking-wide text-daiku-muted uppercase">{header}</p>}
                            <label className="flex cursor-pointer items-center gap-3 border-t border-border px-3 py-2 text-sm first:border-t-0 hover:bg-daiku-gray/50">
                                <Checkbox checked={value.includes(item.id)} onCheckedChange={(on) => toggle(item.id, on === true)} />
                                <span className="min-w-0 flex-1">
                                    <span className="text-foreground">{item.description}</span>
                                    <span className="ml-1.5 text-xs text-muted-foreground">{item.volume_label}</span>
                                </span>
                                <span className="shrink-0 text-xs text-muted-foreground">
                                    {!item.has_wage ? 'tanpa upah' : elsewhere > 0 ? `di ${elsewhere} timeline lain` : 'belum ada timeline'}
                                </span>
                            </label>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
