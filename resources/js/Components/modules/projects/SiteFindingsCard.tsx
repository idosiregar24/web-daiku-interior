import { EmptyState } from '@/Components/shared/EmptyState';
import { MoneyInput } from '@/Components/shared/MoneyInput';
import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogClose, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import { formatDate } from '@/lib/format';
import type { Milestone, ProjectExecution, User } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { ClipboardList, ExternalLink, Wrench } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

type Finding = ProjectExecution['findings'][number];

// Mirrors StoreSiteFindingRequest.
const findingSchema = z.object({
    description: z.string().trim().min(3, 'Uraian temuan minimal 3 karakter.').max(2000),
    milestone_id: z.string().optional(),
    photo_url: z.union([z.literal(''), z.string().url('Link foto harus URL yang valid.').max(500)]).optional(),
});

// Mirrors StoreRepairTaskRequest.
const repairSchema = z.object({
    assignee_id: z.string().min(1, 'Tukang wajib dipilih.'),
    due_date: z.string().min(1, 'Tanggal perbaikan wajib diisi.'),
    rate_per_task: z.string().optional(),
});

/**
 * Sprint 24 D12 — "Pengecekan finishing di lokasi": the Pengawas records
 * findings at PEMASANGAN; each can become a repair task for a Tukang and
 * closes when that task is DONE (or by hand). Open findings hold the
 * timeline's QA and the project's completion.
 */
export function SiteFindingsCard({
    projectId,
    execution,
    milestones,
    fieldStaff,
}: {
    projectId: number;
    execution: ProjectExecution;
    milestones: Milestone[];
    fieldStaff: Pick<User, 'id' | 'name' | 'is_active'>[];
}) {
    const { findings, can } = execution;
    const [repairing, setRepairing] = useState<Finding | null>(null);
    const form = useForm<z.infer<typeof findingSchema>>({ resolver: zodResolver(findingSchema), defaultValues: { description: '', milestone_id: '', photo_url: '' } });

    if (!can.recordFinding && findings.length === 0) {
        return null;
    }

    const open = findings.filter((finding) => finding.status === 'TERBUKA').length;

    function onSubmit(values: z.infer<typeof findingSchema>) {
        router.post(
            route('projects.site-findings.store', { project: projectId }),
            { description: values.description, milestone_id: values.milestone_id ? Number(values.milestone_id) : null, photo_url: values.photo_url || null },
            {
                preserveScroll: true,
                onSuccess: () => form.reset({ description: '', milestone_id: '', photo_url: '' }),
                onError: (errors) => Object.entries(errors).forEach(([key, message]) => form.setError(key as 'description', { message })),
            },
        );
    }

    return (
        <SectionCard
            title="Temuan Finishing di Lokasi"
            description={open > 0 ? `${open} temuan terbuka — menahan QA timeline & penyelesaian proyek.` : 'Dicatat Pengawas saat pemasangan.'}
            icon={ClipboardList}
        >
            {can.recordFinding && (
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="mb-6 space-y-3 rounded-lg bg-daiku-gray/60 p-4">
                        <FormField
                            control={form.control}
                            name="description"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Temuan</FormLabel>
                                    <FormControl>
                                        <Textarea {...field} rows={2} placeholder="Mis. sambungan HPL pintu atas terkelupas" />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <div className="grid gap-3 sm:grid-cols-2">
                            <FormField
                                control={form.control}
                                name="milestone_id"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Timeline</FormLabel>
                                        <Select value={field.value || 'all'} onValueChange={(value) => field.onChange(value === 'all' ? '' : value)}>
                                            <FormControl>
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                <SelectItem value="all">Seluruh proyek</SelectItem>
                                                {milestones.map((milestone) => (
                                                    <SelectItem key={milestone.id} value={String(milestone.id)}>
                                                        {milestone.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="photo_url"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Link foto</FormLabel>
                                        <FormControl>
                                            <Input {...field} placeholder="https://…" />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <Button type="submit" className="w-full sm:w-auto" disabled={form.formState.isSubmitting}>
                            Catat Temuan
                        </Button>
                    </form>
                </Form>
            )}

            {findings.length === 0 ? (
                <EmptyState title="Belum ada temuan." />
            ) : (
                <ul className="divide-y divide-border">
                    {findings.map((finding) => (
                        <li key={finding.id} className="flex flex-wrap items-start gap-3 py-3">
                            <div className="min-w-0 flex-1">
                                <p className="text-sm text-foreground">{finding.description}</p>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    {finding.milestone?.name ?? 'Seluruh proyek'} · {finding.reporter} · {formatDate(finding.created_at)}
                                    {finding.task && ` · Perbaikan: ${finding.task.assignee ?? '—'}, ${formatDate(finding.task.due_date)}`}
                                </p>
                                {finding.photo_url && (
                                    <a href={finding.photo_url} target="_blank" rel="noreferrer" className="mt-1 inline-flex items-center gap-1 text-xs text-info-ink underline">
                                        <ExternalLink className="size-3" />
                                        Foto
                                    </a>
                                )}
                            </div>
                            <StatusChip domain="siteFinding" status={finding.status} />
                            {finding.status === 'TERBUKA' && (
                                <div className="flex gap-2">
                                    {can.manageTargets && !finding.task && (
                                        <Button size="sm" variant="outline" onClick={() => setRepairing(finding)}>
                                            <Wrench className="size-4" />
                                            Jadikan Task Perbaikan
                                        </Button>
                                    )}
                                    {can.resolveFinding && (
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => router.post(route('site-findings.resolve', { siteFinding: finding.id }), {}, { preserveScroll: true })}
                                        >
                                            Tandai Selesai
                                        </Button>
                                    )}
                                </div>
                            )}
                        </li>
                    ))}
                </ul>
            )}

            <RepairTaskDialog finding={repairing} fieldStaff={fieldStaff} onOpenChange={(value) => !value && setRepairing(null)} />
        </SectionCard>
    );
}

function RepairTaskDialog({
    finding,
    fieldStaff,
    onOpenChange,
}: {
    finding: Finding | null;
    fieldStaff: Pick<User, 'id' | 'name' | 'is_active'>[];
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm<z.infer<typeof repairSchema>>({ resolver: zodResolver(repairSchema), defaultValues: { assignee_id: '', due_date: '', rate_per_task: '' } });

    useEffect(() => {
        if (finding) form.reset({ assignee_id: '', due_date: new Date().toISOString().slice(0, 10), rate_per_task: '' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [finding]);

    function onSubmit(values: z.infer<typeof repairSchema>) {
        if (!finding) return;
        router.post(
            route('site-findings.repair-task', { siteFinding: finding.id }),
            { assignee_id: Number(values.assignee_id), due_date: values.due_date, rate_per_task: values.rate_per_task ? Number(values.rate_per_task) : null },
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onError: (errors) => Object.entries(errors).forEach(([key, message]) => form.setError(key as 'assignee_id', { message })),
            },
        );
    }

    return (
        <Dialog open={finding !== null} onOpenChange={onOpenChange}>
            <ResponsiveDialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Jadikan Task Perbaikan</DialogTitle>
                    <DialogDescription>{finding?.description}</DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="assignee_id"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Tukang</FormLabel>
                                    <Select value={field.value} onValueChange={field.onChange}>
                                        <FormControl>
                                            <SelectTrigger>
                                                <SelectValue placeholder="Pilih tukang" />
                                            </SelectTrigger>
                                        </FormControl>
                                        <SelectContent>
                                            {fieldStaff
                                                .filter((staff) => staff.is_active)
                                                .map((staff) => (
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
                            name="due_date"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Tanggal perbaikan</FormLabel>
                                    <FormControl>
                                        <Input type="date" {...field} />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="rate_per_task"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Upah</FormLabel>
                                    <FormControl>
                                        <MoneyInput value={field.value} onChange={field.onChange} placeholder="0" />
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
                                Buat Task Perbaikan
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </ResponsiveDialogContent>
        </Dialog>
    );
}
