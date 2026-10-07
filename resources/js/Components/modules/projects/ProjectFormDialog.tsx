import { Button } from '@/Components/ui/button';
import { DatePicker } from '@/Components/shared/DatePicker';
import { Notice } from '@/Components/shared/Notice';
import { StatusChip } from '@/Components/shared/StatusChip';
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
    FormDescription,
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
import type { Project, ProjectStatus, User } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

// COMPLETED is never a manual choice — QaFormService sets it once every
// milestone passed QA (UpdateProjectRequest / ProjectService::update()).
const STATUS_OPTIONS = ['ACTIVE', 'ON_HOLD', 'CANCELLED'] as const satisfies readonly ProjectStatus[];

type EditableStatus = (typeof STATUS_OPTIONS)[number];

const STATUS_HINT: Record<EditableStatus, string> = {
    ACTIVE: 'Proyek berjalan normal.',
    ON_HOLD: 'Ditahan sementara — penalti form harian dan penanda overdue otomatis dijeda.',
    CANCELLED: 'Dibatalkan permanen — proyek menjadi read-only.',
};

// Mirrors UpdateProjectRequest; the cross-field rules below match its
// `after_or_equal:start_date` and `required_if:status,CANCELLED`.
const schema = z
    .object({
        name: z.string().trim().min(1, 'Nama proyek wajib diisi').max(255, 'Nama proyek maksimal 255 karakter'),
        pm_id: z.string(),
        assistant_pm_id: z.string(),
        start_date: z.date({ message: 'Tanggal mulai proyek wajib diisi' }),
        end_date: z.date().optional(),
        contract_value: z
            .string()
            .min(1, 'Nilai kontrak wajib diisi')
            .refine((v) => !isNaN(Number(v)) && Number(v) >= 0, 'Nilai kontrak tidak valid')
            .refine((v) => Number(v) <= 9999999999999.99, 'Nilai kontrak terlalu besar'),
        status: z.enum(STATUS_OPTIONS),
        note: z.string().max(1000, 'Catatan maksimal 1000 karakter').optional(),
    })
    .refine((values) => !values.end_date || values.end_date >= values.start_date, {
        message: 'Tanggal selesai tidak boleh sebelum tanggal mulai',
        path: ['end_date'],
    })
    .refine((values) => values.status !== 'CANCELLED' || !!values.note?.trim(), {
        message: 'Alasan pembatalan proyek wajib diisi',
        path: ['note'],
    });

type FormValues = z.infer<typeof schema>;

const NO_ASSISTANT = 'none';

const FIELDS: string[] = ['name', 'pm_id', 'assistant_pm_id', 'start_date', 'end_date', 'contract_value', 'status', 'note'];

interface ProjectFormDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    project: Project;
    /** CEO only (PRD §4.4 "PM di-assign oleh CEO") — a PM's form never sends `pm_id`. */
    canChangePm: boolean;
    projectManagers: Pick<User, 'id' | 'name'>[];
    /** Sprint 12 D2 — the CEO or the project's PM picks the Asisten PM. */
    assistantPms: Pick<User, 'id' | 'name'>[];
    /** Any DP/pelunasan on a termin fixes the contract value (ProjectService::update()). */
    hasTerminPayments: boolean;
}

/** "Edit Proyek" (Sprint 9) — CEO any project, PM their own; ProjectService::update() holds the rules. */
export function ProjectFormDialog({
    open,
    onOpenChange,
    project,
    canChangePm,
    projectManagers,
    assistantPms,
    hasTerminPayments,
}: ProjectFormDialogProps) {
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { name: '', pm_id: '', assistant_pm_id: NO_ASSISTANT, start_date: undefined, end_date: undefined, contract_value: '', status: 'ACTIVE', note: '' },
    });

    const status = form.watch('status');
    const statusChanged = status !== project.status;

    useEffect(() => {
        if (!open) return;

        form.reset({
            name: project.name,
            pm_id: String(project.pm_id),
            assistant_pm_id: project.assistant_pm_id ? String(project.assistant_pm_id) : NO_ASSISTANT,
            start_date: project.start_date ? new Date(project.start_date) : undefined,
            end_date: project.end_date ? new Date(project.end_date) : undefined,
            contract_value: String(Number(project.contract_value)),
            status: project.status === 'ON_HOLD' ? 'ON_HOLD' : 'ACTIVE',
            note: '',
        });
    }, [open, project]);

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

        router.put(
            route('projects.update', { project: project.id }),
            {
                name: values.name,
                start_date: format(values.start_date, 'yyyy-MM-dd'),
                end_date: values.end_date ? format(values.end_date, 'yyyy-MM-dd') : null,
                contract_value: Number(values.contract_value),
                status: values.status,
                note: statusChanged && values.note?.trim() ? values.note.trim() : null,
                ...(canChangePm ? { pm_id: Number(values.pm_id) } : {}),
                assistant_pm_id: values.assistant_pm_id === NO_ASSISTANT ? null : Number(values.assistant_pm_id),
            },
            { preserveScroll: true, onError, onSuccess: () => onOpenChange(false) },
        );
    }

    const serverError = form.formState.errors.root?.server?.message;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Ubah Proyek</DialogTitle>
                    <DialogDescription>{project.name}</DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        {serverError && <Notice tone="error">{serverError}</Notice>}
                        <FormField
                            control={form.control}
                            name="name"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Nama Proyek</FormLabel>
                                    <FormControl>
                                        <Input {...field} autoFocus />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="pm_id"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Project Manager</FormLabel>
                                    {canChangePm ? (
                                        <Select value={field.value} onValueChange={field.onChange}>
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue placeholder="Pilih PM" />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                {projectManagers.map((pm) => (
                                                    <SelectItem key={pm.id} value={String(pm.id)}>
                                                        {pm.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    ) : (
                                        <>
                                            <FormControl>
                                                <Input value={project.pm?.name ?? '—'} disabled readOnly />
                                            </FormControl>
                                            <FormDescription>Project Manager hanya bisa diganti oleh CEO.</FormDescription>
                                        </>
                                    )}
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="assistant_pm_id"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Asisten PM</FormLabel>
                                    <Select value={field.value} onValueChange={field.onChange}>
                                        <FormControl>
                                            <SelectTrigger className="w-full">
                                                <SelectValue />
                                            </SelectTrigger>
                                        </FormControl>
                                        <SelectContent>
                                            <SelectItem value={NO_ASSISTANT}>Tanpa asisten</SelectItem>
                                            {project.assistant_pm &&
                                                !assistantPms.some((assistant) => assistant.id === project.assistant_pm?.id) && (
                                                    <SelectItem value={String(project.assistant_pm.id)}>{project.assistant_pm.name} (nonaktif)</SelectItem>
                                                )}
                                            {assistantPms.map((assistant) => (
                                                <SelectItem key={assistant.id} value={String(assistant.id)}>
                                                    {assistant.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <FormDescription>Mengerjakan milestone, task, progress & ACC pengajuan barang — tanpa alokasi dana.</FormDescription>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <FormField
                                control={form.control}
                                name="start_date"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Tanggal Mulai</FormLabel>
                                        <FormControl>
                                            <DatePicker value={field.value} onChange={field.onChange} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="end_date"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Tanggal Selesai</FormLabel>
                                        <div className="flex gap-2">
                                            <FormControl>
                                                <DatePicker value={field.value} onChange={field.onChange} />
                                            </FormControl>
                                            {field.value && (
                                                <Button type="button" variant="ghost" size="sm" className="h-8" onClick={() => field.onChange(undefined)}>
                                                    Kosongkan
                                                </Button>
                                            )}
                                        </div>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <FormField
                            control={form.control}
                            name="contract_value"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Nilai Kontrak (Rp)</FormLabel>
                                    <FormControl>
                                        <Input type="number" step="0.01" min="0" disabled={hasTerminPayments} {...field} />
                                    </FormControl>
                                    <FormDescription>
                                        {hasTerminPayments
                                            ? 'Terkunci — proyek ini sudah menerima pembayaran termin (DP/pelunasan).'
                                            : 'Nominal setiap termin dihitung ulang dari persentasenya bila nilai ini berubah.'}
                                    </FormDescription>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="status"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Status</FormLabel>
                                    <Select value={field.value} onValueChange={field.onChange}>
                                        <FormControl>
                                            <SelectTrigger className="w-full">
                                                <SelectValue />
                                            </SelectTrigger>
                                        </FormControl>
                                        <SelectContent>
                                            {STATUS_OPTIONS.map((option) => (
                                                <SelectItem key={option} value={option}>
                                                    <StatusChip status={option} />
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <FormDescription>{STATUS_HINT[field.value]}</FormDescription>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        {statusChanged && (
                            <FormField
                                control={form.control}
                                name="note"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required={status === 'CANCELLED'}>{status === 'CANCELLED' ? 'Alasan Pembatalan' : 'Catatan Perubahan Status'}</FormLabel>
                                        <FormControl>
                                            <Textarea {...field} rows={2} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        )}
                        {status === 'CANCELLED' && (
                            <Notice tone="warning">
                                Pembatalan bersifat final: proyek tidak bisa diaktifkan kembali dan data proyek tidak bisa
                                diubah lagi.
                            </Notice>
                        )}
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
