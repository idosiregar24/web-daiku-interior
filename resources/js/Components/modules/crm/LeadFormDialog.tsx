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
import { Button } from '@/Components/ui/button';
import { DatePicker } from '@/Components/shared/DatePicker';
import { Input } from '@/Components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import type { Lead, LeadSourceOption, User } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { useEffect, useMemo } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const PRIORITY_OPTIONS = ['HOT', 'WARM', 'COLD'] as const;
const CATEGORY_OPTIONS = ['RESIDENTIAL', 'KOMERSIAL', 'DEVELOPER', 'KONTRAKTOR', 'LAINNYA'] as const;

const schema = z.object({
    client_name: z.string().min(1, 'Nama klien wajib diisi'),
    contact: z.string().min(1, 'Kontak wajib diisi'),
    source: z.string().min(1, 'Sumber lead wajib diisi'),
    priority: z.enum(PRIORITY_OPTIONS),
    category: z.string().optional(),
    service: z.string().optional(),
    city: z.string().optional(),
    gender: z.string().optional(),
    order_detail: z.string().optional(),
    assigned_to: z.string().min(1, 'PIC Marketing wajib dipilih'),
    follow_up_date: z.date().optional(),
    notes: z.string().optional(),
});

type FormValues = z.infer<typeof schema>;

const EMPTY_VALUES: FormValues = {
    client_name: '',
    contact: '',
    source: '',
    priority: 'WARM',
    category: '',
    service: '',
    city: '',
    gender: '',
    order_detail: '',
    assigned_to: '',
    follow_up_date: undefined,
    notes: '',
};

interface LeadFormDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Lead being edited, or null for create. */
    editing: Lead | null;
    marketers: Pick<User, 'id' | 'name'>[];
    leadSources: LeadSourceOption[];
}

/**
 * Create/edit modal for CRM Lead (.claude/plan/sprint-02.md Week 3, Ido
 * task 1). `status` is intentionally not a field here — it only ever
 * changes through LeadStatusDialog/ConfirmDealDialog so a PipelineLog
 * entry is never skipped (see LeadService::update()).
 */
export function LeadFormDialog({ open, onOpenChange, editing, marketers, leadSources }: LeadFormDialogProps) {
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: EMPTY_VALUES,
    });

    // Master Data list, plus the lead's current source if it's a legacy
    // value that predates (or was since removed from) that list — so
    // editing an old lead never silently blanks out a valid value.
    const sourceOptions = useMemo(() => {
        const names = leadSources.map((source) => source.name);

        if (editing?.source && !names.includes(editing.source)) {
            return [editing.source, ...names];
        }

        return names;
    }, [leadSources, editing]);

    useEffect(() => {
        if (!open) return;

        if (editing) {
            form.reset({
                client_name: editing.client_name,
                contact: editing.contact,
                source: editing.source,
                priority: editing.priority,
                category: editing.category ?? '',
                service: editing.service ?? '',
                city: editing.city ?? '',
                gender: editing.gender ?? '',
                order_detail: editing.order_detail ?? '',
                assigned_to: String(editing.assigned_to),
                follow_up_date: editing.follow_up_date ? new Date(editing.follow_up_date) : undefined,
                notes: editing.notes ?? '',
            });
        } else {
            form.reset(EMPTY_VALUES);
        }
    }, [open, editing]);

    function onSubmit(values: FormValues) {
        const onError = (errors: Record<string, string>) => {
            Object.entries(errors).forEach(([field, message]) => {
                form.setError(field as keyof FormValues, { message });
            });
        };
        const onSuccess = () => onOpenChange(false);

        const payload = {
            ...values,
            category: values.category || null,
            assigned_to: Number(values.assigned_to),
            follow_up_date: values.follow_up_date ? format(values.follow_up_date, 'yyyy-MM-dd') : null,
        };

        if (editing) {
            router.put(route('crm.leads.update', { lead: editing.id }), payload, { onError, onSuccess });
        } else {
            router.post(route('crm.leads.store'), payload, { onError, onSuccess });
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] max-w-lg overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{editing ? 'Edit Lead' : 'Tambah Lead'}</DialogTitle>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="client_name"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Nama Klien</FormLabel>
                                    <FormControl>
                                        <Input {...field} autoFocus />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="contact"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Kontak (telepon/email)</FormLabel>
                                    <FormControl>
                                        <Input {...field} />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                control={form.control}
                                name="source"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Sumber</FormLabel>
                                        <Select value={field.value} onValueChange={field.onChange}>
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue placeholder="Pilih sumber" />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                {sourceOptions.map((option) => (
                                                    <SelectItem key={option} value={option}>
                                                        {option}
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
                                name="priority"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Prioritas</FormLabel>
                                        <Select value={field.value} onValueChange={field.onChange}>
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                {PRIORITY_OPTIONS.map((option) => (
                                                    <SelectItem key={option} value={option}>
                                                        {option}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                control={form.control}
                                name="category"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Kategori</FormLabel>
                                        <Select
                                            value={field.value || 'none'}
                                            onValueChange={(value) => field.onChange(value === 'none' ? '' : value)}
                                        >
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue placeholder="Pilih kategori" />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                <SelectItem value="none">—</SelectItem>
                                                {CATEGORY_OPTIONS.map((option) => (
                                                    <SelectItem key={option} value={option}>
                                                        {option}
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
                                name="city"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Kota</FormLabel>
                                        <FormControl>
                                            <Input {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <FormField
                            control={form.control}
                            name="service"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Layanan</FormLabel>
                                    <FormControl>
                                        <Input {...field} placeholder="mis. Interior Kantor" />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                control={form.control}
                                name="assigned_to"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>PIC Marketing</FormLabel>
                                        <Select value={field.value} onValueChange={field.onChange}>
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue placeholder="Pilih PIC" />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                {marketers.map((marketer) => (
                                                    <SelectItem key={marketer.id} value={String(marketer.id)}>
                                                        {marketer.name}
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
                                name="follow_up_date"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Tanggal Follow-up</FormLabel>
                                        <FormControl>
                                            <DatePicker value={field.value} onChange={field.onChange} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <FormField
                            control={form.control}
                            name="order_detail"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Detail Pesanan</FormLabel>
                                    <FormControl>
                                        <Textarea {...field} rows={2} />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="notes"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Catatan</FormLabel>
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
                                Simpan
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
