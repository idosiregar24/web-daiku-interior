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
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

interface KpiOpenPeriodDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** `YYYY-MM` — the latest month that may be opened. */
    currentMonth: string;
}

/** HR opens a KPI month (mirrors App\Http\Requests\HR\OpenKpiPeriodRequest; no future months). */
export function KpiOpenPeriodDialog({ open, onOpenChange, currentMonth }: KpiOpenPeriodDialogProps) {
    const schema = z.object({
        period: z
            .string()
            .regex(/^\d{4}-(0[1-9]|1[0-2])$/, 'Format periode harus YYYY-MM.')
            .refine((v) => v <= currentMonth, 'Periode KPI bulan yang akan datang belum bisa dibuka.'),
    });
    type FormValues = z.infer<typeof schema>;

    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { period: currentMonth } });

    useEffect(() => {
        if (open) form.reset({ period: currentMonth });
    }, [open]);

    function onSubmit(values: FormValues) {
        router.post(route('hr.kpi.periods.store'), values, {
            preserveScroll: true,
            onError: (errors) => form.setError('period', { message: errors.period }),
            onSuccess: () => onOpenChange(false),
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-sm">
                <DialogHeader>
                    <DialogTitle>Buka Periode KPI</DialogTitle>
                    <DialogDescription>
                        Periode bulan berjalan dibuka otomatis setiap tanggal 1. Bulan yang sudah lewat bisa dibuka di sini.
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="period"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Bulan</FormLabel>
                                    <FormControl>
                                        <Input type="month" max={currentMonth} {...field} />
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
                                Buka Periode
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
