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
import type { Lead, LeadCategoryOption, LeadSourceOption, User } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const PRIORITY_OPTIONS = ['HOT', 'WARM', 'COLD'] as const;

const schema = z.object({
    client_name: z.string().min(1, 'Nama klien wajib diisi'),
    contact: z.string().min(1, 'Kontak wajib diisi'),
    // Mirrors StoreLeadRequest/UpdateLeadRequest: lead_source_id required,
    // lead_category_id nullable — both ids of Data Master rows (Select value = String(id)).
    lead_source_id: z.string().min(1, 'Sumber lead wajib dipilih'),
    priority: z.enum(PRIORITY_OPTIONS),
    lead_category_id: z.string().optional(),
    service: z.string().optional(),
    city: z.string().optional(),
    gender: z.string().optional(),
    order_detail: z.string().optional(),
    assigned_to: z.string().min(1, 'PIC Marketing wajib dipilih'),
    // Create only: the first follow-up's date (saved as FU-1). Later FUs live on the lead's timeline.
    follow_up_date: z.date().optional(),
    first_contacted_at: z.date().optional(),
    address: z.string().max(1000).optional(),
    maps_url: z
        .string()
        .max(500)
        .optional()
        .refine((v) => !v || /^https?:\/\//i.test(v), 'Link Google Maps harus diawali http:// atau https://'),
    notes: z.string().optional(),
});

type FormValues = z.infer<typeof schema>;

const EMPTY_VALUES: FormValues = {
    client_name: '',
    contact: '',
    lead_source_id: '',
    priority: 'WARM',
    lead_category_id: '',
    service: '',
    city: '',
    gender: '',
    order_detail: '',
    assigned_to: '',
    follow_up_date: undefined,
    first_contacted_at: undefined,
    address: '',
    maps_url: '',
    notes: '',
};

interface LeadFormDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Lead being edited, or null for create. */
    editing: Lead | null;
    marketers: Pick<User, 'id' | 'name'>[];
    leadSources: Pick<LeadSourceOption, 'id' | 'name'>[];
    leadCategories: Pick<LeadCategoryOption, 'id' | 'name'>[];
}

/**
 * Create/edit modal for CRM Lead (.claude/plan/sprint-02.md Week 3, Ido
 * task 1). `status` is intentionally not a field here — it only ever
 * changes through LeadStatusDialog/ConfirmDealDialog so a PipelineLog
 * entry is never skipped (see LeadService::update()).
 */
export function LeadFormDialog({ open, onOpenChange, editing, marketers, leadSources, leadCategories }: LeadFormDialogProps) {
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: EMPTY_VALUES,
    });

    useEffect(() => {
        if (!open) return;

        if (editing) {
            form.reset({
                client_name: editing.client_name,
                contact: editing.contact,
                lead_source_id: editing.lead_source_id ? String(editing.lead_source_id) : '',
                priority: editing.priority,
                lead_category_id: editing.lead_category_id ? String(editing.lead_category_id) : '',
                service: editing.service ?? '',
                city: editing.city ?? '',
                gender: editing.gender ?? '',
                order_detail: editing.order_detail ?? '',
                assigned_to: String(editing.assigned_to),
                follow_up_date: undefined,
                first_contacted_at: editing.first_contacted_at ? new Date(editing.first_contacted_at) : undefined,
                address: editing.address ?? '',
                maps_url: editing.maps_url ?? '',
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
            lead_source_id: Number(values.lead_source_id),
            lead_category_id: values.lead_category_id ? Number(values.lead_category_id) : null,
            assigned_to: Number(values.assigned_to),
            first_contacted_at: values.first_contacted_at ? format(values.first_contacted_at, 'yyyy-MM-dd') : null,
            address: values.address || null,
            maps_url: values.maps_url || null,
            // Only sent on create (FU-1); UpdateLeadRequest no longer takes it.
            ...(editing ? { follow_up_date: undefined } : { follow_up_date: values.follow_up_date ? format(values.follow_up_date, 'yyyy-MM-dd') : null }),
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
                                name="lead_source_id"
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
                                                {leadSources.map((source) => (
                                                    <SelectItem key={source.id} value={String(source.id)}>
                                                        {source.name}
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
                                name="lead_category_id"
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
                                                {leadCategories.map((category) => (
                                                    <SelectItem key={category.id} value={String(category.id)}>
                                                        {category.name}
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
                                name="first_contacted_at"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Pertama Dihubungi</FormLabel>
                                        <FormControl>
                                            <DatePicker value={field.value} onChange={field.onChange} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        {!editing && (
                            <FormField
                                control={form.control}
                                name="follow_up_date"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Jadwal Follow-up Pertama (FU-1)</FormLabel>
                                        <FormControl>
                                            <DatePicker value={field.value} onChange={field.onChange} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        )}
                        <FormField
                            control={form.control}
                            name="address"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Alamat</FormLabel>
                                    <FormControl>
                                        <Textarea {...field} rows={2} placeholder="mis. Jl. Tegal Sari No. 12, Pekanbaru" />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="maps_url"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Link Google Maps</FormLabel>
                                    <FormControl>
                                        <Input {...field} placeholder="https://maps.app.goo.gl/…" />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
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
