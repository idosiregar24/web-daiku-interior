import { Button } from '@/Components/ui/button';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/Components/ui/sheet';
import { Textarea } from '@/Components/ui/textarea';
import { cn } from '@/lib/utils';
import type { TaskStatus } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import type { FieldTask } from './TaskCard';

type ManualStatus = Exclude<TaskStatus, 'OVER'>;

// OVER is system-computed only (PRD §4.5) — same list as UpdateTaskStatusRequest / StoreDailyTaskFormRequest.
const STATUS_BUTTONS: { value: ManualStatus; label: string }[] = [
    { value: 'PENDING', label: 'Belum mulai' },
    { value: 'ONPROGRESS', label: 'Sedang dikerjakan' },
    { value: 'PENGECEKAN', label: 'Minta dicek' },
    { value: 'DONE', label: 'Selesai' },
];

const schema = z.object({
    status: z.enum(['PENDING', 'ONPROGRESS', 'PENGECEKAN', 'DONE'], { message: 'Pilih status tugas.' }),
    kendala: z.string().optional(),
    note: z.string().optional(),
});

type FormValues = z.infer<typeof schema>;

/**
 * Sprint 13 H3 — tap a task card, update it in one go: status as big
 * buttons, kendala & catatan, one wide "Simpan". When today's daily form
 * is still due, Simpan sends the form (DailyTaskFormService records it
 * AND updates the task, one transaction); otherwise it only updates the
 * status. Only status/kendala/catatan ever leave the phone — title,
 * description and due date aren't sent at all (task immutability, PRD §4.5).
 */
export function TaskActionSheet({
    task,
    canSubmitDailyForm,
    onOpenChange,
}: {
    task: FieldTask | null;
    /** Working day, before the cutoff (TodayController / TaskController). */
    canSubmitDailyForm: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [saving, setSaving] = useState(false);
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { status: 'ONPROGRESS', kendala: '', note: '' },
    });

    useEffect(() => {
        if (task) {
            form.reset({
                status: task.status === 'OVER' ? 'ONPROGRESS' : task.status,
                kendala: task.kendala ?? '',
                note: task.note ?? '',
            });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [task]);

    const sendsDailyForm = Boolean(task && canSubmitDailyForm && task.has_form_today === false);

    function onSubmit(values: FormValues) {
        if (!task) return;

        const options = {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onSuccess: () => onOpenChange(false),
            onError: (errors: Record<string, string>) => {
                const first = Object.values(errors)[0];
                form.setError('root', { message: first ?? 'Gagal menyimpan, coba lagi.' });
            },
        };

        if (sendsDailyForm) {
            router.post(
                route('daily-forms.store', { task: task.id }),
                { status: values.status, kendala: values.kendala || null, notes: values.note || null },
                options,
            );
        } else {
            router.patch(
                route('tasks.updateStatus', { task: task.id }),
                { status: values.status, kendala: values.kendala || null, note: values.note || null },
                options,
            );
        }
    }

    return (
        <Sheet open={task !== null} onOpenChange={onOpenChange}>
            <SheetContent side="bottom" className="max-h-[92svh] gap-0 overflow-y-auto rounded-t-2xl pb-[env(safe-area-inset-bottom)]">
                <SheetHeader className="pb-2">
                    <SheetTitle className="text-base">{task?.title}</SheetTitle>
                    <SheetDescription>
                        {task?.project?.name}
                        {sendsDailyForm && ' · Simpan sekaligus mengisi form harian hari ini.'}
                    </SheetDescription>
                </SheetHeader>

                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="flex flex-col gap-4 px-4 pb-4">
                        <FormField
                            control={form.control}
                            name="status"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Status</FormLabel>
                                    <div role="radiogroup" aria-label="Status tugas" className="grid grid-cols-2 gap-2">
                                        {STATUS_BUTTONS.map((option) => {
                                            const selected = field.value === option.value;

                                            return (
                                                <button
                                                    key={option.value}
                                                    type="button"
                                                    role="radio"
                                                    aria-checked={selected}
                                                    onClick={() => field.onChange(option.value)}
                                                    className={cn(
                                                        'min-h-14 rounded-xl px-3 text-sm font-medium ring-1 transition-colors',
                                                        selected
                                                            ? 'bg-daiku-yellow text-daiku-dark ring-daiku-yellow'
                                                            : 'bg-background text-foreground ring-border active:bg-daiku-gray',
                                                    )}
                                                >
                                                    {option.label}
                                                </button>
                                            );
                                        })}
                                    </div>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="kendala"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Kendala (kalau ada)</FormLabel>
                                    <FormControl>
                                        <Textarea {...field} rows={2} placeholder="Mis. bahan belum datang" />
                                    </FormControl>
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="note"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Catatan (kalau ada)</FormLabel>
                                    <FormControl>
                                        <Textarea {...field} rows={2} />
                                    </FormControl>
                                </FormItem>
                            )}
                        />
                        {form.formState.errors.root?.message && (
                            <p className="text-sm text-error-ink">{form.formState.errors.root.message}</p>
                        )}
                        <Button type="submit" size="lg" className="h-12 w-full text-base" disabled={saving}>
                            {saving ? 'Menyimpan…' : 'Simpan'}
                        </Button>
                    </form>
                </Form>
            </SheetContent>
        </Sheet>
    );
}
