import { ConfirmDialog } from '@/Components/shared/ConfirmDialog';
import { DetailItem, DetailList } from '@/Components/shared/DetailList';
import { MoneyInput } from '@/Components/shared/MoneyInput';
import { Notice } from '@/Components/shared/Notice';
import { SectionCard } from '@/Components/shared/SectionCard';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import { formatDate, formatDateTime, formatRupiah } from '@/lib/format';
import { projectVisit, RELOAD } from '@/lib/projectReload';
import { cn } from '@/lib/utils';
import type { Milestone, Project, ProjectExecution, User } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Link, router } from '@inertiajs/react';
import { differenceInCalendarDays } from 'date-fns';
import {
    CalendarClock,
    Check,
    CheckCircle2,
    Circle,
    ClipboardCheck,
    ClipboardPen,
    ExternalLink,
    Loader2,
    Pencil,
    Plus,
    Send,
    Trash2,
    Users,
    X,
} from 'lucide-react';
import { type ReactNode, useEffect, useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { MilestoneFormDialog, suggestTimelineStart } from './MilestoneFormDialog';
import { AttendeePicker } from './AttendeePicker';
import { MaterialStatusChip } from './MaterialStatusChip';
import { TmMaterialPlanner } from './TmMaterialPlanner';
import { WorkItemWageTable } from './WorkItemWageCard';

// Mirrors SaveTechnicalMeetingRequest.
const schema = z
    .object({
        meeting_date: z.string().min(1, 'Tanggal TM wajib diisi.'),
        // Sprint 34 — JADWAL (ahead, invited people notified & reminded) or LANGSUNG (held now).
        mode: z.enum(['JADWAL', 'LANGSUNG']),
        meeting_time: z.string().optional(),
        notes: z.string().max(10000, 'Notulen terlalu panjang.').optional(),
        attendees: z
            .array(
                z.object({
                    user_id: z.string().optional(),
                    name: z.string().max(120).optional(),
                }),
            )
            .max(50, 'Maksimal 50 peserta.'),
        // Optional until a fee exists — the PM may save the TM's date (its schedule) first.
        fee_total: z.string().optional(),
        dp_percent: z
            .string()
            .min(1, 'DP % wajib diisi.')
            .refine((value) => Number(value) >= 0 && Number(value) <= 100, 'DP antara 0–100%.'),
        end_date: z.string().optional(),
    })
    .superRefine((values, context) => {
        if (values.mode !== 'JADWAL') return;
        if (!values.meeting_time) context.addIssue({ code: 'custom', path: ['meeting_time'], message: 'Jam TM wajib diisi untuk menjadwalkan TM.' });
        if (!values.attendees.some((row) => row.user_id)) {
            context.addIssue({
                code: 'custom',
                path: ['attendees'],
                message: 'Pilih minimal satu orang yang punya akun — merekalah yang menerima undangan & pengingat TM.',
            });
        }
    });

type FormValues = z.infer<typeof schema>;
type MeetingView = 'choose' | 'schedule' | 'scheduled' | 'meeting';

const TODAY = () => new Date().toISOString().slice(0, 10);

type RequirementKey = ProjectExecution['meetingRequirements'][number]['key'];

/** A ✓ / ○ checklist line (TM Selesai, syarat DP). */
export function RequirementList({ items }: { items: { key: string; label: string; done: boolean }[] }) {
    return (
        <ul className="space-y-1.5 text-sm">
            {items.map((item) => (
                <li key={item.key} className="flex items-center gap-2">
                    {item.done ? <CheckCircle2 className="size-4 text-success-ink" /> : <Circle className="size-4 text-muted-foreground" />}
                    <span className={item.done ? 'text-foreground' : 'text-muted-foreground'}>{item.label}</span>
                </li>
            ))}
        </ul>
    );
}

interface TechnicalMeetingCardProps {
    project: Project;
    execution: ProjectExecution;
    milestones: Milestone[];
    /** May add/edit/delete timelines (Show's canManageMilestones). */
    canManageTimeline: boolean;
    /** Sprint 33 — the Kepala Tim picker of the Pengawas's wage table. */
    fieldStaff?: Pick<User, 'id' | 'name' | 'is_active'>[];
}

/**
 * Sprint 24 D3 — the Technical Meeting. For the PM (or Asisten PM) it is
 * one guided card holding everything that is theirs to do before "TM
 * Selesai": the meeting (date, minutes, attendees), the Timeline split
 * (added/edited right here — same MilestoneFormDialog as the Timeline
 * tab), the Jasa Pengawas and the project's Tanggal Selesai. The materials
 * are the Pengawas's: planned right here (TmMaterialPlanner) next to the
 * RAB items, in the PM's card and in the Pengawas's read-only one.
 */
export function TechnicalMeetingCard({ project, execution, milestones, canManageTimeline, fieldStaff = [] }: TechnicalMeetingCardProps) {
    const { meeting, fee, can } = execution;

    if (can.saveMeeting) {
        return (
            <TechnicalMeetingForm
                project={project}
                execution={execution}
                milestones={milestones}
                canManageTimeline={canManageTimeline}
                fieldStaff={fieldStaff}
            />
        );
    }

    if (!meeting) {
        return (
            <SectionCard title="Technical Meeting" icon={Users}>
                <p className="text-sm text-muted-foreground">
                    {execution.phase === 'GAMBAR_KERJA'
                        ? 'Dimulai setelah gambar kerja selesai.'
                        : 'Menunggu PM menentukan jadwal Technical Meeting.'}
                </p>
                {execution.meetingRequirements.length > 0 && (
                    <div className="mt-4">
                        <RequirementList items={execution.meetingRequirements} />
                    </div>
                )}
                <SupervisorWages project={project} execution={execution} fieldStaff={fieldStaff} />
                <ReadOnlyMaterials project={project} execution={execution} />
            </SectionCard>
        );
    }

    return (
        <SectionCard title="Technical Meeting" icon={Users}>
            <DetailList>
                <DetailItem label="Tanggal TM">{meetingWhen(meeting)}</DetailItem>
                <DetailItem label="Peserta">{meeting.attendees.map((row) => row.label).join(', ') || '—'}</DetailItem>
                {fee && !execution.usesWorkItems && (
                    <DetailItem label="Upah Tukang">
                        {formatRupiah(fee.total_amount)} · DP {fee.dp_percent}% ({formatRupiah(fee.dp_amount)})
                    </DetailItem>
                )}
                <DetailItem label="Selesai">
                    {meeting.completed_at ? `${formatDateTime(meeting.completed_at)} · ${meeting.completer ?? ''}` : 'Belum'}
                </DetailItem>
            </DetailList>
            {meeting.notes && <p className="mt-4 text-sm whitespace-pre-line text-foreground">{meeting.notes}</p>}
            {execution.meetingRequirements.length > 0 && (
                <div className="mt-4">
                    <RequirementList items={execution.meetingRequirements} />
                </div>
            )}
            <SupervisorWages project={project} execution={execution} fieldStaff={fieldStaff} />
            <ReadOnlyMaterials project={project} execution={execution} />
        </SectionCard>
    );
}

/**
 * "TM Selesai" — in the page header (the detail page's actions, Sprint 23
 * K4b; Sprint 28 K14 removed the TM card's copy) and as the Ringkasan's
 * "Giliran Anda" action. Locked, with the reason on hover, until every
 * requirement is met; the server checks them again.
 */
export function CompleteMeetingButton({
    projectId,
    requirements,
    blocked,
    warnUnsaved = false,
    className,
}: {
    projectId: number;
    requirements: ProjectExecution['meetingRequirements'];
    /** Why it can't be pressed yet, besides missing requirements (e.g. unsaved TM form). */
    blocked?: string;
    /** Pressed away from the TM card (page header) — remind that its unsaved edits don't come along. */
    warnUnsaved?: boolean;
    className?: string;
}) {
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);
    const missing = requirements.filter((row) => !row.done);
    const reason = blocked ?? (missing.length > 0 ? `Belum lengkap: ${missing.map((row) => row.label).join(', ')}.` : undefined);

    function complete() {
        router.post(
            route('projects.technical-meeting.complete', { project: projectId }),
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => {
                    setBusy(false);
                    setConfirming(false);
                },
            },
        );
    }

    return (
        // A disabled button shows no tooltip — the wrapper carries it.
        <span title={reason} className={cn('inline-flex', className)}>
            <Button className="w-full" disabled={reason !== undefined || busy} onClick={() => setConfirming(true)}>
                <CheckCircle2 className="size-4" />
                TM Selesai
                {missing.length > 0 && (
                    <span className="tabular-nums opacity-80">
                        {requirements.length - missing.length}/{requirements.length}
                    </span>
                )}
            </Button>
            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                icon={ClipboardCheck}
                title="Selesaikan Technical Meeting?"
                description="Proyek pindah ke tahap Persiapan. Pengawas diberi tahu untuk melengkapi bahan, upah & daily target, lalu mengajukan DP upah tukang."
                confirmLabel="Ya, TM Selesai"
                busy={busy}
                onConfirm={complete}
            >
                <p className="flex items-center gap-1.5 rounded-lg bg-success/10 px-3 py-1.5 text-xs font-medium text-success-ink">
                    <CheckCircle2 className="size-3.5" />
                    {requirements.length} syarat lengkap
                </p>
                {warnUnsaved && <p className="text-xs text-muted-foreground">Isian kartu TM yang belum disimpan tidak ikut.</p>}
            </ConfirmDialog>
        </span>
    );
}

/** "Selasa, 20 Okt 2026 pukul 10.00" */
function meetingWhen(meeting: NonNullable<ProjectExecution['meeting']>): string {
    const date = meeting.meeting_date
        ? new Date(`${meeting.meeting_date}T00:00:00`).toLocaleDateString('id-ID', {
              weekday: 'long',
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '—';

    return meeting.meeting_time ? `${date} pukul ${meeting.meeting_time.replace(':', '.')}` : date;
}

/** One of the two ways into the TM (Sprint 34). */
function ModeChoice({
    icon: Icon,
    title,
    description,
    onClick,
}: {
    icon: typeof CalendarClock;
    title: string;
    description: string;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="flex flex-col items-start gap-2 rounded-xl bg-background p-4 text-left ring-1 ring-border transition-colors hover:bg-daiku-yellow-light hover:ring-daiku-yellow"
        >
            <span className="flex size-9 items-center justify-center rounded-lg bg-daiku-yellow-light text-daiku-dark">
                <Icon className="size-5" />
            </span>
            <span className="font-semibold text-foreground">{title}</span>
            <span className="text-sm text-muted-foreground">{description}</span>
        </button>
    );
}

/** One numbered step of the TM card: ✓ once the server says its requirements are met. */
function TmStep({
    number,
    title,
    holder,
    done,
    description,
    children,
}: {
    number: number;
    title: string;
    holder: string;
    done: boolean;
    description?: string;
    children: ReactNode;
}) {
    return (
        <section className="border-t border-border pt-5 first:border-t-0 first:pt-0">
            <div className="mb-4 flex items-start gap-3">
                <span
                    className={cn(
                        'flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold',
                        done ? 'bg-success/10 text-success-ink' : 'bg-daiku-yellow-light text-foreground',
                    )}
                >
                    {done ? <Check className="size-3.5" /> : number}
                </span>
                <div className="min-w-0">
                    <h3 className="text-sm font-semibold text-foreground">
                        {title} <span className="font-normal text-muted-foreground">· {holder}</span>
                    </h3>
                    {description && <p className="mt-0.5 text-xs text-muted-foreground">{description}</p>}
                </div>
            </div>
            <div className="sm:pl-9">{children}</div>
        </section>
    );
}

function TechnicalMeetingForm({ project, execution, milestones, canManageTimeline, fieldStaff = [] }: TechnicalMeetingCardProps) {
    const { meeting, fee } = execution;
    const [saving, setSaving] = useState(false);
    const [saved, setSaved] = useState(false);
    const [timelineOpen, setTimelineOpen] = useState(false);
    const [editingTimeline, setEditingTimeline] = useState<Milestone | null>(null);
    const [deletingTimeline, setDeletingTimeline] = useState<Milestone | null>(null);
    const [removingTimeline, setRemovingTimeline] = useState(false);
    const defaultEndDate = execution.defaultEndDate?.slice(0, 10) ?? '';
    // Sprint 34 — no TM yet: choose "Jadwalkan" or "Langsung"; a schedule without minutes shows its summary.
    const [view, setView] = useState<MeetingView>(!meeting ? 'choose' : meeting.mode === 'JADWAL' && !meeting.notes ? 'scheduled' : 'meeting');
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: {
            meeting_date: meeting?.meeting_date ?? TODAY(),
            mode: meeting?.mode ?? 'LANGSUNG',
            meeting_time: meeting?.meeting_time ?? '',
            notes: meeting?.notes ?? '',
            attendees: meeting?.attendees.length
                ? meeting.attendees.map((row) => ({
                      user_id: row.user_id ? String(row.user_id) : '',
                      name: row.name ?? '',
                  }))
                : [],
            fee_total: fee ? String(fee.total_amount) : '',
            dp_percent: fee ? String(fee.dp_percent) : '10',
            end_date: defaultEndDate,
        },
    });

    // A timeline saved here keeps this form's state (preserveState) — follow
    // the suggested Tanggal Selesai (last timeline's end) until the PM types one.
    useEffect(() => {
        if (!form.getFieldState('end_date').isDirty) {
            form.resetField('end_date', { defaultValue: defaultEndDate });
        }
    }, [defaultEndDate]);

    const requirements = execution.meetingRequirements;
    const isDone = (...keys: RequirementKey[]) => keys.every((key) => requirements.find((row) => row.key === key)?.done ?? false);
    const doneCount = requirements.filter((row) => row.done).length;
    const allDone = requirements.length > 0 && doneCount === requirements.length;
    const dirty = form.formState.isDirty;

    const feeTotal = Number(form.watch('fee_total') || 0);
    const dpPercent = Number(form.watch('dp_percent') || 0);
    const sortedTimelines = useMemo(() => [...milestones].sort((a, b) => a.order - b.order), [milestones]);
    const suggestedStart = useMemo(() => suggestTimelineStart(milestones, project.start_date), [milestones, project.start_date]);

    function onSubmit(values: FormValues) {
        router.put(
            route('projects.technical-meeting.save', { project: project.id }),
            {
                ...values,
                meeting_time: values.meeting_time || null,
                fee_total: values.fee_total ? Number(values.fee_total) : null,
                // Sprint 33 — per-item projects: the DP % isn't set at the TM (10 %, the PM decides the amount).
                ...(execution.usesWorkItems ? {} : { dp_percent: Number(values.dp_percent) }),
                end_date: values.end_date || null,
                attendees: values.attendees
                    .filter((row) => row.user_id || row.name?.trim())
                    .map((row) => (row.user_id ? { user_id: Number(row.user_id) } : { name: row.name?.trim() })),
            },
            {
                // Quiet partial reload (Sprint 29 Sub 01 L6): "Tersimpan" beside the button is the feedback.
                ...projectVisit(RELOAD.meeting, { quiet: true }),
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => {
                    // The form stays mounted (preserveState) — what was saved is the new baseline.
                    form.reset(values);
                    setSaved(true);
                    if (view === 'schedule') setView('scheduled');
                },
                onError: (errors) =>
                    Object.entries(errors).forEach(([key, message]) =>
                        form.setError((key.startsWith('attendees') ? 'attendees' : key) as keyof FormValues, { message }),
                    ),
            },
        );
    }

    // "Tersimpan" only until the next edit.
    useEffect(() => {
        if (dirty) setSaved(false);
    }, [dirty]);

    function openTimeline(milestone: Milestone | null) {
        setEditingTimeline(milestone);
        setTimelineOpen(true);
    }

    function deleteTimeline() {
        if (!deletingTimeline) return;

        router.delete(route('milestones.destroy', { milestone: deletingTimeline.id }), {
            ...projectVisit(RELOAD.milestones, { quiet: true }),
            onStart: () => setRemovingTimeline(true),
            onFinish: () => {
                setRemovingTimeline(false);
                setDeletingTimeline(null);
            },
        });
    }

    return (
        <SectionCard
            title="Technical Meeting"
            description="Semua isian PM untuk TM ada di kartu ini. Lengkapi tiap langkah, lalu tekan TM Selesai di atas halaman."
            icon={Users}
            action={
                <span className={cn('text-xs font-medium tabular-nums', allDone ? 'text-success-ink' : 'text-muted-foreground')}>
                    {doneCount}/{requirements.length} syarat
                </span>
            }
        >
            <Form {...form}>
                <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-5">
                    <TmStep
                        number={1}
                        title="Jadwal & Rapat"
                        holder="PM"
                        done={isDone('notes', 'attendees')}
                        description={
                            view === 'choose'
                                ? 'Pilih: jadwalkan TM untuk nanti (orang yang terlibat diundang & diingatkan), atau langsung isi TM yang sedang berlangsung.'
                                : view === 'meeting'
                                  ? 'Isi peserta yang hadir dan notulen rapat.'
                                  : 'Tanggal, jam, dan orang yang diundang ke Technical Meeting.'
                        }
                    >
                        {view === 'choose' && (
                            <div className="grid gap-3 sm:grid-cols-2">
                                <ModeChoice
                                    icon={CalendarClock}
                                    title="Jadwalkan TM"
                                    description="Rapat nanti: tentukan tanggal & jam, lalu pilih orang yang terlibat — mereka mendapat undangan dan pengingat H-1 serta 2 jam sebelum rapat."
                                    onClick={() => {
                                        form.setValue('mode', 'JADWAL', { shouldDirty: true });
                                        setView('schedule');
                                    }}
                                />
                                <ModeChoice
                                    icon={ClipboardPen}
                                    title="Langsung TM"
                                    description="Rapat sedang berlangsung sekarang: isi peserta dan notulen seperti form biasa, tanpa undangan."
                                    onClick={() => {
                                        form.setValue('mode', 'LANGSUNG', { shouldDirty: true });
                                        form.setValue('meeting_date', TODAY(), { shouldDirty: true });
                                        setView('meeting');
                                    }}
                                />
                            </div>
                        )}

                        {view === 'schedule' && (
                            <div className="space-y-4 rounded-lg bg-daiku-gray/50 p-4">
                                <div className="grid gap-4 sm:grid-cols-[14rem_10rem]">
                                    <FormField
                                        control={form.control}
                                        name="meeting_date"
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormLabel required>Tanggal TM</FormLabel>
                                                <FormControl>
                                                    <Input type="date" min={TODAY()} {...field} />
                                                </FormControl>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                    <FormField
                                        control={form.control}
                                        name="meeting_time"
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormLabel required>Jam</FormLabel>
                                                <FormControl>
                                                    <Input type="time" {...field} value={field.value ?? ''} />
                                                </FormControl>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                </div>
                                <FormField
                                    control={form.control}
                                    name="attendees"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel required>Orang yang terlibat (diundang)</FormLabel>
                                            <p className="text-xs text-muted-foreground">
                                                Yang punya akun mendapat undangan sekarang, pengingat H-1, dan 2 jam sebelum rapat. Pengawas proyek
                                                ikut diberi tahu otomatis.
                                            </p>
                                            <AttendeePicker value={field.value} onChange={field.onChange} options={execution.attendeeOptions} />
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setView(meeting ? 'scheduled' : 'choose')}
                                        disabled={saving}
                                    >
                                        Kembali
                                    </Button>
                                    <Button
                                        type="button"
                                        disabled={saving}
                                        onClick={() => {
                                            form.setValue('mode', 'JADWAL');
                                            void form.handleSubmit(onSubmit)();
                                        }}
                                    >
                                        {saving ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                                        {meeting?.invited_at ? 'Simpan & beri tahu' : 'Jadwalkan & kirim undangan'}
                                    </Button>
                                </div>
                            </div>
                        )}

                        {view === 'scheduled' && meeting && (
                            <div className="flex flex-col gap-3 rounded-lg border-2 border-daiku-yellow bg-daiku-yellow-light p-4 sm:flex-row sm:items-start">
                                <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-daiku-yellow text-daiku-dark">
                                    <CalendarClock className="size-5" />
                                </span>
                                <div className="min-w-0 flex-1 space-y-1">
                                    <p className="font-semibold text-foreground">TM dijadwalkan {meetingWhen(meeting)}</p>
                                    <p className="text-sm text-muted-foreground">
                                        {meeting.attendees.length} orang diundang
                                        {meeting.invited_at ? ` · undangan terkirim ${formatDateTime(meeting.invited_at)}` : ''} · pengingat H-1 dan 2
                                        jam sebelum rapat
                                    </p>
                                    {meeting.attendees.length > 0 && (
                                        <p className="text-sm text-foreground">{meeting.attendees.map((row) => row.label).join(', ')}</p>
                                    )}
                                </div>
                                <div className="flex flex-col gap-2 sm:items-end">
                                    <Button type="button" onClick={() => setView('meeting')}>
                                        <ClipboardPen className="size-4" />
                                        Mulai TM · isi notulen
                                    </Button>
                                    <Button type="button" variant="outline" onClick={() => setView('schedule')}>
                                        Ubah jadwal / undangan
                                    </Button>
                                </div>
                            </div>
                        )}

                        {view === 'meeting' && (
                            <div className="space-y-4">
                                {meeting?.mode === 'JADWAL' && (
                                    <p className="rounded-md bg-daiku-gray/60 px-3 py-2 text-sm text-muted-foreground">
                                        Dijadwalkan {meetingWhen(meeting)}. Peserta yang diundang sudah terisi — sesuaikan dengan yang hadir, lalu isi
                                        notulen.
                                    </p>
                                )}
                                <div className="grid gap-4 sm:grid-cols-[14rem_10rem]">
                                    <FormField
                                        control={form.control}
                                        name="meeting_date"
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormLabel required>Tanggal TM</FormLabel>
                                                <FormControl>
                                                    <Input type="date" {...field} />
                                                </FormControl>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                    <FormField
                                        control={form.control}
                                        name="meeting_time"
                                        render={({ field }) => (
                                            <FormItem>
                                                <FormLabel>Jam</FormLabel>
                                                <FormControl>
                                                    <Input type="time" {...field} value={field.value ?? ''} />
                                                </FormControl>
                                                <FormMessage />
                                            </FormItem>
                                        )}
                                    />
                                </div>

                                <FormField
                                    control={form.control}
                                    name="notes"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Notulen</FormLabel>
                                            <FormControl>
                                                <Textarea
                                                    {...field}
                                                    rows={12}
                                                    className="min-h-64"
                                                    placeholder="Hasil rapat: urutan kerja, bahan, catatan tiap divisi…"
                                                />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />

                                <FormField
                                    control={form.control}
                                    name="attendees"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Peserta</FormLabel>
                                            <AttendeePicker value={field.value} onChange={field.onChange} options={execution.attendeeOptions} />
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                            </div>
                        )}
                    </TmStep>

                    <TmStep
                        number={2}
                        title="Pembagian Timeline"
                        holder="PM"
                        done={isDone('timeline')}
                        description="Bagi pekerjaan per tahap dengan tanggal mulai–selesai. Tersimpan langsung — daily target Pengawas nanti harus berada di dalam rentang ini."
                    >
                        <TimelinePlanner
                            timelines={sortedTimelines}
                            canManage={canManageTimeline}
                            onAdd={() => openTimeline(null)}
                            onEdit={openTimeline}
                            onDelete={setDeletingTimeline}
                        />
                    </TmStep>

                    <TmStep
                        number={3}
                        title={execution.usesWorkItems ? 'Tanggal Selesai Proyek' : 'Tanggal Selesai & Upah Tukang'}
                        holder="PM"
                        done={isDone('end_date')}
                        description={
                            execution.usesWorkItems
                                ? 'Wajib untuk TM Selesai (dasar pengingat H-30%). Upah tukang diisi Pengawas per item RAB setelah TM; DP 10 % diajukan Pengawas dan nominalnya diputuskan PM saat menyetujui.'
                                : 'Tanggal Selesai wajib untuk TM Selesai (dasar pengingat H-30%). Nilai upah tukang boleh diisi nanti — dibutuhkan sebelum Pengawas mengajukan DP.'
                        }
                    >
                        <div className={cn('grid gap-4', execution.usesWorkItems ? 'sm:max-w-xs' : 'sm:grid-cols-3')}>
                            {/* Sprint 33 — per-item projects: upah & DP are not part of the TM any more (Langkah Kerja, Persiapan). */}
                            {!execution.usesWorkItems && (
                                <FormField
                                    control={form.control}
                                    name="fee_total"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel required={fee !== null}>Nilai Upah Tukang</FormLabel>
                                            <FormControl>
                                                <MoneyInput value={field.value} onChange={field.onChange} placeholder="0" />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                            )}
                            {!execution.usesWorkItems && (
                                <FormField
                                    control={form.control}
                                    name="dp_percent"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel required>DP Upah Tukang (%)</FormLabel>
                                            <FormControl>
                                                <Input type="number" min={0} max={100} step="0.5" inputMode="decimal" {...field} />
                                            </FormControl>
                                            {feeTotal > 0 && (
                                                <p className="text-xs text-muted-foreground">= {formatRupiah((feeTotal * dpPercent) / 100)}</p>
                                            )}
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                            )}
                            <FormField
                                control={form.control}
                                name="end_date"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Tanggal Selesai Proyek</FormLabel>
                                        <FormControl>
                                            <Input type="date" {...field} />
                                        </FormControl>
                                        <p className="text-xs text-muted-foreground">Usulan: selesai timeline terakhir.</p>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                    </TmStep>

                    <div className="flex flex-col-reverse items-stretch gap-2 sm:flex-row sm:items-center sm:justify-end">
                        {dirty ? (
                            <p className="text-xs text-warning-ink sm:mr-auto">Ada perubahan langkah 1 / 3 yang belum disimpan.</p>
                        ) : (
                            saved && (
                                <p className="flex items-center gap-1 text-xs font-medium text-success-ink sm:mr-auto" role="status">
                                    Tersimpan
                                    <Check className="size-3.5" />
                                </p>
                            )
                        )}
                        <Button type="submit" variant={dirty ? 'default' : 'outline'} className="max-md:h-11" disabled={saving}>
                            {saving && <Loader2 className="size-4 animate-spin" />}
                            {saving ? 'Menyimpan…' : 'Simpan TM'}
                        </Button>
                    </div>
                </form>
            </Form>

            {/* Sprint 29 Sub 03 (D2) — the Pengawas's part: not a TM requirement, checked by the DP conditions. */}
            <div id="tm-bahan" className="mt-5 scroll-mt-24 rounded-lg border border-dashed border-border p-4">
                <p className="text-sm font-semibold text-foreground">Bagian Pengawas</p>
                <p className="mb-4 text-xs text-muted-foreground">
                    Bahan, upah & daily target diisi {project.supervisors?.map((person) => person.name).join(', ') || 'Pengawas proyek'} — boleh
                    setelah TM, sebelum Start Produksi. Tidak menahan TM Selesai.
                </p>
                {execution.usesWorkItems && execution.workItems && (
                    <div id="tm-upah" className="mb-5 scroll-mt-24">
                        <TmStep
                            number={4}
                            title="Upah Tukang per Item RAB"
                            holder="Pengawas"
                            done={execution.workItems.summary.paid_items > 0}
                            description="Isi satuan upah di kolom kanan tiap item RAB — lalu tekan Simpan di bawah tabel. Totalnya jadi nilai upah tukang proyek."
                        >
                            <WorkItemWageTable projectId={project.id} execution={execution} fieldStaff={fieldStaff} />
                        </TmStep>
                    </div>
                )}
                <TmStep
                    number={execution.usesWorkItems && execution.workItems ? 5 : 4}
                    title="Bahan yang Digunakan"
                    holder="Pengawas"
                    done={execution.dpRequirements.find((row) => row.key === 'materials')?.done ?? false}
                    description="Cari barang, isi jumlah, Enter."
                >
                    <MaterialsPlanning project={project} execution={execution} />
                </TmStep>
            </div>

            {/* Sprint 28 K14 — status only; the one "TM Selesai" button is in the page header. */}
            <div className="mt-6 rounded-lg bg-daiku-gray/60 p-4">
                <p className="mb-3 text-sm font-medium text-foreground">Syarat TM Selesai</p>
                <RequirementList items={requirements} />
                {dirty ? (
                    <p className="mt-3 text-xs text-warning-ink">Simpan TM dulu — ada perubahan yang belum disimpan.</p>
                ) : allDone ? (
                    <p className="mt-3 flex items-center gap-1.5 text-xs font-medium text-success-ink">
                        <CheckCircle2 className="size-3.5" />
                        Semua syarat lengkap. Tombol TM Selesai ada di atas halaman.
                    </p>
                ) : (
                    <p className="mt-3 text-xs text-muted-foreground">
                        Lengkapi syarat di atas, lalu tekan TM Selesai di atas halaman — upah & bahan dari Pengawas tidak perlu ditunggu.
                    </p>
                )}
            </div>

            {canManageTimeline && (
                <MilestoneFormDialog
                    open={timelineOpen}
                    onOpenChange={setTimelineOpen}
                    projectId={project.id}
                    editing={editingTimeline}
                    suggestedStart={suggestedStart}
                    preserveState
                />
            )}
            <ConfirmDialog
                open={deletingTimeline !== null}
                onOpenChange={(open) => !open && setDeletingTimeline(null)}
                tone="danger"
                title="Hapus timeline?"
                description={`"${deletingTimeline?.name ?? ''}" dihapus dari proyek ini.`}
                confirmLabel="Ya, hapus"
                busy={removingTimeline}
                onConfirm={deleteTimeline}
            />
        </SectionCard>
    );
}

function dayCount(start: string | null, end: string): number | null {
    return start ? differenceInCalendarDays(new Date(`${end}T00:00:00`), new Date(`${start}T00:00:00`)) + 1 : null;
}

/** Step 2 — the project's Timelines as a compact, editable list (the Gantt stays on the Timeline tab). */
function TimelinePlanner({
    timelines,
    canManage,
    onAdd,
    onEdit,
    onDelete,
}: {
    timelines: Milestone[];
    canManage: boolean;
    onAdd: () => void;
    onEdit: (milestone: Milestone) => void;
    onDelete: (milestone: Milestone) => void;
}) {
    const first = timelines.find((row) => row.start_date)?.start_date ?? null;
    const last = timelines.reduce<string | null>((max, row) => (!max || row.target_date > max ? row.target_date : max), null);
    const span = last ? dayCount(first, last) : null;

    return (
        <div className="space-y-3">
            {timelines.length === 0 ? (
                <Notice tone="info">Belum ada timeline — mis. "Produksi Kabinet", lalu "Pemasangan & Finishing".</Notice>
            ) : (
                <ol className="divide-y divide-border rounded-lg ring-1 ring-border">
                    {timelines.map((row, index) => {
                        const days = dayCount(row.start_date, row.target_date);

                        return (
                            <li key={row.id} className="flex items-center gap-3 px-3 py-2.5">
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium text-foreground">
                                        {index + 1}. {row.name}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {row.start_date ? `${formatDate(row.start_date)} – ` : 'Selesai '}
                                        {formatDate(row.target_date)}
                                        {days !== null && ` · ${days} hari`}
                                    </p>
                                </div>
                                <StatusChip domain="milestone" status={row.status} waitingOn={null} className="hidden sm:inline-flex" />
                                {canManage && (
                                    <div className="flex shrink-0 items-center">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            aria-label={`Ubah ${row.name}`}
                                            onClick={() => onEdit(row)}
                                        >
                                            <Pencil className="size-4" />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon-sm"
                                            aria-label={`Hapus ${row.name}`}
                                            onClick={() => onDelete(row)}
                                        >
                                            <Trash2 className="size-4 text-error-ink" />
                                        </Button>
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ol>
            )}
            <div className="flex flex-wrap items-center justify-between gap-2">
                {canManage ? (
                    <Button type="button" variant="outline" size="sm" onClick={onAdd}>
                        <Plus className="size-4" />
                        Tambah Timeline
                    </Button>
                ) : (
                    <span />
                )}
                {span !== null && first && last && (
                    <p className="text-xs text-muted-foreground">
                        Total {formatDate(first)} – {formatDate(last)} · {span} hari
                    </p>
                )}
            </div>
        </div>
    );
}

/** Read-only TM card (the Pengawas during TM): plan the materials next to the RAB items. */
/**
 * Sprint 33 — the Pengawas's first job after the TM is set: the Satuan Upah
 * per RAB item, right in the TM card (same table as Item RAB › Upah Tukang).
 */
function SupervisorWages({
    project,
    execution,
    fieldStaff,
}: {
    project: Project;
    execution: ProjectExecution;
    fieldStaff: Pick<User, 'id' | 'name' | 'is_active'>[];
}) {
    if (execution.phase !== 'TM' || !execution.usesWorkItems || !execution.workItems) {
        return null;
    }

    return (
        <div id="tm-upah" className="mt-5 scroll-mt-24 border-t border-border pt-5">
            <h3 className="text-sm font-semibold text-foreground">
                Upah Tukang per Item RAB <span className="font-normal text-muted-foreground">· Pengawas</span>
            </h3>
            <p className="mt-0.5 mb-3 text-xs text-muted-foreground">
                {execution.can.setWages
                    ? 'Isi satuan upah di kolom kanan tiap item RAB — lalu tekan Simpan di bawah tabel. Totalnya jadi nilai upah tukang proyek.'
                    : `Diisi ${project.supervisors?.map((person) => person.name).join(', ') || 'Pengawas proyek'}.`}
            </p>
            <WorkItemWageTable projectId={project.id} execution={execution} fieldStaff={fieldStaff} />
        </div>
    );
}

function ReadOnlyMaterials({ project, execution }: { project: Project; execution: ProjectExecution }) {
    if (execution.phase !== 'TM' || (execution.meetingRab.length === 0 && !execution.can.planMaterials)) {
        return null;
    }

    return (
        <div id="tm-bahan" className="mt-5 scroll-mt-24 border-t border-border pt-5">
            <h3 className="text-sm font-semibold text-foreground">
                Bahan yang Digunakan <span className="font-normal text-muted-foreground">· Pengawas</span>
            </h3>
            {execution.can.planMaterials && (
                <p className="mt-0.5 text-xs text-muted-foreground">
                    {execution.can.submitMaterialPlan
                        ? 'Cari barang, isi jumlah, Enter — lalu "Ajukan ke Logistik". Rencana bisa diubah sampai diajukan.'
                        : 'Cari barang, isi jumlah, Enter — rencana bisa diubah sampai produksi dimulai.'}
                </p>
            )}
            <div className="mt-3">
                <MaterialsPlanning project={project} execution={execution} />
            </div>
        </div>
    );
}

/**
 * Step 4 — the Pengawas's planned materials (the inline planner for whoever
 * may plan, a list otherwise), with the approved RAB's work items
 * (description + volume, no prices) beside them as the reference.
 */
function MaterialsPlanning({ project, execution }: { project: Project; execution: ProjectExecution }) {
    const rab = execution.meetingRab;
    const materials = execution.meetingMaterials;
    const labelClass = 'mb-2 text-xs font-medium tracking-wide text-daiku-muted uppercase';

    return (
        <div className={cn('grid gap-4', rab.length > 0 && 'lg:grid-cols-2')}>
            {rab.length > 0 && (
                <div className="min-w-0">
                    <p className={labelClass}>Acuan · Item RAB</p>
                    <div className="max-h-[28rem] overflow-y-auto rounded-lg ring-1 ring-border">
                        {rab.map((group) => (
                            <div key={group.id}>
                                <p className="sticky top-0 bg-daiku-yellow-light px-3 py-1.5 text-xs font-semibold text-foreground">{group.title}</p>
                                <ul className="divide-y divide-border">
                                    {group.items.map((item) => (
                                        <li key={item.id} className="flex items-start justify-between gap-3 px-3 py-2 text-sm">
                                            <span className="min-w-0 text-foreground">{item.description}</span>
                                            <span className="shrink-0 text-muted-foreground tabular-nums">{item.volume}</span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ))}
                    </div>
                </div>
            )}
            <div className="min-w-0">
                <p className={labelClass}>Bahan direncanakan ({materials.length})</p>
                {execution.can.planMaterials ? (
                    <TmMaterialPlanner
                        projectId={project.id}
                        lines={materials}
                        canUpdate={execution.can.updateMaterials}
                        canRemove={execution.can.removeMaterials}
                        throughLogistics={execution.can.submitMaterialPlan}
                    />
                ) : materials.length === 0 ? (
                    <p className="rounded-lg border border-dashed border-border px-3 py-6 text-center text-sm text-muted-foreground">
                        Belum ada bahan — menunggu Pengawas.
                    </p>
                ) : (
                    <ul className="max-h-80 divide-y divide-border overflow-y-auto rounded-lg ring-1 ring-border">
                        {materials.map((line) => (
                            <li key={line.id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                <span className="min-w-0">
                                    <span className="block truncate text-foreground">{line.name}</span>
                                    {line.channel === 'RENCANA' && <MaterialStatusChip line={line} unit={line.unit} inline className="mt-0.5" />}
                                </span>
                                <span className="shrink-0 text-muted-foreground tabular-nums">{line.qty}</span>
                            </li>
                        ))}
                    </ul>
                )}
                <Button variant="link" size="sm" className="mt-1 h-auto px-0" asChild>
                    <Link href={route('projects.show', { project: project.id, tab: 'material' })}>
                        <ExternalLink className="size-3.5" />
                        Buka tab Material (vendor, ajukan barang di luar katalog)
                    </Link>
                </Button>
            </div>
        </div>
    );
}
