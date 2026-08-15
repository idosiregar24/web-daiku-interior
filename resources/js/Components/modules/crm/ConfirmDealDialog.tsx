import { Button } from '@/Components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
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
import type { Lead, User } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const schema = z.object({
    name: z.string().min(1, 'Nama proyek wajib diisi'),
    pm_id: z.string().min(1, 'Project Manager wajib dipilih'),
    start_date: z.date({ message: 'Tanggal mulai wajib diisi' }),
    contract_value: z
        .string()
        .min(1, 'Nilai kontrak wajib diisi')
        .refine((v) => !isNaN(Number(v)) && Number(v) >= 0, 'Nilai kontrak tidak valid'),
});

type FormValues = z.infer<typeof schema>;

interface ConfirmDealDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    lead: Lead | null;
    projectManagers: Pick<User, 'id' | 'name'>[];
}

/**
 * Lead → Deal confirmation (.claude/plan/sprint-02.md Week 3, Ido task 4).
 * Only reachable for DEAL_DESAIN leads — closes the pipeline (CLOSING) and
 * creates the execution Project in one backend transaction
 * (LeadService::confirmDeal()).
 */
export function ConfirmDealDialog({ open, onOpenChange, lead, projectManagers }: ConfirmDealDialogProps) {
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { name: '', pm_id: '', start_date: undefined, contract_value: '' },
    });

    useEffect(() => {
        if (open && lead) {
            form.reset({
                name: `Proyek ${lead.client_name}`,
                pm_id: '',
                start_date: new Date(),
                contract_value: '',
            });
        }
    }, [open, lead]);

    function onSubmit(values: FormValues) {
        if (!lead) return;

        const onError = (errors: Record<string, string>) => {
            Object.entries(errors).forEach(([field, message]) => {
                form.setError(field as keyof FormValues, { message });
            });
        };

        const payload = {
            name: values.name,
            pm_id: Number(values.pm_id),
            start_date: format(values.start_date, 'yyyy-MM-dd'),
            contract_value: Number(values.contract_value),
        };

        router.post(route('crm.leads.confirmDeal', { lead: lead.id }), payload, {
            onError,
            onSuccess: () => onOpenChange(false),
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Konfirmasi Deal</DialogTitle>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <p className="text-sm text-daiku-muted">
                            Lead <span className="font-medium text-daiku-dark">{lead?.client_name}</span> akan
                            ditutup sebagai <span className="font-medium text-success">CLOSING</span> dan proyek
                            eksekusi baru akan dibuat.
                        </p>
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
                        <p className="text-sm text-daiku-muted">
                            PM yang dipilih akan bertanggung jawab atas proyek ini hingga selesai.
                        </p>
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    Batal
                                </Button>
                            </DialogClose>
                            <Button type="submit" disabled={form.formState.isSubmitting}>
                                Konfirmasi &amp; Buat Proyek
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
