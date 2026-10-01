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
import AppLayout from '@/Layouts/AppLayout';
import type { Design, DesignStaffMember, DesignStatus, ProjectType, User } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { format } from 'date-fns';
import { Info, Palette, PenLine, Plus, Trash2, UserPlus } from 'lucide-react';
import { Fragment, useEffect, useMemo, useState } from 'react';
import { type FieldPath, useFieldArray, useForm } from 'react-hook-form';
import { z } from 'zod';

type DesignDetail = Design & { lead: { id: number; client_name: string }; staff?: DesignStaffMember[] };

interface DesignShowProps {
    design: DesignDetail;
    canManage: boolean;
    canClientAcc: boolean;
    /** Every DESIGNER — `is_active` decides who can still be added as sub-staff. */
    designers: Pick<User, 'id' | 'name' | 'is_active'>[];
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

const STATUS_OPTIONS = STATUS_GROUPS.flatMap((group) => group.statuses);

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
            user_id: z.string().min(1, 'Pilih desainer'),
            role_note: z.string().max(100, 'Peran maksimal 100 karakter'),
        }),
    ),
});

type FormValues = z.infer<typeof baseSchema>;

/**
 * Mirrors UpdateDesignRequest + DesignService::update(): no post-ACC
 * stage before Client ACC (only a *change* is checked), and each
 * sub-staff member once, never the PIC.
 */
function buildSchema(clientAcc: boolean, currentStatus: DesignStatus) {
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
                    message: 'Desainer yang sama dipilih lebih dari sekali.',
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
export default function DesignShow({ design, canManage, canClientAcc, designers }: DesignShowProps) {
    const [accOpen, setAccOpen] = useState(false);

    const schema = useMemo(() => buildSchema(design.client_acc, design.status), [design.client_acc, design.status]);

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

    function onSubmit(values: FormValues) {
        const onError = (errors: Record<string, string>) => {
            Object.entries(errors).forEach(([field, message]) => {
                // Server keys design_urls.N map onto the field array's `.value` inputs; staff.N.user_id matches as is.
                const [root, index] = field.split('.');
                const path = root === 'design_urls' && index !== undefined ? `design_urls.${index}.value` : field;
                form.setError(path as FieldPath<FormValues>, { message });
            });
        };

        router.put(
            route('design.update', { design: design.id }),
            {
                pic_id: Number(values.pic_id),
                jenis_project: values.jenis_project || null,
                status: values.status,
                target_hari: values.target_hari ? Number(values.target_hari) : null,
                start_date: values.start_date ? format(values.start_date, 'yyyy-MM-dd') : null,
                brief_note: values.brief_note || null,
                problem: values.problem || null,
                design_urls: values.design_urls.map((u) => u.value).filter((v) => v.trim() !== ''),
                staff: values.staff.map((member) => ({
                    user_id: Number(member.user_id),
                    role_note: member.role_note.trim() || null,
                })),
            },
            { onError },
        );
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
                    <div className="flex items-center gap-2">
                        <StatusChip status={design.status} />
                        {canOpenClientAcc && (
                            <Button onClick={() => setAccOpen(true)}>Client ACC</Button>
                        )}
                        {design.client_acc && (
                            <span className="text-xs text-daiku-muted">
                                Di-ACC {design.acc_date ? new Date(design.acc_date).toLocaleDateString('id-ID') : ''}
                            </span>
                        )}
                    </div>
                }
            />

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
                                            <FormLabel>PIC Utama</FormLabel>
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
                                    <FormLabel>Sub-Staff</FormLabel>
                                    {canManage && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => appendStaff({ user_id: '', role_note: '' })}
                                        >
                                            <UserPlus className="size-4" />
                                            Tambah Sub-Staff
                                        </Button>
                                    )}
                                </div>
                                {staffFields.length === 0 ? (
                                    <p className="text-sm text-daiku-muted">Belum ada sub-staff — hanya PIC utama.</p>
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
                                                                        <SelectValue placeholder="Pilih desainer" />
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

                            <div className="grid grid-cols-3 gap-4">
                                <FormField
                                    control={form.control}
                                    name="status"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Status</FormLabel>
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
                                                <DatePicker value={field.value} onChange={field.onChange} disabled={!canManage} />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                            </div>

                            <p className="text-xs text-daiku-muted">
                                {design.client_acc
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
                    <div>
                        <p className="text-xs text-daiku-muted">PIC Utama</p>
                        <p className="font-medium text-daiku-dark">{design.pic?.name ?? '—'}</p>
                    </div>
                    <div>
                        <p className="text-xs text-daiku-muted">Sub-Staff</p>
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
                </SectionCard>
            </div>

            <ClientAccDialog
                open={accOpen}
                onOpenChange={setAccOpen}
                design={design}
                clientName={design.lead.client_name}
            />
        </AppLayout>
    );
}
