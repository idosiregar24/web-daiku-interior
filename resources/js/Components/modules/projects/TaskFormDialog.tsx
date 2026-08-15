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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import type { Milestone, TaskPriority, User } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const PRIORITY_OPTIONS: TaskPriority[] = ['HIGH', 'MEDIUM', 'LOW'];

const schema = z.object({
    milestone_id: z.string().optional(),
    title: z.string().min(1, 'Judul task wajib diisi'),
    description: z.string().optional(),
    assignee_id: z.string().min(1, 'Tukang wajib dipilih'),
    due_date: z.date({ message: 'Tanggal jatuh tempo wajib diisi' }),
    priority: z.enum(PRIORITY_OPTIONS as [TaskPriority, ...TaskPriority[]]),
    rate_per_task: z
        .string()
        .optional()
        .refine((v) => !v || (!isNaN(Number(v)) && Number(v) >= 0), 'Rate tidak valid'),
});

type FormValues = z.infer<typeof schema>;

const EMPTY_VALUES: FormValues = {
    milestone_id: '',
    title: '',
    description: '',
    assignee_id: '',
    due_date: undefined as unknown as Date,
    priority: 'MEDIUM',
    rate_per_task: '',
};

interface TaskFormDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    projectId: number;
    milestones: Milestone[];
    fieldStaff: Pick<User, 'id' | 'name'>[];
}

/**
 * "Task assignment form PM: pilih tukang, due date, rate per task"
 * (.claude/plan/sprint-02.md Week 4). Scoped to a Project — reached from
 * Projects/Show.tsx's Task tab, mirroring MilestoneFormDialog's pattern.
 */
export function TaskFormDialog({ open, onOpenChange, projectId, milestones, fieldStaff }: TaskFormDialogProps) {
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: EMPTY_VALUES,
    });

    useEffect(() => {
        if (open) {
            form.reset(EMPTY_VALUES);
        }
    }, [open]);

    function onSubmit(values: FormValues) {
        const onError = (errors: Record<string, string>) => {
            Object.entries(errors).forEach(([field, message]) => {
                form.setError(field as keyof FormValues, { message });
            });
        };

        router.post(
            route('tasks.store', { project: projectId }),
            {
                milestone_id: values.milestone_id ? Number(values.milestone_id) : null,
                title: values.title,
                description: values.description || null,
                assignee_id: Number(values.assignee_id),
                due_date: format(values.due_date, 'yyyy-MM-dd'),
                priority: values.priority,
                rate_per_task: values.rate_per_task ? Number(values.rate_per_task) : null,
            },
            { onError, onSuccess: () => onOpenChange(false) },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Tambah Task</DialogTitle>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="title"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Judul Task</FormLabel>
                                    <FormControl>
                                        <Input {...field} autoFocus placeholder="mis. Pasang kusen lantai 2" />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="description"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Deskripsi (opsional)</FormLabel>
                                    <FormControl>
                                        <Textarea {...field} rows={2} />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                control={form.control}
                                name="assignee_id"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Tukang</FormLabel>
                                        <Select value={field.value} onValueChange={field.onChange}>
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue placeholder="Pilih tukang" />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                {fieldStaff.map((staff) => (
                                                    <SelectItem key={staff.id} value={String(staff.id)}>
                                                        {staff.name}
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
                                name="milestone_id"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Milestone (opsional)</FormLabel>
                                        <Select
                                            value={field.value || 'none'}
                                            onValueChange={(value) => field.onChange(value === 'none' ? '' : value)}
                                        >
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue placeholder="Pilih milestone" />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                <SelectItem value="none">—</SelectItem>
                                                {milestones.map((milestone) => (
                                                    <SelectItem key={milestone.id} value={String(milestone.id)}>
                                                        {milestone.name}
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
                                name="due_date"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Jatuh Tempo</FormLabel>
                                        <FormControl>
                                            <DatePicker value={field.value} onChange={field.onChange} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="priority"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Prioritas</FormLabel>
                                        <Select value={field.value} onValueChange={field.onChange}>
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                {PRIORITY_OPTIONS.map((option) => (
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
                        </div>
                        <FormField
                            control={form.control}
                            name="rate_per_task"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Rate per Task (Rp, opsional)</FormLabel>
                                    <FormControl>
                                        <Input type="number" min="0" step="0.01" {...field} />
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
