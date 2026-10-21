import { Button } from '@/Components/ui/button';
import { DatePicker } from '@/Components/shared/DatePicker';
import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import { SavedHint, useSavedHint } from '@/Components/shared/SavedHint';
import {
    Dialog,
    DialogClose,
    DialogDescription,
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
import { Textarea } from '@/Components/ui/textarea';
import { projectVisit, RELOAD } from '@/lib/projectReload';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { Loader2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const schema = z.object({
    description: z.string().min(1, 'Catatan progress wajib diisi'),
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
 * "Catatan Progress" form PM: tanggal + catatan + URL referensi
 * (.claude/plan/sprint-04.md Jonathan Week 7). No percentage since Sprint
 * 24 D11 — the progress is the actual activity of the daily targets.
 */
export function ProgressLogFormDialog({ open, onOpenChange, projectId }: ProgressLogFormDialogProps) {
    const [saving, setSaving] = useState(false);
    // Sprint 29 Sub 01 (K4) — "Simpan & tambah lagi" keeps the dialog open for the next note.
    const again = useRef(false);
    const saved = useSavedHint();
    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { description: '', ref_urls: '', log_date: new Date() },
    });

    useEffect(() => {
        if (open) {
            form.reset({ description: '', ref_urls: '', log_date: new Date() });
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
                description: values.description,
                ref_urls: urls.length > 0 ? urls : null,
                log_date: format(values.log_date, 'yyyy-MM-dd'),
            },
            {
                // Quiet — the note appears in the Catatan Progress list.
                ...projectVisit(RELOAD.progressLogs, { quiet: true }),
                onError,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => {
                    if (!again.current) {
                        onOpenChange(false);
                        return;
                    }

                    // Same day, empty note — back to "Catatan" (shadcn's Textarea doesn't forward refs, so no RHF setFocus).
                    form.reset({ description: '', ref_urls: '', log_date: values.log_date });
                    saved.flash();
                    requestAnimationFrame(() => document.querySelector<HTMLTextAreaElement>('textarea[data-focus-key="progress-description"]')?.focus());
                },
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <ResponsiveDialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Tambah Catatan Progress</DialogTitle>
                    <DialogDescription>
                        Persentase progress dihitung otomatis dari daily target yang selesai — di sini cukup catatannya.
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
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
                        <FormField
                            control={form.control}
                            name="description"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Catatan</FormLabel>
                                    <FormControl>
                                        <Textarea {...field} rows={3} autoFocus data-focus-key="progress-description" placeholder="mis. Pemasangan kusen selesai, mulai finishing cat" />
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
                                    <FormLabel>URL Referensi (satu per baris)</FormLabel>
                                    <FormControl>
                                        <Textarea {...field} rows={2} placeholder="https://..." />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <DialogFooter className="sm:items-center">
                            <SavedHint shown={saved.shown} label="Catatan tersimpan" className="sm:mr-auto" />
                            <DialogClose asChild>
                                <Button type="button" variant="outline" disabled={saving}>
                                    Batal
                                </Button>
                            </DialogClose>
                            <Button type="submit" variant="outline" disabled={saving} onClick={() => (again.current = true)}>
                                Simpan & tambah lagi
                            </Button>
                            <Button type="submit" disabled={saving} onClick={() => (again.current = false)}>
                                {saving && <Loader2 className="size-4 animate-spin" />}
                                Simpan
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </ResponsiveDialogContent>
        </Dialog>
    );
}
