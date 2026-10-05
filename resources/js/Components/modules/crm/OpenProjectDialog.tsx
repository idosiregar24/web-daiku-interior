import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { DatePicker } from '@/Components/shared/DatePicker';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import type { Lead, Quotation, User } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

// Mirrors StoreProjectRequest.
const schema = z.object({
    name: z.string().trim().min(1, 'Nama proyek wajib diisi').max(255),
    pm_id: z.string().min(1, 'Project Manager wajib dipilih'),
    start_date: z.date({ message: 'Tanggal mulai wajib diisi' }),
    contract_value: z
        .string()
        .min(1, 'Nilai kontrak wajib diisi')
        .refine((v) => !isNaN(Number(v)) && Number(v) >= 0, 'Nilai kontrak tidak valid'),
});

type FormValues = z.infer<typeof schema>;

interface OpenProjectDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    lead: Pick<Lead, 'id' | 'client_name'>;
    quotation: Pick<Quotation, 'total_amount'>;
    projectManagers: Pick<User, 'id' | 'name'>[];
}

/**
 * Sprint 12 Sub 5 — "Buka Proyek" once the client approved the RAB Proyek
 * on its link (the lead is CLOSING by then). Posts to `projects.store`
 * (ProjectService::createFromLead()). Sub 7 replaces it with the CEO's
 * pop-up, which also copies the payment scheme into termins.
 */
export function OpenProjectDialog({ open, onOpenChange, lead, quotation, projectManagers }: OpenProjectDialogProps) {
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { name: '', pm_id: '', start_date: undefined, contract_value: '' },
    });

    useEffect(() => {
        if (open) {
            form.reset({
                name: `Proyek ${lead.client_name}`,
                pm_id: '',
                start_date: new Date(),
                contract_value: String(Number(quotation.total_amount)),
            });
        }
    }, [open]);

    function onSubmit(values: FormValues) {
        router.post(
            route('projects.store'),
            {
                lead_id: lead.id,
                name: values.name,
                pm_id: Number(values.pm_id),
                start_date: format(values.start_date, 'yyyy-MM-dd'),
                contract_value: Number(values.contract_value),
            },
            {
                onError: (errors) =>
                    Object.entries(errors).forEach(([field, message]) =>
                        form.setError(field === 'lead_id' ? 'name' : (field as keyof FormValues), { message }),
                    ),
                onSuccess: () => onOpenChange(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Buka Proyek</DialogTitle>
                    <DialogDescription>
                        Klien <span className="font-medium text-foreground">{lead.client_name}</span> sudah menyetujui RAB Proyek.
                        Tentukan PM dan tanggal mulai proyek eksekusinya.
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="name"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Nama Proyek</FormLabel>
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
                                    <FormLabel>Project Manager</FormLabel>
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
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <div className="grid grid-cols-2 gap-4">
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
                                name="contract_value"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Nilai Kontrak (Rp)</FormLabel>
                                        <FormControl>
                                            <Input type="number" step="0.01" min="0" {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    Batal
                                </Button>
                            </DialogClose>
                            <Button type="submit" disabled={form.formState.isSubmitting}>
                                Buka Proyek
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
