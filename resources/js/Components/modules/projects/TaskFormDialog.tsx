import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
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
import { MoneyInput } from '@/Components/shared/MoneyInput';
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
import { type TaskReload, taskVisit } from '@/Components/modules/tasks/taskSave';
import { formatDate } from '@/lib/format';
import { TASK_PRIORITY_LABEL } from '@/lib/taskLabels';
import { isWorkDay } from '@/lib/workDays';
import { router } from '@inertiajs/react';
import { addDays, format } from 'date-fns';
import { CircleCheck, Loader2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const PRIORITY_OPTIONS: TaskPriority[] = ['HIGH', 'MEDIUM', 'LOW'];

// Mirrors StoreTaskRequest/UpdateTaskRequest. Which milestones and tukang
// are selectable (same project, not COMPLETED; active Field Staff) is
// narrowed in the option lists below — the server re-checks both.
const schema = z.object({
    milestone_id: z.string().optional(),
    title: z.string().trim().min(1, 'Judul daily target wajib diisi').max(150, 'Judul daily target maksimal 150 karakter'),
    description: z.string().optional(),
    assignee_id: z.string().min(1, 'Tukang wajib dipilih'),
    due_date: z.date({ message: 'Tanggal jatuh tempo wajib diisi' }),
    // Sprint 29 Sub 04 (K5) — create only: more tukang and/or a last day → tasks.storeMany (StoreTasksRequest).
    extra_assignee_ids: z.array(z.string()).max(9, 'Maksimal 10 tukang sekaligus'),
    until: z.date().optional(),
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
    extra_assignee_ids: [],
    until: undefined,
    priority: 'MEDIUM',
    rate_per_task: '',
};

const FIELDS = Object.keys(EMPTY_VALUES);

/**
 * Sprint 29 L4 — the next working day after `day` (Senin–Sabtu,
 * lib/workDays), or `day` itself when that would leave the timeline.
 */
function nextWorkDay(day: Date, lastDay: string | null): Date {
    let next = addDays(day, 1);
    while (!isWorkDay(format(next, 'yyyy-MM-dd'))) next = addDays(next, 1);

    return lastDay && format(next, 'yyyy-MM-dd') > lastDay ? day : next;
}

/** What a DONE-but-unpaid task still allows — TaskService::update()'s DONE_EDITABLE. */
const DONE_EDITABLE: (keyof FormValues)[] = ['title', 'description', 'rate_per_task'];

interface TaskFormDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Project a new task goes into — ignored when editing (the task carries its own). */
    projectId?: number;
    /** Task being edited, or null/undefined for create. */
    editing?: Task | null;
    /** May span several projects (Tasks/Index) — filtered to the task's project here. Dates (when sent) bound the target day. */
    milestones: (Pick<Milestone, 'id' | 'name' | 'project_id' | 'status'> & Partial<Pick<Milestone, 'start_date' | 'target_date'>>)[];
    fieldStaff: Pick<User, 'id' | 'name' | 'is_active'>[];
    /**
     * Sprint 29 L4 — the page props a save reloads (Detail Proyek:
     * `RELOAD.tasks`, Tugas: `TASKS_INDEX_RELOAD`); omitted = full reload.
     */
    reload?: TaskReload;
    /** Sprint 29 Sub 08 (B8) — the project pays per RAB item (Upah Tukang card): no per-target wage. */
    hideWage?: boolean;
}

/**
 * "Task assignment form PM: pilih tukang, due date, rate per task"
 * (.claude/plan/sprint-02.md Week 4) and, since Sprint 9, "Edit Task" —
 * PM only (TaskPolicy::update()). Create is reached from Projects/Show.tsx's
 * Task tab; edit from there and from Tasks/Index.tsx.
 * Sprint 29 L4 — creating offers "Simpan & buat lagi" (also Enter): saved
 * without the pop-up, the dialog stays open with tukang, timeline,
 * priority and upah kept, the day moved to the next working day inside the
 * timeline, and the cursor back in Judul. "Simpan" saves and closes.
 */
export function TaskFormDialog({ open, onOpenChange, projectId, editing = null, milestones, fieldStaff, reload, hideWage = false }: TaskFormDialogProps) {
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: EMPTY_VALUES,
    });
    // Which button is saving ('again' = Simpan & buat lagi) — both stay locked meanwhile.
    const [saving, setSaving] = useState<'close' | 'again' | null>(null);
    // Titles saved with "Simpan & buat lagi" since the dialog opened — the quiet "Tersimpan ✓".
    const [savedTitles, setSavedTitles] = useState<string[]>([]);

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

        setSavedTitles([]);
        if (editing) {
            form.reset({
                milestone_id: editing.milestone_id ? String(editing.milestone_id) : '',
                title: editing.title,
                description: editing.description ?? '',
                assignee_id: editing.assignee_id ? String(editing.assignee_id) : '',
                due_date: editing.due_date ? new Date(editing.due_date) : (undefined as unknown as Date),
                priority: editing.priority,
                rate_per_task: editing.rate_per_task !== null ? String(Number(editing.rate_per_task)) : '',
                extra_assignee_ids: [],
                until: undefined,
            });
        } else {
            form.reset(EMPTY_VALUES);
        }
    }, [open, editing]);

    function isLocked(field: keyof FormValues) {
        return isPaid || (isDone && !DONE_EDITABLE.includes(field));
    }

    const selectedMilestone = milestoneOptions.find((milestone) => String(milestone.id) === form.watch('milestone_id'));
    const range =
        selectedMilestone?.start_date && selectedMilestone?.target_date
            ? { from: selectedMilestone.start_date.slice(0, 10), to: selectedMilestone.target_date.slice(0, 10) }
            : null;
    // Sprint 24 D6 — a new daily target always sits in a timeline (an old task without one may keep none).
    const milestoneRequired = !editing || editing.milestone_id !== null;

    function onSubmit(values: FormValues, again: boolean) {
        if (saving) return;

        if (milestoneRequired && !values.milestone_id) {
            form.setError('milestone_id', { message: 'Pilih timeline daily target ini.' });
            return;
        }

        const day = format(values.due_date, 'yyyy-MM-dd');
        if (range && (day < range.from || day > range.to) && !isLocked('due_date')) {
            form.setError('due_date', { message: 'Tanggal target harus di dalam rentang timeline.' });
            return;
        }

        const until = !editing && values.until ? format(values.until, 'yyyy-MM-dd') : null;
        if (until && until < day) {
            form.setError('until', { message: 'Sampai tanggal tidak boleh sebelum Tanggal Target.' });
            return;
        }
        if (until && range && until > range.to) {
            form.setError('until', { message: 'Sampai tanggal harus di dalam rentang timeline.' });
            return;
        }
        const extras = editing ? [] : values.extra_assignee_ids.filter((id) => id !== values.assignee_id);
        const batch = !editing && (extras.length > 0 || (until !== null && until !== day));

        // StoreTasksRequest's keys back onto this form's fields.
        const BATCH_FIELD: Record<string, keyof FormValues> = { assignee_ids: 'assignee_id', date_from: 'due_date', date_to: 'until' };
        const onError = (errors: Record<string, string>) => {
            Object.entries(errors).forEach(([key, message]) => {
                const field = BATCH_FIELD[key.split('.')[0]] ?? key;
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
        const keepGoing = again && !editing;
        const options = {
            ...taskVisit(reload, { quiet: keepGoing }),
            onStart: () => setSaving(keepGoing ? 'again' : 'close'),
            onFinish: () => setSaving(null),
            onError,
            onSuccess: () => {
                if (!keepGoing) {
                    onOpenChange(false);
                    return;
                }

                // Same tukang, timeline, priority & upah — a new title on the next working day.
                form.reset({ ...values, title: '', description: '', due_date: nextWorkDay(values.until ?? values.due_date, range?.to ?? null), until: undefined });
                setSavedTitles((titles) => [...titles, values.title]);
                setTimeout(() => form.setFocus('title'), 0);
            },
        };

        if (editing) {
            router.put(route('tasks.update', { task: editing.id }), payload, options);
        } else if (targetProjectId && batch) {
            const { assignee_id, due_date, ...shared } = payload;
            router.post(
                route('tasks.storeMany', { project: targetProjectId }),
                { ...shared, assignee_ids: [assignee_id, ...extras.map(Number)], date_from: due_date, date_to: until ?? due_date },
                options,
            );
        } else if (targetProjectId) {
            router.post(route('tasks.store', { project: targetProjectId }), payload, options);
        }
    }

    const submit = (again: boolean) => form.handleSubmit((values) => onSubmit(values, again));
    const serverError = form.formState.errors.root?.server?.message;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{editing ? 'Edit Daily Target' : 'Tambah Daily Target'}</DialogTitle>
                    {editing?.project && <DialogDescription>Proyek {editing.project.name}</DialogDescription>}
                </DialogHeader>
                <Form {...form}>
                    {/* Enter = the main button: "Simpan & buat lagi" when creating, "Simpan" when editing. */}
                    <form onSubmit={submit(!editing)} className="space-y-4">
                        {serverError && <Notice tone="error">{serverError}</Notice>}
                        {isPaid ? (
                            <Notice tone="info">Upah daily target ini sudah dibayar — daily target terkunci dan tidak bisa diubah lagi.</Notice>
                        ) : isDone ? (
                            <Notice tone="info">Daily target sudah selesai — hanya judul, deskripsi, dan rate yang masih bisa diubah.</Notice>
                        ) : (
                            editing?.status === 'OVER' && (
                                <Notice tone="warning">
                                    Daily target ini melewati deadline (Terlambat). Mundurkan jatuh tempo ke hari ini atau setelahnya untuk
                                    mengembalikan statusnya ke {editing.pre_overdue_status ?? 'PENDING'}.
                                </Notice>
                            )
                        )}
                        <FormField
                            control={form.control}
                            name="title"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Judul Daily Target</FormLabel>
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
                                    <FormLabel>Deskripsi</FormLabel>
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
                                        <FormLabel required>Tukang</FormLabel>
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
                                        <FormLabel required={milestoneRequired}>Timeline</FormLabel>
                                        <Select
                                            value={field.value || 'none'}
                                            onValueChange={(value) => field.onChange(value === 'none' ? '' : value)}
                                            disabled={isLocked('milestone_id')}
                                        >
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue placeholder="Pilih timeline" />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                {!milestoneRequired && <SelectItem value="none">—</SelectItem>}
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
                        {!editing && staffOptions.filter((staff) => staff.is_active !== false).length > 1 && (
                            <FormField
                                control={form.control}
                                name="extra_assignee_ids"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Juga untuk tukang</FormLabel>
                                        <div className="flex flex-wrap gap-x-4 gap-y-2">
                                            {staffOptions
                                                .filter((staff) => staff.is_active !== false && String(staff.id) !== form.watch('assignee_id'))
                                                .map((staff) => {
                                                    const id = String(staff.id);
                                                    const checked = field.value.includes(id);

                                                    return (
                                                        <label key={staff.id} className="flex min-h-9 items-center gap-2 text-sm">
                                                            <Checkbox
                                                                checked={checked}
                                                                onCheckedChange={(next) =>
                                                                    field.onChange(next ? [...field.value, id] : field.value.filter((value) => value !== id))
                                                                }
                                                            />
                                                            {staff.name}
                                                        </label>
                                                    );
                                                })}
                                        </div>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        )}
                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                control={form.control}
                                name="due_date"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Tanggal Target</FormLabel>
                                        <FormControl>
                                            <DatePicker value={field.value} onChange={field.onChange} disabled={isLocked('due_date')} />
                                        </FormControl>
                                        {range && (
                                            <p className="text-xs text-muted-foreground">
                                                Timeline {formatDate(range.from)} – {formatDate(range.to)}
                                            </p>
                                        )}
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            {!editing && (
                                <FormField
                                    control={form.control}
                                    name="until"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Sampai tanggal</FormLabel>
                                            <FormControl>
                                                <DatePicker value={field.value} onChange={field.onChange} clearable placeholder="Satu hari saja" />
                                            </FormControl>
                                            <p className="text-xs text-muted-foreground">Satu target tiap hari kerja (Senin–Sabtu).</p>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                            )}
                            <FormField
                                control={form.control}
                                name="priority"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required={editing !== null}>Prioritas</FormLabel>
                                        <Select value={field.value} onValueChange={field.onChange} disabled={isLocked('priority')}>
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                {PRIORITY_OPTIONS.map((option) => (
                                                    <SelectItem key={option} value={option}>
                                                        {TASK_PRIORITY_LABEL[option]}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        {!hideWage && (
                            <FormField
                                control={form.control}
                                name="rate_per_task"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Upah (Rp)</FormLabel>
                                        <FormControl>
                                            <MoneyInput decimals={2} disabled={isLocked('rate_per_task')} {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        )}
                        {savedTitles.length > 0 && (
                            <p role="status" className="flex items-start gap-1.5 text-sm text-success-ink">
                                <CircleCheck className="mt-0.5 size-4 shrink-0" aria-hidden />
                                <span>
                                    Tersimpan: “{savedTitles.at(-1)}”
                                    {savedTitles.length > 1 && ` · ${savedTitles.length} target sejak dialog dibuka`}
                                </span>
                            </p>
                        )}
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    {isPaid || savedTitles.length > 0 ? 'Tutup' : 'Batal'}
                                </Button>
                            </DialogClose>
                            {editing ? (
                                !isPaid && (
                                    <Button type="submit" disabled={saving !== null}>
                                        {saving && <Loader2 className="size-4 animate-spin" />}
                                        {saving ? 'Menyimpan…' : 'Simpan'}
                                    </Button>
                                )
                            ) : (
                                <>
                                    <Button type="button" variant="outline" disabled={saving !== null} onClick={submit(false)}>
                                        {saving === 'close' && <Loader2 className="size-4 animate-spin" />}
                                        Simpan
                                    </Button>
                                    <Button type="submit" disabled={saving !== null}>
                                        {saving === 'again' && <Loader2 className="size-4 animate-spin" />}
                                        {saving === 'again' ? 'Menyimpan…' : 'Simpan & buat lagi'}
                                    </Button>
                                </>
                            )}
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
