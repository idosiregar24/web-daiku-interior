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

// Mirrors App\Http\Requests\Analytics\StoreRevenueTargetRequest.
const schema = z.object({
    month: z.string().regex(/^\d{4}-\d{2}$/, 'Bulan wajib dipilih'),
    target_amount: z
        .string()
        .min(1, 'Nilai target wajib diisi')
        .refine((v) => !isNaN(Number(v)) && Number(v) >= 0, 'Nilai target tidak valid'),
});

type FormValues = z.infer<typeof schema>;

interface RevenueTargetDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** 'YYYY-MM' → existing target, to pre-fill when the CEO re-picks a month. */
    existing: Record<string, number | null>;
}

/** CSV Sprint 6 "Revenue vs target — input target manual per bulan" (CEO only). */
export function RevenueTargetDialog({ open, onOpenChange, existing }: RevenueTargetDialogProps) {
    const currentMonth = new Date().toISOString().slice(0, 7);
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { month: currentMonth, target_amount: '' } });

    useEffect(() => {
        if (open) {
            const value = existing[currentMonth];
            form.reset({ month: currentMonth, target_amount: value ? String(value) : '' });
        }
    }, [open]);

    function onSubmit(values: FormValues) {
        router.post(
            route('analytics.targets.store'),
            { month: values.month, target_amount: Number(values.target_amount) },
            {
                preserveScroll: true,
                onError: (errors) =>
                    Object.entries(errors).forEach(([field, message]) => form.setError(field as keyof FormValues, { message })),
                onSuccess: () => onOpenChange(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-sm">
                <DialogHeader>
                    <DialogTitle>Target Pendapatan</DialogTitle>
                    <DialogDescription>Nilai kontrak yang ditargetkan closing pada bulan tersebut.</DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="month"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Bulan</FormLabel>
                                    <FormControl>
                                        <Input
                                            type="month"
                                            {...field}
                                            onChange={(event) => {
                                                field.onChange(event);
                                                const value = existing[event.target.value];
                                                form.setValue('target_amount', value ? String(value) : '');
                                            }}
                                        />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="target_amount"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Target (Rp)</FormLabel>
                                    <FormControl>
                                        <Input type="number" min="0" step="any" inputMode="decimal" {...field} />
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
