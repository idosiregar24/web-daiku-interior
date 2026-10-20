import {
    ProjectTeamFields,
    refineTeamLead,
    TEAM_ERROR_FIELD,
    teamDefaults,
    teamFieldsShape,
    teamPayload,
} from '@/Components/modules/projects/ProjectTeamFields';
import { DatePicker } from '@/Components/shared/DatePicker';
import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogClose, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Form, FormControl, FormDescription, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { formatRupiah } from '@/lib/format';
import type { PageProps, ProjectOpening, ProjectOpeningFormOptions } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router, usePage } from '@inertiajs/react';
import { format, startOfDay } from 'date-fns';
import { type ReactNode, useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const NO_ASSISTANT = 'none';

// Mirrors OpenProjectRequest (+ ValidatesProjectPeople for Kepala Tim / Pengawas).
const schema = z
    .object({
        name: z.string().trim().min(1, 'Nama proyek wajib diisi').max(255, 'Nama proyek maksimal 255 karakter'),
        pm_id: z.string().min(1, 'Project Manager wajib dipilih'),
        assistant_pm_id: z.string(),
        ...teamFieldsShape,
        opened_on: z.date({ message: 'Tanggal buka proyek wajib diisi' }),
        start_date: z.date({ message: 'Tanggal mulai wajib diisi' }),
        end_date: z.date().optional(),
    })
    .superRefine((values, ctx) => {
        refineTeamLead(values, ctx);

        if (values.start_date && values.opened_on && startOfDay(values.start_date) < startOfDay(values.opened_on)) {
            ctx.addIssue({ code: 'custom', path: ['start_date'], message: 'Tanggal mulai tidak boleh sebelum tanggal buka proyek' });
        }

        if (values.end_date && values.start_date && startOfDay(values.end_date) < startOfDay(values.start_date)) {
            ctx.addIssue({ code: 'custom', path: ['end_date'], message: 'Tanggal selesai tidak boleh sebelum tanggal mulai' });
        }
    });

type FormValues = z.infer<typeof schema>;

const FIELDS = ['name', 'pm_id', 'assistant_pm_id', 'opened_on', 'start_date', 'end_date'] as const;

interface OpenProjectDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    opening: ProjectOpening;
    options: ProjectOpeningFormOptions;
    /** Extra footer action — "Nanti" in the pop-up. */
    secondaryAction?: ReactNode;
}

/**
 * "Buka Proyek" for a RAB Proyek the client approved — Sprint 23 K13/K14:
 * filled by the PM who approved that RAB (default PM = themself). Name,
 * PM, optional Asisten PM, Kepala Tim (karyawan or a typed name),
 * optional Pengawas (QA/QC), Tanggal Buka, Tanggal Mulai (not before it),
 * optional Tanggal Selesai. Contract value and termins come from the
 * approved RAB and its payment scheme (ProjectService::openFromQuotation()).
 */
export function OpenProjectDialog({ open, onOpenChange, opening, options, secondaryAction }: OpenProjectDialogProps) {
    const me = usePage<PageProps>().props.auth.user.id;
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { name: '', pm_id: '', assistant_pm_id: NO_ASSISTANT, ...teamDefaults(), opened_on: undefined, start_date: undefined, end_date: undefined },
    });

    useEffect(() => {
        if (open) {
            const today = new Date();

            form.reset({
                name: `Proyek ${opening.lead.client_name}`,
                pm_id: options.projectManagers.some((pm) => pm.id === me) ? String(me) : '',
                assistant_pm_id: NO_ASSISTANT,
                ...teamDefaults(),
                opened_on: today,
                start_date: today,
                end_date: undefined,
            });
        }
    }, [open, opening.id]);

    function onSubmit(values: FormValues) {
        router.post(
            route('projects.openings.open', { opening: opening.id }),
            {
                name: values.name,
                pm_id: Number(values.pm_id),
                assistant_pm_id: values.assistant_pm_id === NO_ASSISTANT ? null : Number(values.assistant_pm_id),
                ...teamPayload(values),
                opened_on: format(values.opened_on, 'yyyy-MM-dd'),
                start_date: format(values.start_date, 'yyyy-MM-dd'),
                end_date: values.end_date ? format(values.end_date, 'yyyy-MM-dd') : null,
            },
            {
                onError: (errors) =>
                    Object.entries(errors).forEach(([field, message]) => {
                        const target = TEAM_ERROR_FIELD[field] ?? ((FIELDS as readonly string[]).includes(field) ? (field as keyof FormValues) : 'name');
                        form.setError(target, { message });
                    }),
                onSuccess: () => onOpenChange(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <ResponsiveDialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Buka Proyek</DialogTitle>
                    <DialogDescription>
                        Klien <span className="font-medium text-foreground">{opening.lead.client_name}</span> menyetujui RAB Proyek senilai{' '}
                        {formatRupiah(opening.quotation.total_amount)}. Termin dibuat otomatis dari skema pembayarannya.
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="name"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Nama Proyek</FormLabel>
                                    <FormControl>
                                        <Input {...field} />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <div className="grid gap-4 sm:grid-cols-2">
                            <FormField
                                control={form.control}
                                name="pm_id"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Project Manager</FormLabel>
                                        <Select value={field.value} onValueChange={field.onChange}>
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue placeholder="Pilih PM" />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                {options.projectManagers.map((pm) => (
                                                    <SelectItem key={pm.id} value={String(pm.id)}>
                                                        {pm.name}
                                                        {pm.id === me ? ' (Anda)' : ''}
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
                                                {options.assistantPms.map((assistant) => (
                                                    <SelectItem key={assistant.id} value={String(assistant.id)}>
                                                        {assistant.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <ProjectTeamFields options={options} />
                        <FormField
                            control={form.control}
                            name="opened_on"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Tanggal Buka Proyek</FormLabel>
                                    <FormControl>
                                        <DatePicker value={field.value} onChange={field.onChange} />
                                    </FormControl>
                                    <FormDescription>Hari proyek dibuka di sistem — bisa berbeda dengan tanggal mulai pekerjaan.</FormDescription>
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
                                        <FormControl>
                                            <DatePicker value={field.value} onChange={field.onChange} clearable />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <DialogFooter>
                            {secondaryAction ?? (
                                <DialogClose asChild>
                                    <Button type="button" variant="outline">
                                        Batal
                                    </Button>
                                </DialogClose>
                            )}
                            <Button type="submit" disabled={form.formState.isSubmitting}>
                                Buka Proyek
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </ResponsiveDialogContent>
        </Dialog>
    );
}
