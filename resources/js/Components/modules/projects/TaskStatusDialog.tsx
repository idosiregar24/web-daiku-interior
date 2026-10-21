import { Button } from '@/Components/ui/button';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import { type TaskReload, taskVisit } from '@/Components/modules/tasks/taskSave';
import { MANUAL_TASK_STATUSES, TASK_STATUS_LABEL } from '@/lib/taskLabels';
import type { Task, TaskStatus } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const schema = z.object({
    status: z.enum(MANUAL_TASK_STATUSES as [TaskStatus, ...TaskStatus[]]),
    kendala: z.string().optional(),
    note: z.string().optional(),
});

type FormValues = z.infer<typeof schema>;

interface TaskStatusDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    task: Task | null;
    /**
     * Sprint 29 L7 — the page props a status change reloads (Detail Proyek:
     * `RELOAD.tasks`); then the save is partial and quiet (the row's chip is
     * the feedback). Omitted: full reload with the success pop-up.
     */
    reload?: TaskReload;
}

/** Task immutability (CLAUDE.md golden rule #6) — only status/kendala/note, enforced server-side by TaskPolicy::updateStatus(). */
export function TaskStatusDialog({ open, onOpenChange, task, reload }: TaskStatusDialogProps) {
    const [saving, setSaving] = useState(false);
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { status: 'PENDING', kendala: '', note: '' },
    });

    useEffect(() => {
        if (open && task) {
            form.reset({
                status: task.status === 'OVER' ? 'PENDING' : task.status,
                kendala: task.kendala ?? '',
                note: task.note ?? '',
            });
        }
    }, [open, task]);

    function onSubmit(values: FormValues) {
        if (!task || saving) return;

        const onError = (errors: Record<string, string>) => {
            Object.entries(errors).forEach(([field, message]) => {
                form.setError(field as keyof FormValues, { message });
            });
        };

        router.patch(route('tasks.updateStatus', { task: task.id }), values, {
            ...(reload ? taskVisit(reload, { quiet: true }) : {}),
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onError,
            onSuccess: () => onOpenChange(false),
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <ResponsiveDialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Ubah Status</DialogTitle>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <p className="text-sm text-daiku-muted">{task?.title}</p>
                        <FormField
                            control={form.control}
                            name="status"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Status</FormLabel>
                                    <Select value={field.value} onValueChange={field.onChange}>
                                        <FormControl>
                                            <SelectTrigger className="w-full">
                                                <SelectValue />
                                            </SelectTrigger>
                                        </FormControl>
                                        <SelectContent>
                                            {MANUAL_TASK_STATUSES.map((option) => (
                                                <SelectItem key={option} value={option}>
                                                    {TASK_STATUS_LABEL[option]}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="kendala"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Kendala</FormLabel>
                                    <FormControl>
                                        <Textarea {...field} rows={2} placeholder="Hambatan yang dihadapi" />
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
                                    <FormLabel>Catatan</FormLabel>
                                    <FormControl>
                                        <Textarea {...field} rows={2} />
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
                                {saving ? 'Menyimpan…' : 'Simpan'}
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </ResponsiveDialogContent>
        </Dialog>
    );
}
