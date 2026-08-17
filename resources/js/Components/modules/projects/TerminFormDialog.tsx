import { Button } from '@/Components/ui/button';
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
import type { BankAccount, Milestone } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const schema = z.object({
    milestone_id: z.string().optional(),
    percentage: z
        .string()
        .min(1, 'Persentase wajib diisi')
        .refine((v) => !isNaN(Number(v)) && Number(v) >= 1 && Number(v) <= 100, 'Persentase 1-100'),
    bank_account_id: z.string().optional(),
});

type FormValues = z.infer<typeof schema>;

interface TerminFormDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    projectId: number;
    milestones: Milestone[];
    bankAccounts: Pick<BankAccount, 'id' | 'label'>[];
}

/**
 * "TerminController + TerminService: CRUD + jadwal Sabtu otomatis"
 * (.claude/plan/sprint-04.md Ido Week 8) — scheduled_date is computed
 * server-side (TerminService::getNextSaturday()), not entered here.
 */
export function TerminFormDialog({ open, onOpenChange, projectId, milestones, bankAccounts }: TerminFormDialogProps) {
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { milestone_id: '', percentage: '', bank_account_id: '' },
    });

    useEffect(() => {
        if (open) {
            form.reset({ milestone_id: '', percentage: '', bank_account_id: '' });
        }
    }, [open]);

    function onSubmit(values: FormValues) {
        const onError = (errors: Record<string, string>) => {
            Object.entries(errors).forEach(([field, message]) => {
                form.setError(field as keyof FormValues, { message });
            });
        };

        router.post(
            route('projects.termins.store', { project: projectId }),
            {
                milestone_id: values.milestone_id ? Number(values.milestone_id) : null,
                percentage: Number(values.percentage),
                bank_account_id: values.bank_account_id ? Number(values.bank_account_id) : null,
            },
            { onError, onSuccess: () => onOpenChange(false) },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Jadwalkan Termin</DialogTitle>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <p className="text-sm text-daiku-muted">
                            Jadwal (Sabtu terdekat) dihitung otomatis dari target tanggal milestone yang dipilih.
                        </p>
                        <FormField
                            control={form.control}
                            name="percentage"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Persentase (%)</FormLabel>
                                    <FormControl>
                                        <Input type="number" min="1" max="100" {...field} autoFocus />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="milestone_id"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Milestone (opsional)</FormLabel>
                                    <Select
                                        value={field.value || 'none'}
                                        onValueChange={(value) => field.onChange(value === 'none' ? '' : value)}
                                    >
                                        <FormControl>
                                            <SelectTrigger className="w-full">
                                                <SelectValue placeholder="Pilih milestone" />
                                            </SelectTrigger>
                                        </FormControl>
                                        <SelectContent>
                                            <SelectItem value="none">—</SelectItem>
                                            {milestones.map((milestone) => (
                                                <SelectItem key={milestone.id} value={String(milestone.id)}>
                                                    {milestone.name}
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
                            name="bank_account_id"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Rekening Tujuan (opsional)</FormLabel>
                                    <Select
                                        value={field.value || 'none'}
                                        onValueChange={(value) => field.onChange(value === 'none' ? '' : value)}
                                    >
                                        <FormControl>
                                            <SelectTrigger className="w-full">
                                                <SelectValue placeholder="Pilih rekening" />
                                            </SelectTrigger>
                                        </FormControl>
                                        <SelectContent>
                                            <SelectItem value="none">—</SelectItem>
                                            {bankAccounts.map((account) => (
                                                <SelectItem key={account.id} value={String(account.id)}>
                                                    {account.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
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
