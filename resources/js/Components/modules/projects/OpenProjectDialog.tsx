import { DatePicker } from '@/Components/shared/DatePicker';
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
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { formatRupiah } from '@/lib/format';
import type { ProjectOpening } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { type ReactNode, useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const NO_ASSISTANT = 'none';

// Mirrors OpenProjectRequest.
const schema = z.object({
    name: z.string().trim().min(1, 'Nama proyek wajib diisi').max(255),
    pm_id: z.string().min(1, 'Project Manager wajib dipilih'),
    assistant_pm_id: z.string(),
    start_date: z.date({ message: 'Tanggal mulai wajib diisi' }),
});

type FormValues = z.infer<typeof schema>;
type Person = { id: number; name: string };

interface OpenProjectDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    opening: ProjectOpening;
    projectManagers: Person[];
    assistantPms: Person[];
    /** Extra footer action — "Nanti" in the pop-up. */
    secondaryAction?: ReactNode;
}

/**
 * Sprint 12 decision #19 — the CEO's "Buka Proyek" for a RAB Proyek the
 * client approved: name, PM, optional Asisten PM (D2), start date. The
 * contract value and termins come from the approved RAB and its payment
 * scheme (ProjectService::openFromQuotation()).
 */
export function OpenProjectDialog({ open, onOpenChange, opening, projectManagers, assistantPms, secondaryAction }: OpenProjectDialogProps) {
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { name: '', pm_id: '', assistant_pm_id: NO_ASSISTANT, start_date: undefined },
    });

    useEffect(() => {
        if (open) {
            form.reset({ name: `Proyek ${opening.lead.client_name}`, pm_id: '', assistant_pm_id: NO_ASSISTANT, start_date: new Date() });
        }
    }, [open, opening.id]);

    function onSubmit(values: FormValues) {
        router.post(
            route('projects.openings.open', { opening: opening.id }),
            {
                name: values.name,
                pm_id: Number(values.pm_id),
                assistant_pm_id: values.assistant_pm_id === NO_ASSISTANT ? null : Number(values.assistant_pm_id),
                start_date: format(values.start_date, 'yyyy-MM-dd'),
            },
            {
                onError: (errors) =>
                    Object.entries(errors).forEach(([field, message]) =>
                        form.setError(field in values ? (field as keyof FormValues) : 'name', { message }),
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
                                                {assistantPms.map((assistant) => (
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
            </DialogContent>
        </Dialog>
    );
}
