import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
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
import AppLayout from '@/Layouts/AppLayout';
import type { DailyTaskForm, Task, TaskStatus } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

interface DailyFormIndexProps {
    forms: DailyTaskForm[];
    pendingTasks: Task[];
    date: string;
    isFieldStaff: boolean;
}

const STATUS_OPTIONS: Exclude<TaskStatus, 'OVER'>[] = ['PENDING', 'ONPROGRESS', 'PENGECEKAN', 'DONE'];

const schema = z.object({
    status: z.enum(STATUS_OPTIONS as [TaskStatus, ...TaskStatus[]]),
    kendala: z.string().optional(),
    notes: z.string().optional(),
});

type FormValues = z.infer<typeof schema>;

function SubmitFormDialog({ task, open, onOpenChange }: { task: Task | null; open: boolean; onOpenChange: (open: boolean) => void }) {
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { status: 'ONPROGRESS', kendala: '', notes: '' },
    });

    useEffect(() => {
        if (open) {
            form.reset({ status: 'ONPROGRESS', kendala: '', notes: '' });
        }
    }, [open]);

    function onSubmit(values: FormValues) {
        if (!task) return;

        const onError = (errors: Record<string, string>) => {
            Object.entries(errors).forEach(([field, message]) => {
                form.setError(field as keyof FormValues, { message });
            });
        };

        router.post(route('daily-forms.store', { task: task.id }), values, {
            onError,
            onSuccess: () => onOpenChange(false),
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Form Harian — {task?.title}</DialogTitle>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="status"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Status Hari Ini</FormLabel>
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
                                        <Textarea {...field} rows={2} />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="notes"
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
                                Submit
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * "Daily form page Tukang: form per task aktif hari ini" +
 * "DailyTaskFormController: store, index by date" (.claude/plan/sprint-03.md
 * Week 5) — Field Staff fill in today's active tasks here; PM/CEO get a
 * read-only, date-filterable history of everyone's submissions.
 */
export default function DailyFormIndex({ forms, pendingTasks, date, isFieldStaff }: DailyFormIndexProps) {
    const [activeTask, setActiveTask] = useState<Task | null>(null);

    function applyDate(nextDate: string) {
        router.get(route('daily-forms.index'), { date: nextDate }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout breadcrumbs={[{ label: 'Form Harian' }]}>
            <Head title="Form Harian" />

            <PageHeader
                title="Form Harian"
                description={
                    isFieldStaff
                        ? 'Isi form harian untuk setiap task aktif sebelum jam 21:00 WIB.'
                        : 'Riwayat form harian tukang, per tanggal.'
                }
                actions={
                    !isFieldStaff && (
                        <input
                            type="date"
                            value={date}
                            onChange={(e) => applyDate(e.target.value)}
                            className="h-8 rounded-lg border border-daiku-border px-2.5 text-sm"
                        />
                    )
                }
            />

            {isFieldStaff && (
                <Card className="mb-4">
                    <CardContent className="pt-6">
                        <h3 className="mb-3 text-sm font-medium text-daiku-dark">Task Aktif Belum Diisi Hari Ini</h3>
                        {pendingTasks.length === 0 ? (
                            <p className="text-sm text-daiku-muted">
                                Semua task aktif sudah diisi form hari ini. 🎉
                            </p>
                        ) : (
                            <div className="space-y-2">
                                {pendingTasks.map((task) => (
                                    <div
                                        key={task.id}
                                        className="flex items-center justify-between gap-4 rounded-lg border border-daiku-border p-3"
                                    >
                                        <div>
                                            <p className="text-sm font-medium text-daiku-dark">{task.title}</p>
                                            <p className="text-xs text-daiku-muted">{task.project?.name}</p>
                                        </div>
                                        <Button size="sm" onClick={() => setActiveTask(task)}>
                                            Isi Form
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            )}

            <div className="overflow-hidden rounded-lg border border-daiku-border">
                <table className="w-full text-sm">
                    <thead className="bg-daiku-yellow-light">
                        <tr>
                            <th className="p-2 text-left font-medium">Task</th>
                            {!isFieldStaff && <th className="p-2 text-left font-medium">Tukang</th>}
                            <th className="p-2 text-left font-medium">Status</th>
                            <th className="p-2 text-left font-medium">Kendala</th>
                            <th className="p-2 text-left font-medium">Disubmit</th>
                        </tr>
                    </thead>
                    <tbody>
                        {forms.length === 0 ? (
                            <tr>
                                <td colSpan={5} className="p-6 text-center text-daiku-muted">
                                    Belum ada form harian untuk tanggal ini.
                                </td>
                            </tr>
                        ) : (
                            forms.map((form) => (
                                <tr key={form.id} className="border-t border-daiku-border">
                                    <td className="p-2 font-medium">{form.task?.title ?? '—'}</td>
                                    {!isFieldStaff && <td className="p-2 text-daiku-muted">{form.staff?.name ?? '—'}</td>}
                                    <td className="p-2">
                                        <StatusChip status={form.status_update} />
                                    </td>
                                    <td className="p-2 text-daiku-muted">{form.kendala ?? '—'}</td>
                                    <td className="p-2 text-daiku-muted">
                                        {new Date(form.submitted_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            <SubmitFormDialog task={activeTask} open={!!activeTask} onOpenChange={(open) => !open && setActiveTask(null)} />
        </AppLayout>
    );
}
