import { EmptyState } from '@/Components/shared/EmptyState';
import { Notice } from '@/Components/shared/Notice';
import { ProgressBar } from '@/Components/shared/ProgressBar';
import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { TABLE_HEAD_CLASS, TableCard } from '@/Components/shared/TableCard';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogClose, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { formatDate, formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import { statusWithHolder, waitingOn } from '@/lib/waitingOn';
import type { Milestone, Project, ProjectExecution, User } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Link, router } from '@inertiajs/react';
import { Activity, ArrowLeftRight, Check, ChevronLeft, ChevronRight, DraftingCompass, ExternalLink, MapPin, Route } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { SiteFindingsCard } from './SiteFindingsCard';
import { SupervisorFeeCard } from './SupervisorFeeCard';
import { TechnicalMeetingCard } from './TechnicalMeetingCard';
import type { MaterialOption } from './TmMaterialPlanner';
import { WeeklyReportsCard } from './WeeklyReportsCard';

const DAY_LABEL = new Intl.DateTimeFormat('id-ID', { weekday: 'short', day: 'numeric' });

/**
 * Sprint 24 — the project's "Eksekusi" tab: where the project is in the
 * flow (Gambar Kerja → TM → Persiapan → Produksi → Pemasangan) and whose
 * turn it is, then one card per step. Everything comes from
 * ProjectExecutionService::overview(); the buttons are the ones its `can`
 * allows (the routes check again).
 */
export function ProjectExecutionTab({
    project,
    execution,
    milestones,
    fieldStaff,
    canManageMilestones,
    materialOptions,
    onOpenActivityTarget,
}: {
    project: Project;
    execution: ProjectExecution;
    milestones: Milestone[];
    fieldStaff: Pick<User, 'id' | 'name' | 'is_active'>[];
    canManageMilestones: boolean;
    materialOptions: MaterialOption[];
    /** Sprint 25 — opens the project-wide "Activity Target" tab (when this viewer reads it). */
    onOpenActivityTarget?: () => void;
}) {
    const current = execution.phases.find((phase) => phase.state === 'current');
    const holder = project.status === 'ACTIVE' ? waitingOn('projectPhase', execution.phase) : null;

    return (
        <div className="space-y-6">
            <SectionCard title="Tahap Eksekusi" description={current ? statusWithHolder(current.label, holder) : undefined} icon={Route}>
                <ol className="grid gap-2 sm:grid-cols-5">
                    {execution.phases.map((phase, index) => (
                        <li
                            key={phase.value}
                            className={cn(
                                'flex items-center gap-2 rounded-lg px-3 py-2 text-sm ring-1',
                                phase.state === 'current' && 'bg-daiku-yellow-light font-medium text-foreground ring-daiku-yellow',
                                phase.state === 'done' && 'bg-success/10 text-success-ink ring-success/30',
                                phase.state === 'upcoming' && 'bg-background text-muted-foreground ring-border',
                            )}
                        >
                            <span className="flex size-5 shrink-0 items-center justify-center rounded-full bg-background text-xs ring-1 ring-current">
                                {phase.state === 'done' ? <Check className="size-3" /> : index + 1}
                            </span>
                            {phase.label}
                        </li>
                    ))}
                </ol>
                {execution.isLegacy && (
                    <p className="mt-3 text-xs text-muted-foreground">Proyek ini dibuka sebelum alur eksekusi baru — langsung di tahap Produksi.</p>
                )}
            </SectionCard>

            {execution.deadline.warning && (
                <Notice tone={execution.deadline.days_left !== null && execution.deadline.days_left < 0 ? 'error' : 'warning'}>
                    Mendekati Tanggal Selesai ({formatDate(execution.deadline.end_date)}):{' '}
                    {execution.deadline.days_left !== null && execution.deadline.days_left >= 0
                        ? `sisa ${execution.deadline.days_left} hari`
                        : `lewat ${Math.abs(execution.deadline.days_left ?? 0)} hari`}{' '}
                    — actual activity {execution.progress}%.
                </Notice>
            )}

            <div className="grid gap-6 lg:grid-cols-2">
                <WorkingDrawingCard execution={execution} />
                <ProgressCard project={project} execution={execution} />
            </div>

            <TechnicalMeetingCard
                project={project}
                execution={execution}
                milestones={milestones}
                canManageTimeline={canManageMilestones}
                materialOptions={materialOptions}
            />
            <SupervisorFeeCard projectId={project.id} execution={execution} />
            {execution.targetVsActual && <TargetVsActualCard project={project} execution={execution} fieldStaff={fieldStaff} onOpenActivityTarget={onOpenActivityTarget} />}
            <WeeklyReportsCard projectId={project.id} execution={execution} />
            <SiteFindingsCard projectId={project.id} execution={execution} milestones={milestones} fieldStaff={fieldStaff} />
        </div>
    );
}

function WorkingDrawingCard({ execution }: { execution: ProjectExecution }) {
    const drawing = execution.workingDrawing;

    return (
        <SectionCard
            title="Gambar Kerja"
            icon={DraftingCompass}
            action={
                drawing?.canOpen ? (
                    <Button variant="outline" size="sm" asChild>
                        <Link href={route('design.working-drawings.show', { workingDrawing: drawing.id })}>Buka</Link>
                    </Button>
                ) : undefined
            }
        >
            {!drawing ? (
                <p className="text-sm text-muted-foreground">Tidak ada (proyek dibuka sebelum Sprint 24).</p>
            ) : (
                <div className="space-y-2 text-sm">
                    <StatusChip domain="workingDrawing" status={drawing.status} />
                    <p className="text-muted-foreground">
                        {drawing.pic ? `Arsitek: ${drawing.pic}${drawing.staff.length ? ` + ${drawing.staff.join(', ')}` : ''}` : 'Belum ditugaskan'}
                        {drawing.deadline && ` · deadline ${formatDate(drawing.deadline)}`}
                    </p>
                    {drawing.completed_at && (
                        <p className="text-muted-foreground">
                            Selesai {formatDateTime(drawing.completed_at)} · {drawing.completer}
                        </p>
                    )}
                    {drawing.drawing_urls.map((url) => (
                        <a key={url} href={url} target="_blank" rel="noreferrer" className="flex items-center gap-1 truncate text-info-ink underline">
                            <ExternalLink className="size-3.5 shrink-0" />
                            {url}
                        </a>
                    ))}
                </div>
            )}
        </SectionCard>
    );
}

function ProgressCard({ project, execution }: { project: Project; execution: ProjectExecution }) {
    const [busy, setBusy] = useState(false);
    const reached = execution.progress >= execution.threshold;

    return (
        <SectionCard title="Actual Activity" description="Target selesai ÷ semua target proyek." icon={Activity}>
            <p className="text-4xl leading-none font-semibold tracking-tight text-foreground">{execution.progress}%</p>
            <ProgressBar value={execution.progress} tone={reached ? 'success' : 'default'} label="Actual activity proyek" className="mt-4" />
            <p className="mt-2 text-xs text-muted-foreground">Berangkat ke lokasi terbuka mulai {execution.threshold}%.</p>
            {execution.departure ? (
                <p className="mt-3 text-sm text-success-ink">
                    Berangkat ke lokasi disetujui {formatDateTime(execution.departure.approved_at)} · {execution.departure.approver}
                </p>
            ) : (
                execution.can.approveDeparture && (
                    <Button
                        className="mt-4 w-full sm:w-auto"
                        disabled={!reached || busy}
                        onClick={() =>
                            router.post(route('projects.departure.approve', { project: project.id }), {}, {
                                preserveScroll: true,
                                onStart: () => setBusy(true),
                                onFinish: () => setBusy(false),
                            })
                        }
                    >
                        <MapPin className="size-4" />
                        Setujui Berangkat ke Lokasi
                    </Button>
                )
            )}
        </SectionCard>
    );
}

function shiftWeek(weekStart: string, days: number): string {
    const date = new Date(`${weekStart}T00:00:00`);
    date.setDate(date.getDate() + days);

    return date.toISOString().slice(0, 10);
}

/** D6/D7 — per Tukang per day: target (first planned) vs actual (finished), rolled ones marked. */
function TargetVsActualCard({
    project,
    execution,
    fieldStaff,
    onOpenActivityTarget,
}: {
    project: Project;
    execution: ProjectExecution;
    fieldStaff: Pick<User, 'id' | 'name' | 'is_active'>[];
    onOpenActivityTarget?: () => void;
}) {
    const data = execution.targetVsActual!;
    const [swapping, setSwapping] = useState(false);

    function goTo(week: string) {
        router.get(route('projects.show', { project: project.id }), { tab: 'execution', week }, { preserveScroll: true, preserveState: true, only: ['execution'] });
    }

    return (
        <SectionCard
            title="Target vs Actual"
            description={`Minggu mulai ${formatDate(data.week_start)} — angka: selesai / target hari itu (↻ = bergulir ke hari berikutnya).`}
            icon={Activity}
            flush
            footer={
                onOpenActivityTarget && (
                    <Button variant="link" size="sm" className="h-auto px-0" onClick={onOpenActivityTarget}>
                        Lihat seluruh proyek (Activity Target)
                        <ChevronRight className="size-4" />
                    </Button>
                )
            }
            action={
                <div className="flex items-center gap-1">
                    <Button variant="ghost" size="icon-sm" aria-label="Minggu sebelumnya" onClick={() => goTo(shiftWeek(data.week_start, -7))}>
                        <ChevronLeft className="size-4" />
                    </Button>
                    <Button variant="ghost" size="icon-sm" aria-label="Minggu berikutnya" onClick={() => goTo(shiftWeek(data.week_start, 7))}>
                        <ChevronRight className="size-4" />
                    </Button>
                    {execution.can.manageTargets && (
                        <Button variant="outline" size="sm" onClick={() => setSwapping(true)}>
                            <ArrowLeftRight className="size-4" />
                            Tukar Hari
                        </Button>
                    )}
                </div>
            }
        >
            {data.rows.length === 0 ? (
                <EmptyState title="Belum ada daily target." />
            ) : (
                <TableCard className="rounded-none border-0 shadow-none ring-0">
                    <table className="w-full min-w-[560px] text-sm">
                        <thead className={TABLE_HEAD_CLASS}>
                            <tr>
                                <th className="px-4 py-2 text-left">Tukang</th>
                                {data.days.map((day) => (
                                    <th key={day} className="px-2 py-2 text-center">
                                        {DAY_LABEL.format(new Date(`${day}T00:00:00`))}
                                    </th>
                                ))}
                                <th className="px-4 py-2 text-right">Minggu ini</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border">
                            {data.rows.map((row) => (
                                <tr key={row.staff_id}>
                                    <td className="px-4 py-2 font-medium text-foreground">{row.name}</td>
                                    {data.days.map((day) => {
                                        const cell = row.cells[day];
                                        const empty = cell.target === 0 && cell.actual === 0;

                                        return (
                                            <td key={day} className="px-2 py-2 text-center tabular-nums">
                                                {empty ? (
                                                    <span className="text-muted-foreground/50">—</span>
                                                ) : (
                                                    <span className={cn(cell.actual >= cell.target ? 'text-success-ink' : 'text-warning-ink')}>
                                                        {cell.actual}/{cell.target}
                                                        {cell.rolled > 0 && <span className="ml-0.5 text-xs text-error-ink">↻{cell.rolled}</span>}
                                                    </span>
                                                )}
                                            </td>
                                        );
                                    })}
                                    <td className="px-4 py-2 text-right tabular-nums">
                                        {row.actual}/{row.target}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </TableCard>
            )}
            <SwapDaysDialog open={swapping} onOpenChange={setSwapping} projectId={project.id} fieldStaff={fieldStaff} />
        </SectionCard>
    );
}

// Mirrors RescheduleTargetRequest (swap form).
const swapSchema = z
    .object({
        from_date: z.string().min(1, 'Tanggal pertama wajib diisi.'),
        to_date: z.string().min(1, 'Tanggal kedua wajib diisi.'),
        assignee_id: z.string().optional(),
    })
    .refine((values) => values.from_date !== values.to_date, { message: 'Pilih dua tanggal yang berbeda.', path: ['to_date'] });

/** D9 — "Tukar": unfinished targets of two days swap places, only inside their timelines (server-checked). */
function SwapDaysDialog({
    open,
    onOpenChange,
    projectId,
    fieldStaff,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    projectId: number;
    fieldStaff: Pick<User, 'id' | 'name' | 'is_active'>[];
}) {
    const form = useForm<z.infer<typeof swapSchema>>({ resolver: zodResolver(swapSchema), defaultValues: { from_date: '', to_date: '', assignee_id: '' } });

    function onSubmit(values: z.infer<typeof swapSchema>) {
        router.post(
            route('projects.targets.swap', { project: projectId }),
            { ...values, assignee_id: values.assignee_id ? Number(values.assignee_id) : null },
            {
                preserveScroll: true,
                onSuccess: () => {
                    form.reset();
                    onOpenChange(false);
                },
                onError: (errors) => Object.entries(errors).forEach(([key, message]) => form.setError(key as 'to_date', { message })),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <ResponsiveDialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Tukar Hari Target</DialogTitle>
                    <DialogDescription>Target yang belum selesai di kedua tanggal bertukar hari — hanya di dalam rentang timeline masing-masing.</DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <FormField
                                control={form.control}
                                name="from_date"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Tanggal pertama</FormLabel>
                                        <FormControl>
                                            <Input type="date" {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="to_date"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Tanggal kedua</FormLabel>
                                        <FormControl>
                                            <Input type="date" {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <FormField
                            control={form.control}
                            name="assignee_id"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Tukang</FormLabel>
                                    <Select value={field.value || 'all'} onValueChange={(value) => field.onChange(value === 'all' ? '' : value)}>
                                        <FormControl>
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                        </FormControl>
                                        <SelectContent>
                                            <SelectItem value="all">Semua tukang</SelectItem>
                                            {fieldStaff.map((staff) => (
                                                <SelectItem key={staff.id} value={String(staff.id)}>
                                                    {staff.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
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
                                Tukar
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </ResponsiveDialogContent>
        </Dialog>
    );
}
