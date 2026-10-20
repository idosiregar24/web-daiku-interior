import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { DatePicker } from '@/Components/shared/DatePicker';
import { SectionCard } from '@/Components/shared/SectionCard';
import { Notice } from '@/Components/shared/Notice';
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
import { DesignLinksCard } from '@/Components/modules/design/DesignLinksCard';
import { AutoProjectRabNotice, type RunningProjectRab } from '@/Components/modules/quotation/AutoProjectRabNotice';
import { formatDate, formatDateTime, formatRupiah } from '@/lib/format';
import AppLayout from '@/Layouts/AppLayout';
import type { Design, DesignDiscussionThread, DesignStaffMember, DesignStatus, ProjectType, User } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, Link, router } from '@inertiajs/react';
import { format } from 'date-fns';
import { CheckCircle2, History, Info, MessageCircle, Palette, PenLine, Plus, Trash2, UserPlus } from 'lucide-react';
import { Fragment, useEffect, useMemo, useState } from 'react';
import { type FieldPath, useFieldArray, useForm } from 'react-hook-form';
import { z } from 'zod';

type DesignDetail = Design & { lead: { id: number; client_name: string; assigned_to?: number | null }; staff?: DesignStaffMember[] };

interface DesignShowProps {
    design: DesignDetail;
    /** Pre-Sprint-12 designs only — the brief form (team, status, links). */
    canManage: boolean;
    /** Pre-Sprint-12 designs only — the old Client ACC button. */
    canClientAcc: boolean;
    /** Sprint 12 #15 — Kepala Desain: assign / reassign the team. */
    canAssign: boolean;
    /** Sprint 23 K18/K19b — the lead's Marketing or the design team: WhatsApp group & design links. */
    canEditLinks: boolean;
    /** Sprint 23 K19 — the same people: "Desain Disetujui Klien". */
    canApprove: boolean;
    /** Sprint 12 D6 — null when the viewer may not see the thread. */
    discussion: DesignDiscussionThread | null;
    /** Every DESIGNER — `is_active` decides who can still be added as sub-staff. */
    designers: Pick<User, 'id' | 'name' | 'is_active'>[];
    /** Sprint 17 Sub 04 — the lead's running RAB Proyek, if any. */
    projectRab: RunningProjectRab | null;
}

/**
 * A design's page. Since Sprint 23 (G) a design born from a RAB Jasa Desain
 * runs with the client in a WhatsApp group: the page keeps the group link +
 * design links ("Link Desain") and one action, "Desain Disetujui Klien",
 * at the top (K4b) with whose turn it is. A pre-Sprint-12 design keeps its
 * brief form (team, manual status, links) and the old Client ACC.
 */
export default function DesignShow({
    design,
    canManage,
    canClientAcc,
    canAssign,
    canEditLinks,
    canApprove,
    discussion,
    designers,
    projectRab,
}: DesignShowProps) {
    const [accOpen, setAccOpen] = useState(false);
    const [assignOpen, setAssignOpen] = useState(false);
    const [approveOpen, setApproveOpen] = useState(false);
    const flowManaged = design.quotation_id !== null;
    const isLocked = design.status === 'MENUNGGU_BAYAR' || design.status === 'MENUNGGU_PENUGASAN';
    const hasLinks = (design.design_urls ?? []).length > 0;
    const canOpenClientAcc = canClientAcc && !design.client_acc && design.status === 'WAITING_ACC_DESAIN';
    const hasActions = canApprove || Boolean(design.whatsapp_group_url) || canAssign || canOpenClientAcc;

    return (
        <AppLayout breadcrumbs={[{ label: design.lead.client_name }]}>
            <Head title={`Desain — ${design.lead.client_name}`} />

            <PageHeader
                title={`Desain: ${design.lead.client_name}`}
                icon={Palette}
                description={
                    <span className="flex flex-wrap items-center gap-2">
                        <StatusChip domain="design" status={design.status} />
                        <span>
                            {flowManaged
                                ? 'Tim desain, link grup WhatsApp & link desain, dan persetujuan klien.'
                                : 'Brief, tim desain, link desain, dan status pipeline desain.'}
                        </span>
                    </span>
                }
                step={nextStep(design, flowManaged, hasLinks, canOpenClientAcc)}
                actions={
                    hasActions ? (
                        <>
                            {canApprove && (
                                <Button
                                    onClick={() => setApproveOpen(true)}
                                    disabled={!hasLinks}
                                    title={hasLinks ? undefined : 'Simpan minimal satu link desain dulu.'}
                                >
                                    <CheckCircle2 className="size-4" />
                                    Desain Disetujui Klien
                                </Button>
                            )}
                            {design.whatsapp_group_url && (
                                <Button asChild variant="outline">
                                    <a href={design.whatsapp_group_url} target="_blank" rel="noopener noreferrer">
                                        <MessageCircle className="size-4" />
                                        Buka Grup WA
                                    </a>
                                </Button>
                            )}
                            {canAssign && (
                                <Button variant={design.pic_id ? 'outline' : 'default'} onClick={() => setAssignOpen(true)}>
                                    {design.pic_id ? 'Ubah Penugasan' : 'Tugaskan Desain'}
                                </Button>
                            )}
                            {canOpenClientAcc && <Button onClick={() => setAccOpen(true)}>Konfirmasi ACC Klien</Button>}
                        </>
                    ) : undefined
                }
            />

            {design.client_acc && (
                <Notice tone="success" className="mb-6">
                    <ClientApprovalText design={design} />
                </Notice>
            )}
            <AutoProjectRabNotice rab={projectRab} className="mb-6" />

            <div className="grid gap-6 lg:grid-cols-3">
                {flowManaged ? (
                    <DesignLinksCard design={design} canEdit={canEditLinks} locked={isLocked} className="lg:col-span-2" />
                ) : (
                    <LegacyBriefCard design={design} canManage={canManage} designers={designers} className="lg:col-span-2" />
                )}

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
                                {design.assigned_at && `, ${formatDate(design.assigned_at)}`}
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
                    {flowManaged && (
                        <div>
                            <p className="text-xs text-daiku-muted">Deadline</p>
                            <p className="font-medium text-daiku-dark">
                                {formatDate(design.deadline)}
                                {design.target_hari ? <span className="font-normal text-daiku-muted"> · target {design.target_hari} hari</span> : null}
                            </p>
                            {design.delay_hari > 0 && !design.client_acc && (
                                <p className="text-xs font-medium text-error-ink">Terlambat {design.delay_hari} hari</p>
                            )}
                        </div>
                    )}
                    <div>
                        <p className="text-xs text-daiku-muted">Persetujuan Klien</p>
                        {design.client_acc ? (
                            <p className="font-medium text-daiku-dark">
                                <ClientApprovalText design={design} />
                            </p>
                        ) : (
                            <p className="font-medium text-daiku-dark">Belum</p>
                        )}
                    </div>
                </SectionCard>
            </div>

            {(design.revisions ?? []).length > 0 && (
                <SectionCard
                    title="Riwayat Revisi"
                    description="Revisi yang tercatat sebelum revisi dibahas di grup WhatsApp."
                    icon={History}
                    className="mt-6"
                    flush
                >
                    <ul className="divide-y divide-border">
                        {[...(design.revisions ?? [])].reverse().map((revision) => (
                            <li key={revision.id} className="px-4 py-3 text-sm sm:px-5">
                                <div className="flex flex-wrap items-baseline justify-between gap-x-2 text-xs text-daiku-muted">
                                    <span>
                                        <span className="font-medium text-daiku-dark">Revisi #{revision.sequence}</span> ·{' '}
                                        {revision.requester?.name ?? '—'}
                                    </span>
                                    <time dateTime={revision.created_at}>{formatDateTime(revision.created_at)}</time>
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
            {canApprove && (
                <DesignConfirmDialog
                    open={approveOpen}
                    onOpenChange={setApproveOpen}
                    title="Desain Disetujui Klien"
                    description={
                        <>
                            Klien &ldquo;{design.lead.client_name}&rdquo; menyetujui desain ini di grup WhatsApp.{' '}
                            {projectRab
                                ? `${projectRab.title} klien ini sudah berjalan, jadi tidak diminta ulang.`
                                : 'Permintaan RAB Proyek otomatis dikirim ke Estimator.'}{' '}
                            Nama Anda dicatat sebagai yang mengonfirmasi.
                        </>
                    }
                    confirmLabel="Konfirmasi"
                    action={route('design.markClientApproved', { design: design.id })}
                />
            )}
            {canClientAcc && (
                <ClientAccDialog open={accOpen} onOpenChange={setAccOpen} design={design} clientName={design.lead.client_name} />
            )}
        </AppLayout>
    );
}

/** Sprint 23 K4b — the one line under the title: what happens next, and whose turn it is. */
function nextStep(design: DesignDetail, flowManaged: boolean, hasLinks: boolean, canOpenClientAcc: boolean): string | undefined {
    if (!flowManaged) {
        return canOpenClientAcc ? 'Desain sedang ditinjau klien — konfirmasi ACC klien bila sudah disetujui.' : undefined;
    }

    if (design.client_acc) {
        return undefined;
    }

    switch (design.status) {
        case 'MENUNGGU_BAYAR':
            return 'Menunggu pembayaran jasa desain diverifikasi Finance — desain masih terkunci.';
        case 'MENUNGGU_PENUGASAN':
            return 'Pembayaran sudah diverifikasi — menunggu Kepala Desain menugaskan arsitek.';
        case 'DESAIN':
        case 'WAITING_ACC_DESAIN':
        case 'REVISI_DESAIN':
            return hasLinks
                ? 'Revisi & persetujuan berjalan di grup WhatsApp — tekan "Desain Disetujui Klien" begitu klien setuju.'
                : 'Simpan link grup WhatsApp & link desain, lalu tekan "Desain Disetujui Klien" begitu klien setuju di grup.';
        default:
            return undefined;
    }
}

/** "Disetujui klien — dicatat oleh {nama}, {tanggal jam}" (K19); older rows without a recorder show the date only. */
function ClientApprovalText({ design }: { design: DesignDetail }) {
    if (design.client_approver && design.client_acc_at) {
        return (
            <>
                Disetujui klien — dicatat oleh {design.client_approver.name}, {formatDateTime(design.client_acc_at)}
            </>
        );
    }

    return <>Disetujui klien{design.acc_date ? ` — ${formatDate(design.acc_date)}` : ''}</>;
}

// ── Pre-Sprint-12 designs: the brief form ───────────────────────────────

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

// Sprint 12 #16 — set by the payment flow only, never picked.
const STATUS_OPTIONS: DesignStatus[] = ['MENUNGGU_BAYAR', 'MENUNGGU_PENUGASAN', ...STATUS_GROUPS.flatMap((group) => group.statuses)];

const JENIS_PROJECT_OPTIONS: ProjectType[] = [
    'TOKO', 'CAFE', 'RENOVASI', 'KAMAR_SET', 'KITCHEN_SET',
    'KANTOR', 'ARSITEKTURAL', 'RUANG_TAMU_TV', 'RETAIL_TOKO', 'LAINNYA',
];

const baseSchema = z.object({
    pic_id: z.string().min(1, 'PIC wajib dipilih'),
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

type BriefValues = z.infer<typeof baseSchema>;

/**
 * Mirrors UpdateDesignRequest + DesignService::update(): no post-ACC
 * stage before Client ACC (only a *change* is checked), and each
 * sub-staff member once, never the PIC.
 */
function buildBriefSchema(clientAcc: boolean, currentStatus: DesignStatus) {
    return baseSchema.superRefine((values, ctx) => {
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

function toBriefValues(design: DesignDetail): BriefValues {
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
 * Design brief form + link list + sub-staff (.claude/plan/sprint-02.md
 * Week 4, Sprint 9) — only for a design opened by hand before Sprint 12.
 */
function LegacyBriefCard({
    design,
    canManage,
    designers,
    className,
}: {
    design: DesignDetail;
    canManage: boolean;
    designers: Pick<User, 'id' | 'name' | 'is_active'>[];
    className?: string;
}) {
    const schema = useMemo(() => buildBriefSchema(design.client_acc, design.status), [design.client_acc, design.status]);

    const form = useForm<BriefValues>({
        resolver: zodResolver(schema),
        defaultValues: toBriefValues(design),
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

    // The same design changed on the server: take the new values, keep unsaved edits.
    useEffect(() => {
        form.reset(toBriefValues(design), { keepDirtyValues: true });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [design.id, design.updated_at]);

    function onSubmit(values: BriefValues) {
        const onError = (errors: Record<string, string>) => {
            Object.entries(errors).forEach(([field, message]) => {
                // Server keys design_urls.N map onto the field array's `.value` inputs; staff.N.user_id matches as is.
                const [root, index] = field.split('.');
                const path = root === 'design_urls' && index !== undefined ? `design_urls.${index}.value` : field;
                form.setError(path as FieldPath<BriefValues>, { message });
            });
        };

        router.put(
            route('design.update', { design: design.id }),
            {
                jenis_project: values.jenis_project || null,
                brief_note: values.brief_note || null,
                problem: values.problem || null,
                design_urls: values.design_urls.map((u) => u.value).filter((v) => v.trim() !== ''),
                pic_id: Number(values.pic_id),
                status: values.status,
                target_hari: values.target_hari ? Number(values.target_hari) : null,
                start_date: values.start_date ? format(values.start_date, 'yyyy-MM-dd') : null,
                staff: values.staff.map((member) => ({
                    user_id: Number(member.user_id),
                    role_note: member.role_note.trim() || null,
                })),
            },
            { onError },
        );
    }

    const isStatusLocked = (status: DesignStatus) =>
        !design.client_acc && status !== design.status && ACC_REQUIRED_STATUSES.includes(status);
    const staffError = form.formState.errors.staff?.message;

    return (
        <SectionCard title="Brief Desain" icon={PenLine} className={className}>
            <Form {...form}>
                <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <FormField
                            control={form.control}
                            name="pic_id"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>PIC Arsitek</FormLabel>
                                    <Select value={field.value} onValueChange={field.onChange} disabled={!canManage}>
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
                            {canManage && (
                                <Button type="button" variant="outline" size="sm" onClick={() => appendStaff({ user_id: '', role_note: '' })}>
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
                                                    <Select value={field.value} onValueChange={field.onChange} disabled={!canManage}>
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
                                                                        <SelectItem key={option.id} value={option.id} disabled={isPic || takenElsewhere}>
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
                                        {canManage && (
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

                    <div className="grid gap-4 sm:grid-cols-3">
                        <FormField
                            control={form.control}
                            name="status"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Status</FormLabel>
                                    <Select value={field.value} onValueChange={field.onChange} disabled={!canManage}>
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
                                                            {group.statuses === ACC_REQUIRED_STATUSES && !design.client_acc ? ' — perlu Client ACC' : ''}
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
                                        <Input type="number" min="1" {...field} disabled={!canManage} />
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
                                        <DatePicker value={field.value} onChange={field.onChange} disabled={!canManage} clearable />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                    </div>

                    <p className="text-xs text-daiku-muted">
                        {design.client_acc
                            ? 'Status maju otomatis mengikuti quotation, deal, dan proyek (kecuali saat Hold/Revisi Klien atau Reject Produksi).'
                            : 'Tahap setelah ACC terkunci sampai klien ACC desain lewat tombol "Konfirmasi ACC Klien".'}
                    </p>

                    {design.deadline && (
                        <p className="text-xs text-daiku-muted">
                            Deadline (otomatis): {formatDate(design.deadline)}
                            {design.delay_hari > 0 && <span className="ml-2 font-medium text-error-ink">Delay {design.delay_hari} hari</span>}
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
                                <Button type="button" variant="outline" size="sm" onClick={() => append({ value: '' })}>
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
                                                        <Input {...field} placeholder="https://..." disabled={!canManage} />
                                                    </FormControl>
                                                    <FormMessage />
                                                </FormItem>
                                            )}
                                        />
                                        {canManage && (
                                            <Button type="button" variant="ghost" size="icon-sm" onClick={() => remove(index)}>
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
    );
}
