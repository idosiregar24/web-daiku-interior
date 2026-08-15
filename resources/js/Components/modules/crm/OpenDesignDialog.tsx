import { Button } from '@/Components/ui/button';
import { DatePicker } from '@/Components/shared/DatePicker';
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
import { Input } from '@/Components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import type { Lead, ProjectType, User } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const JENIS_PROJECT_OPTIONS: ProjectType[] = [
    'TOKO', 'CAFE', 'RENOVASI', 'KAMAR_SET', 'KITCHEN_SET',
    'KANTOR', 'ARSITEKTURAL', 'RUANG_TAMU_TV', 'RETAIL_TOKO', 'LAINNYA',
];

const schema = z.object({
    pic_id: z.string().min(1, 'PIC utama wajib dipilih'),
    jenis_project: z.string().optional(),
    target_hari: z.string().optional(),
    start_date: z.date().optional(),
    brief_note: z.string().optional(),
});

type FormValues = z.infer<typeof schema>;

interface OpenDesignDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    lead: Lead | null;
    designers: Pick<User, 'id' | 'name'>[];
}

/**
 * "Buka Desain" trigger (.claude/plan/sprint-02.md Week 4 — the UI half
 * of Week 3's DesignController::store, deliberately deferred then).
 * Only reachable for DEAL_DESAIN leads without a design yet — see
 * CRM/Index.tsx's dropdown. Redirects into Design/Show on success
 * (DesignController::store()).
 */
export function OpenDesignDialog({ open, onOpenChange, lead, designers }: OpenDesignDialogProps) {
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { pic_id: '', jenis_project: '', target_hari: '', start_date: new Date(), brief_note: '' },
    });

    useEffect(() => {
        if (open) {
            form.reset({ pic_id: '', jenis_project: '', target_hari: '', start_date: new Date(), brief_note: '' });
        }
    }, [open, lead]);

    function onSubmit(values: FormValues) {
        if (!lead) return;

        const onError = (errors: Record<string, string>) => {
            Object.entries(errors).forEach(([field, message]) => {
                form.setError(field as keyof FormValues, { message });
            });
        };

        router.post(
            route('crm.leads.design.store', { lead: lead.id }),
            {
                pic_id: Number(values.pic_id),
                jenis_project: values.jenis_project || null,
                target_hari: values.target_hari ? Number(values.target_hari) : null,
                start_date: values.start_date ? format(values.start_date, 'yyyy-MM-dd') : null,
                brief_note: values.brief_note || null,
            },
            { onError, onSuccess: () => onOpenChange(false) },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Buka Desain</DialogTitle>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <p className="text-sm text-daiku-muted">
                            Membuka proyek desain untuk{' '}
                            <span className="font-medium text-daiku-dark">{lead?.client_name}</span>.
                        </p>
                        <FormField
                            control={form.control}
                            name="pic_id"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>PIC Utama</FormLabel>
                                    <Select value={field.value} onValueChange={field.onChange}>
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
                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                control={form.control}
                                name="jenis_project"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Jenis Project</FormLabel>
                                        <Select
                                            value={field.value || 'none'}
                                            onValueChange={(value) => field.onChange(value === 'none' ? '' : value)}
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
                            <FormField
                                control={form.control}
                                name="target_hari"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Target Hari</FormLabel>
                                        <FormControl>
                                            <Input type="number" min="1" {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <FormField
                            control={form.control}
                            name="start_date"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Tanggal Mulai</FormLabel>
                                    <FormControl>
                                        <DatePicker value={field.value} onChange={field.onChange} />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="brief_note"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Catatan Brief (opsional)</FormLabel>
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
                                Buka Desain
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
