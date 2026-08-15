import { PageHeader } from '@/Components/shared/PageHeader';
import { StatusChip } from '@/Components/shared/StatusChip';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { DatePicker } from '@/Components/shared/DatePicker';
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
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import { ClientAccDialog } from '@/Components/modules/design/ClientAccDialog';
import AppLayout from '@/Layouts/AppLayout';
import type { Design, DesignStatus, ProjectType, User } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { format } from 'date-fns';
import { Plus, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useFieldArray, useForm } from 'react-hook-form';
import { z } from 'zod';

interface DesignShowProps {
    design: Design & { lead: { id: number; client_name: string } };
    canManage: boolean;
    canClientAcc: boolean;
    designers: Pick<User, 'id' | 'name'>[];
}

const STATUS_OPTIONS: DesignStatus[] = [
    'BRIEF', 'DESAIN', 'WAITING_ACC_DESAIN', 'REVISI_DESAIN', 'ACC_DESAIN',
    'GAMBAR_RAB', 'PEMBUATAN_PENAWARAN', 'WAITING_ACC_PENAWARAN', 'PRODUKSI',
    'REJECT_PRODUKSI', 'DONE_PRODUKSI', 'HOLD_CLIENT', 'REVISI_CLIENT',
];

const JENIS_PROJECT_OPTIONS: ProjectType[] = [
    'TOKO', 'CAFE', 'RENOVASI', 'KAMAR_SET', 'KITCHEN_SET',
    'KANTOR', 'ARSITEKTURAL', 'RUANG_TAMU_TV', 'RETAIL_TOKO', 'LAINNYA',
];

const schema = z.object({
    pic_id: z.string().min(1, 'PIC wajib dipilih'),
    jenis_project: z.string().optional(),
    status: z.enum(STATUS_OPTIONS as [DesignStatus, ...DesignStatus[]]),
    target_hari: z.string().optional(),
    start_date: z.date().optional(),
    brief_note: z.string().optional(),
    problem: z.string().optional(),
    design_urls: z.array(z.object({ value: z.string() })),
});

type FormValues = z.infer<typeof schema>;

/**
 * Design brief form + link list + status badge (.claude/plan/sprint-02.md
 * Week 4, Ido task 1) + Client ACC trigger (task 2). Reached from the CRM
 * Lead index's "Buka Desain" action, not its own nav entry — see
 * DesignController's docblock.
 */
export default function DesignShow({ design, canManage, canClientAcc, designers }: DesignShowProps) {
    const [accOpen, setAccOpen] = useState(false);

    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: {
            pic_id: design.pic_id ? String(design.pic_id) : '',
            jenis_project: design.jenis_project ?? '',
            status: design.status,
            target_hari: design.target_hari ? String(design.target_hari) : '',
            start_date: design.start_date ? new Date(design.start_date) : undefined,
            brief_note: design.brief_note ?? '',
            problem: design.problem ?? '',
            design_urls: (design.design_urls ?? []).map((value) => ({ value })),
        },
    });

    const { fields, append, remove } = useFieldArray({ control: form.control, name: 'design_urls' });

    useEffect(() => {
        form.reset({
            pic_id: design.pic_id ? String(design.pic_id) : '',
            jenis_project: design.jenis_project ?? '',
            status: design.status,
            target_hari: design.target_hari ? String(design.target_hari) : '',
            start_date: design.start_date ? new Date(design.start_date) : undefined,
            brief_note: design.brief_note ?? '',
            problem: design.problem ?? '',
            design_urls: (design.design_urls ?? []).map((value) => ({ value })),
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [design.id]);

    function onSubmit(values: FormValues) {
        const onError = (errors: Record<string, string>) => {
            Object.entries(errors).forEach(([field, message]) => {
                form.setError(field.split('.')[0] as keyof FormValues, { message });
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
            },
            { onError },
        );
    }

    const canOpenClientAcc = canClientAcc && !design.client_acc && design.status === 'WAITING_ACC_DESAIN';

    return (
        <AppLayout
            breadcrumbs={[
                { label: 'CRM', routeName: 'crm.leads.index' },
                { label: design.lead.client_name },
            ]}
        >
            <Head title={`Desain — ${design.lead.client_name}`} />

            <PageHeader
                title={`Desain: ${design.lead.client_name}`}
                description="Brief, link desain, dan status pipeline desain."
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

            <div className="grid gap-4 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle className="text-base">Brief Desain</CardTitle>
                    </CardHeader>
                    <CardContent>
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
                                                        {STATUS_OPTIONS.map((option) => (
                                                            <SelectItem key={option} value={option}>
                                                                {option.replace(/_/g, ' ')}
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

                                {design.deadline && (
                                    <p className="text-xs text-daiku-muted">
                                        Deadline (otomatis): {new Date(design.deadline).toLocaleDateString('id-ID')}
                                        {design.delay_hari > 0 && (
                                            <span className="ml-2 font-medium text-error">Delay {design.delay_hari} hari</span>
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
                                                            <Trash2 className="size-4 text-error" />
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
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Ringkasan</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        <div>
                            <p className="text-xs text-daiku-muted">Klien</p>
                            <p className="font-medium text-daiku-dark">{design.lead.client_name}</p>
                        </div>
                        <div>
                            <p className="text-xs text-daiku-muted">Status Client ACC</p>
                            <p className="font-medium text-daiku-dark">
                                {design.client_acc ? 'Sudah ACC' : 'Belum ACC'}
                            </p>
                        </div>
                    </CardContent>
                </Card>
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
