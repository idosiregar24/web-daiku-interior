import { Button } from '@/Components/ui/button';
import { DatePicker } from '@/Components/shared/DatePicker';
import { Notice } from '@/Components/shared/Notice';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
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
import type { Milestone, Task, TaskPriority, User } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { useEffect, useMemo } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const PRIORITY_OPTIONS: TaskPriority[] = ['HIGH', 'MEDIUM', 'LOW'];

// Mirrors StoreTaskRequest/UpdateTaskRequest. Which milestones and tukang
// are selectable (same project, not COMPLETED; active Field Staff) is
// narrowed in the option lists below — the server re-checks both.
const schema = z.object({
    milestone_id: z.string().optional(),
    title: z.string().trim().min(1, 'Judul task wajib diisi').max(150, 'Judul task maksimal 150 karakter'),
    description: z.string().optional(),
    assignee_id: z.string().min(1, 'Tukang wajib dipilih'),
    due_date: z.date({ message: 'Tanggal jatuh tempo wajib diisi' }),
    priority: z.enum(PRIORITY_OPTIONS as [TaskPriority, ...TaskPriority[]]),
    rate_per_task: z
        .string()
        .optional()
        .refine((v) => !v || (!isNaN(Number(v)) && Number(v) >= 0), 'Rate tidak valid')
        .refine((v) => !v || Number(v) <= 9999999999.99, 'Rate terlalu besar'),
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

const FIELDS = Object.keys(EMPTY_VALUES);

/** What a DONE-but-unpaid task still allows — TaskService::update()'s DONE_EDITABLE. */
const DONE_EDITABLE: (keyof FormValues)[] = ['title', 'description', 'rate_per_task'];

interface TaskFormDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Project a new task goes into — ignored when editing (the task carries its own). */
    projectId?: number;
    /** Task being edited, or null/undefined for create. */
    editing?: Task | null;
    /** May span several projects (Tasks/Index) — filtered to the task's project here. */
    milestones: Pick<Milestone, 'id' | 'name' | 'project_id' | 'status'>[];
    fieldStaff: Pick<User, 'id' | 'name' | 'is_active'>[];
}

/**
 * "Task assignment form PM: pilih tukang, due date, rate per task"
 * (.claude/plan/sprint-02.md Week 4) and, since Sprint 9, "Edit Task" —
 * PM only (TaskPolicy::update()). Create is reached from Projects/Show.tsx's
 * Task tab; edit from there and from Tasks/Index.tsx.
 */
export function TaskFormDialog({ open, onOpenChange, projectId, editing = null, milestones, fieldStaff }: TaskFormDialogProps) {
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: EMPTY_VALUES,
    });

    const targetProjectId = editing?.project_id ?? projectId;
    const isDone = editing?.status === 'DONE';
    const isPaid = isDone && !!editing?.is_wage_paid;

    // Same project; a milestone that already passed QA only stays listed
    // for the task that already sits in it (see UpdateTaskRequest).
    const milestoneOptions = useMemo(
        () =>
            milestones.filter(
                (milestone) =>
                    milestone.project_id === targetProjectId &&
                    (milestone.status !== 'COMPLETED' || milestone.id === editing?.milestone_id),
            ),
        [milestones, targetProjectId, editing],
    );

    // Active tukang only — plus a deactivated one who already holds this task.
    const staffOptions = useMemo(
        () => fieldStaff.filter((staff) => staff.is_active !== false || staff.id === editing?.assignee_id),
        [fieldStaff, editing],
    );

    useEffect(() => {
        if (!open) return;

        if (editing) {
            form.reset({
                milestone_id: editing.milestone_id ? String(editing.milestone_id) : '',
                title: editing.title,
                description: editing.description ?? '',
                assignee_id: editing.assignee_id ? String(editing.assignee_id) : '',
                due_date: editing.due_date ? new Date(editing.due_date) : (undefined as unknown as Date),
                priority: editing.priority,
                rate_per_task: editing.rate_per_task !== null ? String(Number(editing.rate_per_task)) : '',
            });
        } else {
            form.reset(EMPTY_VALUES);
        }
    }, [open, editing]);

    function isLocked(field: keyof FormValues) {
        return isPaid || (isDone && !DONE_EDITABLE.includes(field));
    }

    function onSubmit(values: FormValues) {
        const onError = (errors: Record<string, string>) => {
            Object.entries(errors).forEach(([field, message]) => {
                if (FIELDS.includes(field)) {
                    form.setError(field as keyof FormValues, { message });
                } else {
                    form.setError('root.server', { message });
                }
            });
        };

        const payload = {
            milestone_id: values.milestone_id ? Number(values.milestone_id) : null,
            title: values.title,
            description: values.description || null,
            assignee_id: Number(values.assignee_id),
            due_date: format(values.due_date, 'yyyy-MM-dd'),
            priority: values.priority,
            rate_per_task: values.rate_per_task ? Number(values.rate_per_task) : null,
        };
        const options = { preserveScroll: true, onError, onSuccess: () => onOpenChange(false) };

        if (editing) {
            router.put(route('tasks.update', { task: editing.id }), payload, options);
        } else if (targetProjectId) {
            router.post(route('tasks.store', { project: targetProjectId }), payload, options);
        }
    }

    const serverError = form.formState.errors.root?.server?.message;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{editing ? 'Edit Task' : 'Tambah Task'}</DialogTitle>
                    {editing?.project && <DialogDescription>Proyek {editing.project.name}</DialogDescription>}
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        {serverError && <Notice tone="error">{serverError}</Notice>}
                        {isPaid ? (
                            <Notice tone="info">Upah task ini sudah dibayar — task terkunci dan tidak bisa diubah lagi.</Notice>
                        ) : isDone ? (
                            <Notice tone="info">Task sudah DONE — hanya judul, deskripsi, dan rate yang masih bisa diubah.</Notice>
                        ) : (
                            editing?.status === 'OVER' && (
                                <Notice tone="warning">
                                    Task ini melewati deadline (OVER). Mundurkan jatuh tempo ke hari ini atau setelahnya untuk
                                    mengembalikan statusnya ke {editing.pre_overdue_status ?? 'PENDING'}.
                                </Notice>
                            )
                        )}
                        <FormField
                            control={form.control}
                            name="title"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Judul Task</FormLabel>
                                    <FormControl>
                                        <Input {...field} autoFocus disabled={isLocked('title')} placeholder="mis. Pasang kusen lantai 2" />
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
                                        <Textarea {...field} rows={2} disabled={isLocked('description')} />
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
                                        <Select value={field.value} onValueChange={field.onChange} disabled={isLocked('assignee_id')}>
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue placeholder="Pilih tukang" />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                {staffOptions.map((staff) => (
                                                    <SelectItem key={staff.id} value={String(staff.id)}>
                                                        {staff.is_active === false ? `${staff.name} (nonaktif)` : staff.name}
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
                                            disabled={isLocked('milestone_id')}
                                        >
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue placeholder="Pilih milestone" />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                <SelectItem value="none">—</SelectItem>
                                                {milestoneOptions.map((milestone) => (
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
                                            <DatePicker value={field.value} onChange={field.onChange} disabled={isLocked('due_date')} />
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
                                        <Select value={field.value} onValueChange={field.onChange} disabled={isLocked('priority')}>
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
                                        <Input type="number" min="0" step="0.01" disabled={isLocked('rate_per_task')} {...field} />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    {isPaid ? 'Tutup' : 'Batal'}
                                </Button>
                            </DialogClose>
                            {!isPaid && (
                                <Button type="submit" disabled={form.formState.isSubmitting}>
                                    Simpan
                                </Button>
                            )}
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
