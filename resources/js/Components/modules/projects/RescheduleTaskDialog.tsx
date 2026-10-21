import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogClose, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { type TaskReload, taskVisit } from '@/Components/modules/tasks/taskSave';
import { formatDate } from '@/lib/format';
import { isWorkDay, NON_WORK_DAY_MESSAGE } from '@/lib/workDays';
import type { Task } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

// Mirrors RescheduleTargetRequest (single task) + TaskService's working-day rule.
const schema = z.object({
    due_date: z.string().min(1, 'Tanggal baru wajib diisi.').refine(isWorkDay, NON_WORK_DAY_MESSAGE),
    note: z.string().max(255, 'Catatan maksimal 255 karakter.').optional(),
});

/**
 * Sprint 24 D9 — "Geser": move one unfinished daily target to another day;
 * logged on the task and the Tukang is told. Sprint 29 Sub 05 (F7) — any
 * working day: a day in another timeline moves the target into it (server).
 * Sprint 29 L7 — `reload`: the page props it reloads (Detail Proyek:
 * `RELOAD.tasks`); omitted = full reload. A one-off decision with a reason,
 * so the success pop-up stays.
 */
export function RescheduleTaskDialog({
    task,
    onOpenChange,
    reload,
}: {
    task: Task | null;
    onOpenChange: (open: boolean) => void;
    reload?: TaskReload;
}) {
    const [saving, setSaving] = useState(false);
    const form = useForm<z.infer<typeof schema>>({ resolver: zodResolver(schema), defaultValues: { due_date: '', note: '' } });
    const from = task?.milestone?.start_date?.slice(0, 10);
    const to = task?.milestone?.target_date?.slice(0, 10);

    useEffect(() => {
        if (task) form.reset({ due_date: task.due_date?.slice(0, 10) ?? '', note: '' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [task]);

    function onSubmit(values: z.infer<typeof schema>) {
        if (!task || saving) return;
        router.patch(route('tasks.reschedule', { task: task.id }), { due_date: values.due_date, note: values.note || null }, {
            ...taskVisit(reload),
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onSuccess: () => onOpenChange(false),
            onError: (errors) => Object.entries(errors).forEach(([key, message]) => form.setError(key === 'note' ? 'note' : 'due_date', { message })),
        });
    }

    return (
        <Dialog open={task !== null} onOpenChange={onOpenChange}>
            <ResponsiveDialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Geser Tanggal Target</DialogTitle>
                    <DialogDescription>
                        {task?.title}
                        {from && to && ` — timeline ${task?.milestone?.name}: ${formatDate(from)} – ${formatDate(to)}. Hari di timeline lain memindah target ke timeline itu.`}
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="due_date"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Tanggal baru</FormLabel>
                                    <FormControl>
                                        <Input type="date" {...field} />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="note"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Alasan</FormLabel>
                                    <FormControl>
                                        <Input {...field} maxLength={255} placeholder="Mis. bahan HPL datang Kamis" />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    Batal
                                </Button>
                            </DialogClose>
                            <Button type="submit" disabled={saving}>
                                {saving && <Loader2 className="size-4 animate-spin" />}
                                {saving ? 'Menggeser…' : 'Geser'}
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </ResponsiveDialogContent>
        </Dialog>
    );
}
