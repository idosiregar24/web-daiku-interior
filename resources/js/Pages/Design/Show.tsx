import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { DatePicker } from '@/Components/shared/DatePicker';
import { SectionCard } from '@/Components/shared/SectionCard';
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
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectSeparator,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import { ClientAccDialog } from '@/Components/modules/design/ClientAccDialog';
import { AssignDesignDialog } from '@/Components/modules/design/AssignDesignDialog';
import { DesignConfirmDialog } from '@/Components/modules/design/DesignConfirmDialog';
import { DesignDiscussionPanel } from '@/Components/modules/design/DesignDiscussionPanel';
import { DesignReadyDialog } from '@/Components/modules/design/DesignReadyDialog';
import { DesignRevisionDialog } from '@/Components/modules/design/DesignRevisionDialog';
import { SendDesignDialog } from '@/Components/modules/design/SendDesignDialog';
import { AutoProjectRabNotice, type RunningProjectRab } from '@/Components/modules/quotation/AutoProjectRabNotice';
import { Notice } from '@/Components/shared/Notice';
import { formatRupiah } from '@/lib/format';
import AppLayout from '@/Layouts/AppLayout';
import type { Design, DesignDiscussionThread, DesignStaffMember, DesignStatus, ProjectType, User } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, Link, router } from '@inertiajs/react';
import { format } from 'date-fns';
import { History, Info, Palette, PenLine, Plus, Send, Trash2, UserPlus } from 'lucide-react';
import { Fragment, useEffect, useMemo, useState } from 'react';
import { type FieldPath, useFieldArray, useForm } from 'react-hook-form';
import { z } from 'zod';

type DesignDetail = Design & { lead: { id: number; client_name: string }; staff?: DesignStaffMember[] };

interface DesignShowProps {
    design: DesignDetail;
    canManage: boolean;
    /** Pre-Sprint-12 designs only — the old Client ACC button. */
    canClientAcc: boolean;
    /** Sprint 12 #15 — Kepala Desain: assign / reassign the team. */
    canAssign: boolean;
    /** Sprint 12 #17 — Marketing: send to the client, ask a revision, record the approval. */
    canMarketingActions: boolean;
    /** Sprint 22 — the design's team: hand the finished design / revision to Marketing. */
    canMarkReady: boolean;
    /** Sprint 22 K7 — the lead's No. HP, sent to Marketing only. */
    clientPhone: string | null;
    /** Sprint 12 D6 — null when the viewer may not see the thread. */
    discussion: DesignDiscussionThread | null;
    /** Every DESIGNER — `is_active` decides who can still be added as sub-staff. */
    designers: Pick<User, 'id' | 'name' | 'is_active'>[];
    /** Sprint 17 Sub 04 — the lead's running RAB Proyek, if any. */
    projectRab: RunningProjectRab | null;
}

/**
 * Stages that need Client ACC first — mirrors
 * DesignService::STATUSES_REQUIRING_CLIENT_ACC (a manual change to one of
 * them is refused server-side while `client_acc` is false).
 */
const ACC_REQUIRED_STATUSES: DesignStatus[] = [
    'ACC_DESAIN', 'GAMBAR_RAB', 'PEMBUATAN_PENAWARAN', 'WAITING_ACC_PENAWARAN',
    'PRODUKSI', 'DONE_PRODUKSI', 'REJECT_PRODUKSI',
];

const STATUS_GROUPS: { label: string; statuses: DesignStatus[] }[] = [
    { label: 'Tahap desain', statuses: ['BRIEF', 'DESAIN', 'WAITING_ACC_DESAIN', 'REVISI_DESAIN'] },
    { label: 'Setelah ACC klien', statuses: ACC_REQUIRED_STATUSES },
    { label: 'Ditangguhkan klien', statuses: ['HOLD_CLIENT', 'REVISI_CLIENT'] },
];

// Sprint 12 #16 — set by the payment flow only, never picked (a design in them isn't editable anyway).
const STATUS_OPTIONS: DesignStatus[] = ['MENUNGGU_BAYAR', 'MENUNGGU_PENUGASAN', ...STATUS_GROUPS.flatMap((group) => group.statuses)];

const JENIS_PROJECT_OPTIONS: ProjectType[] = [
    'TOKO', 'CAFE', 'RENOVASI', 'KAMAR_SET', 'KITCHEN_SET',
    'KANTOR', 'ARSITEKTURAL', 'RUANG_TAMU_TV', 'RETAIL_TOKO', 'LAINNYA',
];

const baseSchema = z.object({
    // Required only for a design outside the RAB flow — see buildSchema().
    pic_id: z.string(),
    jenis_project: z.string().optional(),
    status: z.enum(STATUS_OPTIONS as [DesignStatus, ...DesignStatus[]]),
    target_hari: z.string().optional(),
    start_date: z.date().optional(),
    brief_note: z.string().optional(),
    problem: z.string().optional(),
    design_urls: z.array(z.object({ value: z.string() })),
    staff: z.array(
        z.object({
            user_id: z.string().min(1, 'Pilih arsitek'),
            role_note: z.string().max(100, 'Peran maksimal 100 karakter'),
        }),
    ),
});

type FormValues = z.infer<typeof baseSchema>;

/**
 * Mirrors UpdateDesignRequest + DesignService::update(): no post-ACC
 * stage before Client ACC (only a *change* is checked), and each
 * sub-staff member once, never the PIC. A design born from a RAB Jasa
 * Desain (flowManaged) only saves its brief — the server then accepts no
 * PIC/team/timeline/status at all, so none of them is checked here.
 */
function buildSchema(clientAcc: boolean, currentStatus: DesignStatus, flowManaged: boolean) {
    return baseSchema.superRefine((values, ctx) => {
        if (flowManaged) {
            return;
        }

        if (!values.pic_id) {
            ctx.addIssue({ code: 'custom', path: ['pic_id'], message: 'PIC wajib dipilih' });
        }

        if (!clientAcc && values.status !== currentStatus && ACC_REQUIRED_STATUSES.includes(values.status)) {
            ctx.addIssue({
                code: 'custom',
                path: ['status'],
                message: 'Status ini baru bisa dipilih setelah desain di-ACC klien.',
            });
        }

        const seen = new Set<string>();
        values.staff.forEach((member, index) => {
            if (!member.user_id) return;

            if (member.user_id === values.pic_id) {
                ctx.addIssue({
                    code: 'custom',
                    path: ['staff', index, 'user_id'],
                    message: 'PIC utama tidak perlu ditambahkan lagi sebagai sub-staff.',
                });
            } else if (seen.has(member.user_id)) {
                ctx.addIssue({
                    code: 'custom',
                    path: ['staff', index, 'user_id'],
                    message: 'Arsitek yang sama dipilih lebih dari sekali.',
                });
            }

            seen.add(member.user_id);
        });
    });
}

function toFormValues(design: DesignDetail): FormValues {
    return {
        pic_id: design.pic_id ? String(design.pic_id) : '',
        jenis_project: design.jenis_project ?? '',
        status: design.status,
        target_hari: design.target_hari ? String(design.target_hari) : '',
        start_date: design.start_date ? new Date(design.start_date) : undefined,
        brief_note: design.brief_note ?? '',
        problem: design.problem ?? '',
        design_urls: (design.design_urls ?? []).map((value) => ({ value })),
        staff: (design.staff ?? []).map((member) => ({
            user_id: String(member.id),
            role_note: member.pivot.role_note ?? '',
        })),
    };
}

/**
 * Design brief form + link list + status badge (.claude/plan/sprint-02.md
 * Week 4, Ido task 1) + Client ACC trigger (task 2) + sub-staff and the
 * Client-ACC status guard (Sprint 9). Reached from the CRM Lead index's
 * "Buka Desain" action or the Desain list.
 */
export default function DesignShow({
    design,
    canManage,
    canClientAcc,
    canAssign,
    canMarketingActions,
    canMarkReady,
    clientPhone,
    discussion,
    designers,
    projectRab,
}: DesignShowProps) {
    const [accOpen, setAccOpen] = useState(false);
    const [readyOpen, setReadyOpen] = useState(false);
    const [assignOpen, setAssignOpen] = useState(false);
    const [revisionOpen, setRevisionOpen] = useState(false);
    const [confirm, setConfirm] = useState<'send' | 'approve' | null>(null);
    // Sprint 12: team, timeline and status come from the Kepala Desain / Marketing actions.
    const flowManaged = design.quotation_id !== null;
    const isLocked = design.status === 'MENUNGGU_BAYAR' || design.status === 'MENUNGGU_PENUGASAN';
    const hasLinks = (design.design_urls ?? []).length > 0;
    const canSend = canMarketingActions && (design.status === 'DESAIN' || design.status === 'REVISI_DESAIN');
    const awaitingClient = canMarketingActions && design.status === 'WAITING_ACC_DESAIN';
    const readyAt = design.ready_for_client_at
        ? new Date(design.ready_for_client_at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })
        : null;

    const schema = useMemo(
        () => buildSchema(design.client_acc, design.status, flowManaged),
        [design.client_acc, design.status, flowManaged],
    );

    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: toFormValues(design),
    });

    const { fields, append, remove } = useFieldArray({ control: form.control, name: 'design_urls' });
    const {
        fields: staffFields,
        append: appendStaff,
        remove: removeStaff,
    } = useFieldArray({ control: form.control, name: 'staff' });

    const picId = form.watch('pic_id');
    const watchedStaff = form.watch('staff');

    // Active designers can be added; anyone already on the team (even if
    // since deactivated or no longer a designer) stays selectable in
    // their own row — same rule as UpdateDesignRequest.
    const staffOptions = useMemo(() => {
        const options = new Map<string, { id: string; name: string; assignable: boolean }>();
        designers.forEach((designer) =>
            options.set(String(designer.id), {
                id: String(designer.id),
                name: designer.name,
                assignable: designer.is_active !== false,
            }),
        );
        (design.staff ?? []).forEach((member) => {
            if (!options.has(String(member.id))) {
                options.set(String(member.id), { id: String(member.id), name: member.name, assignable: false });
            }
        });

        return [...options.values()];
    }, [designers, design.staff]);

    useEffect(() => {
        form.reset(toFormValues(design));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [design.id]);

    // The same design changed on the server (e.g. the Kepala Desain assigned
    // the PIC in AssignDesignDialog): take the new values, but keep any brief
    // field the user has edited and not saved yet.
    useEffect(() => {
        form.reset(toFormValues(design), { keepDirtyValues: true });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [design.updated_at]);

    // Sprint 22 — "Perlu Tindakan → Desain siap dikirim" and its notification link here with ?action=send.
    useEffect(() => {
        if (canSend && hasLinks && new URLSearchParams(window.location.search).get('action') === 'send') {
            setConfirm('send');
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [design.id]);

    const briefDirty = form.formState.isDirty;

    function onSubmit(values: FormValues) {
        const onError = (errors: Record<string, string>) => {
            Object.entries(errors).forEach(([field, message]) => {
                // Server keys design_urls.N map onto the field array's `.value` inputs; staff.N.user_id matches as is.
                const [root, index] = field.split('.');
                const path = root === 'design_urls' && index !== undefined ? `design_urls.${index}.value` : field;
                form.setError(path as FieldPath<FormValues>, { message });
            });
        };

        const brief = {
            jenis_project: values.jenis_project || null,
            brief_note: values.brief_note || null,
            problem: values.problem || null,
            design_urls: values.design_urls.map((u) => u.value).filter((v) => v.trim() !== ''),
        };

        // A flow-managed design sends its brief only — the team, timeline and
        // status come from the Kepala Desain / Marketing actions (UpdateDesignRequest).
        const payload = flowManaged
            ? brief
            : {
                  ...brief,
                  pic_id: Number(values.pic_id),
                  status: values.status,
                  target_hari: values.target_hari ? Number(values.target_hari) : null,
                  start_date: values.start_date ? format(values.start_date, 'yyyy-MM-dd') : null,
                  staff: values.staff.map((member) => ({
                      user_id: Number(member.user_id),
                      role_note: member.role_note.trim() || null,
                  })),
              };

        router.put(route('design.update', { design: design.id }), payload, { onError });
    }

    const canOpenClientAcc = canClientAcc && !design.client_acc && design.status === 'WAITING_ACC_DESAIN';
    const isStatusLocked = (status: DesignStatus) =>
        !design.client_acc && status !== design.status && ACC_REQUIRED_STATUSES.includes(status);
    const staffError = form.formState.errors.staff?.message;

    return (
        <AppLayout
            breadcrumbs={[{ label: design.lead.client_name }]}
        >
            <Head title={`Desain — ${design.lead.client_name}`} />

            <PageHeader
                title={`Desain: ${design.lead.client_name}`}
                icon={Palette}
                description="Brief, tim desain, link desain, dan status pipeline desain."
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        <StatusChip status={design.status} />
                        {canOpenClientAcc && (
                            <Button onClick={() => setAccOpen(true)}>Konfirmasi ACC Klien</Button>
                        )}
                        {canAssign && (
                            <Button variant={design.pic_id ? 'outline' : 'default'} onClick={() => setAssignOpen(true)}>
                                {design.pic_id ? 'Ubah Penugasan' : 'Tugaskan Desain'}
                            </Button>
                        )}
                        {canMarkReady && (
                            <Button
                                onClick={() => setReadyOpen(true)}
                                disabled={!hasLinks || briefDirty}
                                title={
                                    !hasLinks
                                        ? 'Simpan link desain (Drive / Figma) dulu.'
                                        : briefDirty
                                          ? 'Simpan Brief dulu agar Marketing menerima link terbaru.'
                                          : undefined
                                }
                            >
                                <Send className="size-4" />
                                Desain Siap Dikirim
                            </Button>
                        )}
                        {canSend && (
                            <Button
                                variant={design.ready_for_client_at ? 'default' : 'outline'}
                                onClick={() => setConfirm('send')}
                                disabled={!hasLinks}
                                title={hasLinks ? undefined : 'Arsitek belum mengunggah link desain.'}
                            >
                                Kirim Desain ke Klien
                            </Button>
                        )}
                        {awaitingClient && (
                            <>
                                <Button variant="outline" onClick={() => setRevisionOpen(true)}>
                                    Minta Revisi
                                </Button>
                                <Button onClick={() => setConfirm('approve')}>Desain Disetujui Klien</Button>
                            </>
                        )}
                        {design.client_acc && (
                            <span className="text-xs text-daiku-muted">
                                Di-ACC {design.acc_date ? new Date(design.acc_date).toLocaleDateString('id-ID') : ''}
                            </span>
                        )}
                    </div>
                }
            />

            {design.status === 'MENUNGGU_BAYAR' && (
                <Notice tone="warning" className="mb-6">
                    Klien sudah menyetujui RAB Jasa Desain, tetapi pembayarannya belum diverifikasi Finance — desain terkunci.
                </Notice>
            )}
            {design.status === 'MENUNGGU_PENUGASAN' && (
                <Notice tone="info" className="mb-6">
                    Pembayaran jasa desain sudah diverifikasi — menunggu Kepala Desain menugaskan arsitek.
                </Notice>
            )}
            {flowManaged && design.status === 'DESAIN' && !hasLinks && (
                <Notice tone="info" className="mb-6">
                    Unggah link desain (Drive / Figma) di form brief dan simpan, lalu tekan &ldquo;Desain Siap Dikirim&rdquo; agar
                    Marketing mengirimkannya ke klien.
                </Notice>
            )}
            {readyAt && (
                <Notice tone={canSend ? 'warning' : 'info'} className="mb-6">
                    {canSend
                        ? `Arsitek menandai desain siap dikirim pada ${readyAt} — kirim ke klien.`
                        : `Ditandai siap dikirim pada ${readyAt} — menunggu Marketing mengirim ke klien.`}
                    {design.ready_note && (
                        <span className="mt-1 block">
                            <span className="font-medium">Catatan arsitek:</span> {design.ready_note}
                        </span>
                    )}
                </Notice>
            )}
            <AutoProjectRabNotice rab={projectRab} className="mb-6" />

            <div className="grid gap-6 lg:grid-cols-3">
                <SectionCard title="Brief Desain" icon={PenLine} className="lg:col-span-2">
                    <Form {...form}>
                        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                            <div className="grid grid-cols-2 gap-4">
                                <FormField
                                    control={form.control}
                                    name="pic_id"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel required>PIC Arsitek</FormLabel>
                                            <Select value={field.value} onValueChange={field.onChange} disabled={!canManage || flowManaged}>
                                                <FormControl>
                                                    <SelectTrigger className="w-full">
                                                        <SelectValue placeholder="Pilih PIC" />
                                                    </SelectTrigger>
                                                </FormControl>
                                                <SelectContent>
                                                    {designers.map((designer) => (
                                                        <SelectItem key={designer.id} value={String(designer.id)}>
                                                            {designer.name}
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
                                    name="jenis_project"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Jenis Project</FormLabel>
                                            <Select
                                                value={field.value || 'none'}
                                                onValueChange={(value) => field.onChange(value === 'none' ? '' : value)}
                                                disabled={!canManage}
                                            >
                                                <FormControl>
                                                    <SelectTrigger className="w-full">
                                                        <SelectValue placeholder="Pilih jenis" />
                                                    </SelectTrigger>
                                                </FormControl>
                                                <SelectContent>
                                                    <SelectItem value="none">—</SelectItem>
                                                    {JENIS_PROJECT_OPTIONS.map((option) => (
                                                        <SelectItem key={option} value={option}>
                                                            {option.replace('_', ' ')}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                            </div>

                            <div>
                                <div className="mb-2 flex items-center justify-between">
                                    <FormLabel>Asisten Arsitek</FormLabel>
                                    {canManage && !flowManaged && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => appendStaff({ user_id: '', role_note: '' })}
                                        >
                                            <UserPlus className="size-4" />
                                            Tambah Asisten
                                        </Button>
                                    )}
                                </div>
                                {staffFields.length === 0 ? (
                                    <p className="text-sm text-daiku-muted">Belum ada asisten — hanya PIC arsitek.</p>
                                ) : (
                                    <div className="space-y-2">
                                        {staffFields.map((item, index) => (
                                            <div key={item.id} className="flex flex-col gap-2 sm:flex-row sm:items-start">
                                                <FormField
                                                    control={form.control}
                                                    name={`staff.${index}.user_id`}
                                                    render={({ field }) => (
                                                        <FormItem className="sm:w-60">
                                                            <Select value={field.value} onValueChange={field.onChange} disabled={!canManage || flowManaged}>
                                                                <FormControl>
                                                                    <SelectTrigger className="w-full" aria-label={`Sub-staff ${index + 1}`}>
                                                                        <SelectValue placeholder="Pilih arsitek" />
                                                                    </SelectTrigger>
                                                                </FormControl>
                                                                <SelectContent>
                                                                    {staffOptions
                                                                        .filter((option) => option.assignable || option.id === field.value)
                                                                        .map((option) => {
                                                                            const isPic = option.id === picId;
                                                                            const takenElsewhere =
                                                                                option.id !== field.value &&
                                                                                watchedStaff.some((member) => member.user_id === option.id);

                                                                            return (
                                                                                <SelectItem
                                                                                    key={option.id}
                                                                                    value={option.id}
                                                                                    disabled={isPic || takenElsewhere}
                                                                                >
                                                                                    {option.name}
                                                                                    {isPic ? ' (PIC utama)' : ''}
                                                                                    {!option.assignable ? ' (nonaktif)' : ''}
                                                                                </SelectItem>
                                                                            );
                                                                        })}
                                                                </SelectContent>
                                                            </Select>
                                                            <FormMessage />
                                                        </FormItem>
                                                    )}
                                                />
                                                <FormField
                                                    control={form.control}
                                                    name={`staff.${index}.role_note`}
                                                    render={({ field }) => (
                                                        <FormItem className="flex-1">
                                                            <FormControl>
                                                                <Input
                                                                    {...field}
                                                                    maxLength={100}
                                                                    placeholder="Peran, mis. 3D modeling"
                                                                    aria-label={`Peran sub-staff ${index + 1}`}
                                                                    disabled={!canManage}
                                                                />
                                                            </FormControl>
                                                            <FormMessage />
                                                        </FormItem>
                                                    )}
                                                />
                                                {canManage && !flowManaged && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        className="sm:mt-0.5"
                                                        aria-label={`Hapus sub-staff ${index + 1}`}
                                                        onClick={() => removeStaff(index)}
                                                    >
                                                        <Trash2 className="size-4 text-error-ink" />
                                                    </Button>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}
                                {staffError && <p className="mt-2 text-sm text-destructive">{staffError}</p>}
                            </div>

                            <div className="grid grid-cols-3 gap-4">
                                <FormField
                                    control={form.control}
                                    name="status"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel required>Status</FormLabel>
                                            <Select value={field.value} onValueChange={field.onChange} disabled={!canManage || flowManaged}>
                                                <FormControl>
                                                    <SelectTrigger className="w-full">
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                </FormControl>
                                                <SelectContent>
                                                    {STATUS_GROUPS.map((group, groupIndex) => (
                                                        <Fragment key={group.label}>
                                                            {groupIndex > 0 && <SelectSeparator />}
                                                            <SelectGroup>
                                                                <SelectLabel>
                                                                    {group.label}
                                                                    {group.statuses === ACC_REQUIRED_STATUSES && !design.client_acc
                                                                        ? ' — perlu Client ACC'
                                                                        : ''}
                                                                </SelectLabel>
                                                                {group.statuses.map((option) => (
                                                                    <SelectItem key={option} value={option} disabled={isStatusLocked(option)}>
                                                                        {option.replace(/_/g, ' ')}
                                                                    </SelectItem>
                                                                ))}
                                                            </SelectGroup>
                                                        </Fragment>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="target_hari"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Target Hari</FormLabel>
                                            <FormControl>
                                                <Input type="number" min="1" {...field} disabled={!canManage || flowManaged} />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="start_date"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Tanggal Mulai</FormLabel>
                                            <FormControl>
                                                <DatePicker value={field.value} onChange={field.onChange} disabled={!canManage || flowManaged} />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                            </div>

                            <p className="text-xs text-daiku-muted">
                                {flowManaged
                                    ? 'Tim & timeline diatur Kepala Desain; status mengikuti tombol Marketing (kirim, revisi, disetujui klien).'
                                    : design.client_acc
                                    ? 'Status maju otomatis mengikuti quotation, deal, dan proyek (kecuali saat Hold/Revisi Klien atau Reject Produksi).'
                                    : 'Tahap setelah ACC terkunci sampai klien ACC desain lewat tombol "Client ACC".'}
                            </p>

                            {design.deadline && (
                                <p className="text-xs text-daiku-muted">
                                    Deadline (otomatis): {new Date(design.deadline).toLocaleDateString('id-ID')}
                                    {design.delay_hari > 0 && (
                                        <span className="ml-2 font-medium text-error-ink">Delay {design.delay_hari} hari</span>
                                    )}
                                </p>
                            )}

                            <FormField
                                control={form.control}
                                name="brief_note"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Catatan Brief</FormLabel>
                                        <FormControl>
                                            <Textarea {...field} rows={3} disabled={!canManage} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="problem"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Problem / Kendala</FormLabel>
                                        <FormControl>
                                            <Textarea {...field} rows={2} disabled={!canManage} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />

                            <div>
                                <div className="mb-2 flex items-center justify-between">
                                    <FormLabel>Link Desain (Drive / Figma)</FormLabel>
                                    {canManage && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => append({ value: '' })}
                                        >
                                            <Plus className="size-4" />
                                            Tambah Link
                                        </Button>
                                    )}
                                </div>
                                {fields.length === 0 ? (
                                    <p className="text-sm text-daiku-muted">Belum ada link desain.</p>
                                ) : (
                                    <div className="space-y-2">
                                        {fields.map((item, index) => (
                                            <div key={item.id} className="flex items-center gap-2">
                                                <FormField
                                                    control={form.control}
                                                    name={`design_urls.${index}.value`}
                                                    render={({ field }) => (
                                                        <FormItem className="flex-1">
                                                            <FormControl>
                                                                <Input
                                                                    {...field}
                                                                    placeholder="https://..."
                                                                    disabled={!canManage}
                                                                />
                                                            </FormControl>
                                                            <FormMessage />
                                                        </FormItem>
                                                    )}
                                                />
                                                {canManage && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        onClick={() => remove(index)}
                                                    >
                                                        <Trash2 className="size-4 text-error-ink" />
                                                    </Button>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>

                            {canManage && (
                                <Button type="submit" disabled={form.formState.isSubmitting}>
                                    Simpan Brief
                                </Button>
                            )}
                        </form>
                    </Form>
                </SectionCard>

                <SectionCard title="Ringkasan" icon={Info} contentClassName="space-y-3 text-sm">
                    <div>
                        <p className="text-xs text-daiku-muted">Klien</p>
                        <p className="font-medium text-daiku-dark">{design.lead.client_name}</p>
                    </div>
                    {design.quotation && (
                        <div>
                            <p className="text-xs text-daiku-muted">RAB Jasa Desain</p>
                            <Link
                                href={route('quotations.show', { quotation: design.quotation.id })}
                                className="font-medium text-daiku-dark underline decoration-daiku-yellow underline-offset-2"
                            >
                                {formatRupiah(design.quotation.total_amount)} · v{design.quotation.version}
                            </Link>
                        </div>
                    )}
                    <div>
                        <p className="text-xs text-daiku-muted">PIC Arsitek</p>
                        <p className="font-medium text-daiku-dark">{design.pic?.name ?? '—'}</p>
                        {design.assigner && (
                            <p className="text-xs text-daiku-muted">
                                Ditugaskan {design.assigner.name}
                                {design.assigned_at && `, ${new Date(design.assigned_at).toLocaleDateString('id-ID')}`}
                            </p>
                        )}
                    </div>
                    <div>
                        <p className="text-xs text-daiku-muted">Asisten Arsitek</p>
                        {design.staff && design.staff.length > 0 ? (
                            <ul className="mt-0.5 space-y-1">
                                {design.staff.map((member) => (
                                    <li key={member.id}>
                                        <span className="font-medium text-daiku-dark">{member.name}</span>
                                        {member.pivot.role_note && (
                                            <span className="text-daiku-muted"> · {member.pivot.role_note}</span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-daiku-muted">—</p>
                        )}
                    </div>
                    <div>
                        <p className="text-xs text-daiku-muted">Status Client ACC</p>
                        <p className="font-medium text-daiku-dark">
                            {design.client_acc ? 'Sudah ACC' : 'Belum ACC'}
                        </p>
                    </div>
                    {flowManaged && (
                        <div>
                            <p className="text-xs text-daiku-muted">Jumlah Revisi</p>
                            <p className="font-medium text-daiku-dark">{design.revision_count}x</p>
                        </div>
                    )}
                </SectionCard>
            </div>

            {(design.revisions ?? []).length > 0 && (
                <SectionCard title="Riwayat Revisi" icon={History} className="mt-6" flush>
                    <ul className="divide-y divide-border">
                        {[...(design.revisions ?? [])].reverse().map((revision) => (
                            <li key={revision.id} className="px-4 py-3 text-sm sm:px-5">
                                <div className="flex flex-wrap items-baseline justify-between gap-x-2 text-xs text-daiku-muted">
                                    <span>
                                        <span className="font-medium text-daiku-dark">Revisi #{revision.sequence}</span> ·{' '}
                                        {revision.requester?.name ?? '—'}
                                    </span>
                                    <time dateTime={revision.created_at}>{new Date(revision.created_at).toLocaleString('id-ID')}</time>
                                </div>
                                <p className="mt-1 whitespace-pre-line text-daiku-dark">{revision.note}</p>
                            </li>
                        ))}
                    </ul>
                </SectionCard>
            )}

            {discussion && !isLocked && <DesignDiscussionPanel thread={discussion} className="mt-6" />}

            {canAssign && (
                <AssignDesignDialog
                    open={assignOpen}
                    onOpenChange={setAssignOpen}
                    design={{
                        id: design.id,
                        client_name: design.lead.client_name,
                        pic_id: design.pic_id,
                        staff: design.staff,
                        start_date: design.start_date,
                        target_hari: design.target_hari,
                    }}
                    architects={designers.filter((designer) => designer.is_active !== false)}
                />
            )}
            {canMarketingActions && (
                <>
                    <DesignRevisionDialog
                        open={revisionOpen}
                        onOpenChange={setRevisionOpen}
                        designId={design.id}
                        clientName={design.lead.client_name}
                        nextNumber={design.revision_count + 1}
                    />
                    <SendDesignDialog
                        open={confirm === 'send'}
                        onOpenChange={(open) => !open && setConfirm(null)}
                        design={design}
                        clientName={design.lead.client_name}
                        phone={clientPhone}
                    />
                    <DesignConfirmDialog
                        open={confirm === 'approve'}
                        onOpenChange={(open) => !open && setConfirm(null)}
                        title="Desain Disetujui Klien"
                        description={
                            projectRab
                                ? `Klien "${design.lead.client_name}" menyetujui desain ini. ${projectRab.title} klien ini sudah berjalan, jadi tidak diminta ulang.`
                                : `Klien "${design.lead.client_name}" menyetujui desain ini. Permintaan RAB Proyek otomatis dikirim ke Estimator.`
                        }
                        confirmLabel="Konfirmasi"
                        action={route('design.markClientApproved', { design: design.id })}
                    />
                </>
            )}

            {canMarkReady && (
                <DesignReadyDialog
                    open={readyOpen}
                    onOpenChange={setReadyOpen}
                    designId={design.id}
                    clientName={design.lead.client_name}
                    revisionNumber={design.status === 'REVISI_DESAIN' ? design.revision_count : 0}
                    linkCount={(design.design_urls ?? []).length}
                />
            )}

            <ClientAccDialog
                open={accOpen}
                onOpenChange={setAccOpen}
                design={design}
                clientName={design.lead.client_name}
            />
        </AppLayout>
    );
}
