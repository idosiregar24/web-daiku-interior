import { Button } from '@/Components/ui/button';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import type { Task, TaskStatus } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

// OVER excluded — system-computed only (PRD §4.5), never a manual choice.
// See TaskService::updateStatus()/UpdateTaskStatusRequest.
const STATUS_OPTIONS: Exclude<TaskStatus, 'OVER'>[] = ['PENDING', 'ONPROGRESS', 'PENGECEKAN', 'DONE'];

const schema = z.object({
    status: z.enum(STATUS_OPTIONS as [TaskStatus, ...TaskStatus[]]),
    kendala: z.string().optional(),
    note: z.string().optional(),
});

type FormValues = z.infer<typeof schema>;

interface TaskStatusDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    task: Task | null;
}

/** Task immutability (CLAUDE.md golden rule #6) — only status/kendala/note, enforced server-side by TaskPolicy::updateStatus(). */
export function TaskStatusDialog({ open, onOpenChange, task }: TaskStatusDialogProps) {
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
        if (!task) return;

        const onError = (errors: Record<string, string>) => {
            Object.entries(errors).forEach(([field, message]) => {
                form.setError(field as keyof FormValues, { message });
            });
        };

        router.patch(route('tasks.updateStatus', { task: task.id }), values, {
            onError,
            onSuccess: () => onOpenChange(false),
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Update Status Task</DialogTitle>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <p className="text-sm text-daiku-muted">{task?.title}</p>
                        <FormField
                            control={form.control}
                            name="status"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Status</FormLabel>
                                    <Select value={field.value} onValueChange={field.onChange}>
                                        <FormControl>
                                            <SelectTrigger className="w-full">
                                                <SelectValue />
                                            </SelectTrigger>
                                        </FormControl>
                                        <SelectContent>
                                            {STATUS_OPTIONS.map((option) => (
                                                <SelectItem key={option} value={option}>
                                                    {option}
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
                                    <FormLabel>Kendala (opsional)</FormLabel>
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
                                    <FormLabel>Catatan (opsional)</FormLabel>
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
