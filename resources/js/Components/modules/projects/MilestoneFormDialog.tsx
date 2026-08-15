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
import type { Milestone } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const schema = z.object({
    name: z.string().min(1, 'Nama milestone wajib diisi'),
    target_date: z.date({ message: 'Target tanggal wajib diisi' }),
});

type FormValues = z.infer<typeof schema>;

interface MilestoneFormDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    projectId: number;
    /** Milestone being edited, or null for create. */
    editing: Milestone | null;
}

/** Add/edit modal for a Project's Milestone — PRD §7.1 "Milestone" row (PM CRUD). */
export function MilestoneFormDialog({ open, onOpenChange, projectId, editing }: MilestoneFormDialogProps) {
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { name: '', target_date: undefined },
    });

    useEffect(() => {
        if (!open) return;

        form.reset({
            name: editing?.name ?? '',
            target_date: editing ? new Date(editing.target_date) : undefined,
        });
    }, [open, editing]);

    function onSubmit(values: FormValues) {
        const onError = (errors: Record<string, string>) => {
            Object.entries(errors).forEach(([field, message]) => {
                form.setError(field as keyof FormValues, { message });
            });
        };
        const onSuccess = () => onOpenChange(false);
        const payload = { name: values.name, target_date: format(values.target_date, 'yyyy-MM-dd') };

        if (editing) {
            router.put(route('milestones.update', { milestone: editing.id }), payload, { onError, onSuccess });
        } else {
            router.post(route('milestones.store', { project: projectId }), payload, { onError, onSuccess });
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{editing ? 'Edit Milestone' : 'Tambah Milestone'}</DialogTitle>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="name"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Nama Milestone</FormLabel>
                                    <FormControl>
                                        <Input {...field} autoFocus />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="target_date"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Target Tanggal</FormLabel>
                                    <FormControl>
                                        <DatePicker value={field.value} onChange={field.onChange} />
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
