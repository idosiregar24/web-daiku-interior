import { Button } from '@/Components/ui/button';
import { DatePicker } from '@/Components/shared/DatePicker';
import {
    Dialog,
    DialogClose,
    DialogContent,
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
import { Input } from '@/Components/ui/input';
import type { Milestone } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { format, startOfDay } from 'date-fns';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

// Mirrors Store/UpdateMilestoneRequest (Sprint 23 K17: start ≤ end).
const schema = z
    .object({
        name: z.string().trim().min(1, 'Nama timeline wajib diisi').max(255, 'Nama timeline maksimal 255 karakter'),
        start_date: z.date({ message: 'Tanggal mulai timeline wajib diisi' }),
        target_date: z.date({ message: 'Tanggal selesai timeline wajib diisi' }),
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
    /** Keep the host page's state (e.g. an unsaved TM form next to it) when the timeline saves. */
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
export function MilestoneFormDialog({ open, onOpenChange, projectId, editing, suggestedStart, preserveState = false }: MilestoneFormDialogProps) {
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { name: '', start_date: undefined, target_date: undefined },
    });

    useEffect(() => {
        if (!open) return;

        form.reset({
            name: editing?.name ?? '',
            start_date: editing?.start_date ? new Date(editing.start_date) : editing ? undefined : suggestedStart,
            target_date: editing ? new Date(editing.target_date) : undefined,
        });
    }, [open, editing]);

    function onSubmit(values: FormValues) {
        const onError = (errors: Record<string, string>) => {
            Object.entries(errors).forEach(([field, message]) => {
                form.setError(field as keyof FormValues, { message });
            });
        };
        const onSuccess = () => onOpenChange(false);
        const options = { onError, onSuccess, preserveState, preserveScroll: preserveState };
        const payload = {
            name: values.name,
            start_date: format(values.start_date, 'yyyy-MM-dd'),
            target_date: format(values.target_date, 'yyyy-MM-dd'),
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
            <DialogContent className="max-w-md">
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
