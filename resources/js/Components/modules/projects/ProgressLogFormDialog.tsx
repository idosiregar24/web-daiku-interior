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
import { Textarea } from '@/Components/ui/textarea';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const schema = z.object({
    percentage: z
        .string()
        .min(1, 'Persentase wajib diisi')
        .refine((v) => !isNaN(Number(v)) && Number(v) >= 0 && Number(v) <= 100, 'Persentase 0-100'),
    description: z.string().min(1, 'Deskripsi wajib diisi'),
    // Newline-separated URLs in the textarea — simpler UX than a dynamic
    // field array for what's usually 1-3 links.
    ref_urls: z.string().optional(),
    log_date: z.date({ message: 'Tanggal log wajib diisi' }),
});

type FormValues = z.infer<typeof schema>;

interface ProgressLogFormDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    projectId: number;
}

/**
 * "Progress log form PM: persentase + deskripsi + URL referensi"
 * (.claude/plan/sprint-04.md Jonathan Week 7).
 */
export function ProgressLogFormDialog({ open, onOpenChange, projectId }: ProgressLogFormDialogProps) {
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { percentage: '', description: '', ref_urls: '', log_date: new Date() },
    });

    useEffect(() => {
        if (open) {
            form.reset({ percentage: '', description: '', ref_urls: '', log_date: new Date() });
        }
    }, [open]);

    function onSubmit(values: FormValues) {
        const onError = (errors: Record<string, string>) => {
            Object.entries(errors).forEach(([field, message]) => {
                form.setError(field as keyof FormValues, { message });
            });
        };

        const urls = (values.ref_urls ?? '')
            .split('\n')
            .map((url) => url.trim())
            .filter(Boolean);

        router.post(
            route('progress-logs.store', { project: projectId }),
            {
                percentage: Number(values.percentage),
                description: values.description,
                ref_urls: urls.length > 0 ? urls : null,
                log_date: format(values.log_date, 'yyyy-MM-dd'),
            },
            { onError, onSuccess: () => onOpenChange(false) },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Tambah Progress Log</DialogTitle>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                control={form.control}
                                name="percentage"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Persentase (%)</FormLabel>
                                        <FormControl>
                                            <Input type="number" min="0" max="100" {...field} autoFocus />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="log_date"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Tanggal</FormLabel>
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
                            name="description"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Deskripsi</FormLabel>
                                    <FormControl>
                                        <Textarea {...field} rows={3} placeholder="mis. Pemasangan kusen selesai, mulai finishing cat" />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="ref_urls"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>URL Referensi (opsional, satu per baris)</FormLabel>
                                    <FormControl>
                                        <Textarea {...field} rows={2} placeholder="https://..." />
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
