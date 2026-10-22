import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogClose, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Textarea } from '@/Components/ui/textarea';
import { zodResolver } from '@hookform/resolvers/zod';
import { Loader2 } from 'lucide-react';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

/** Mirrors ReviewDailyActivityRequest (note required for PERLU_KLARIFIKASI). */
const NOTE_MAX = 1000;

const schema = z.object({
    note: z.string().trim().min(1, 'Catatan klarifikasi wajib diisi.').max(NOTE_MAX, `Catatan maksimal ${NOTE_MAX} karakter.`),
});

type FormValues = z.infer<typeof schema>;

interface DailyActivityClarifyDialogProps {
    /** Employee name — null = closed. */
    name: string | null;
    dateLabel: string;
    busy: boolean;
    onOpenChange: (open: boolean) => void;
    onSubmit: (note: string, setError: (message: string) => void) => void;
}

/** Sprint 29 Sub 13 — "Perlu klarifikasi": HR's question, sent to the employee as a notification. */
export function DailyActivityClarifyDialog({ name, dateLabel, busy, onOpenChange, onSubmit }: DailyActivityClarifyDialogProps) {
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { note: '' } });

    useEffect(() => {
        if (name) form.reset({ note: '' });
    }, [name]);

    return (
        <Dialog open={name !== null} onOpenChange={(open) => !busy && onOpenChange(open)}>
            <ResponsiveDialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Minta Klarifikasi</DialogTitle>
                    <DialogDescription>
                        Aktivitas {name} tanggal {dateLabel}. Catatan ini dikirim ke karyawan sebagai notifikasi.
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form
                        onSubmit={form.handleSubmit((values) => onSubmit(values.note, (message) => form.setError('note', { message })))}
                        className="space-y-4"
                    >
                        <FormField
                            control={form.control}
                            name="note"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Catatan</FormLabel>
                                    <FormControl>
                                        <Textarea rows={3} maxLength={NOTE_MAX} {...field} placeholder="mis. Mohon jelaskan hasil survey di lokasi klien." />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline" disabled={busy}>
                                    Batal
                                </Button>
                            </DialogClose>
                            <Button type="submit" disabled={busy}>
                                {busy && <Loader2 className="size-4 animate-spin" />}
                                Kirim Klarifikasi
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </ResponsiveDialogContent>
        </Dialog>
    );
}
