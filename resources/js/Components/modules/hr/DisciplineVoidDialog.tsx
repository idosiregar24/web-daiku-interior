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
import { Textarea } from '@/Components/ui/textarea';
import { formatDate } from '@/lib/format';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { DISCIPLINE_TYPE_LABEL, type DisciplineRecordRow } from './DisciplineCommon';

const schema = z.object({
    reason: z
        .string()
        .trim()
        .min(1, 'Alasan pembatalan wajib diisi.')
        .min(5, 'Alasan pembatalan minimal 5 karakter.')
        .max(1000, 'Alasan pembatalan maksimal 1000 karakter.'),
});

type FormValues = z.infer<typeof schema>;

interface DisciplineVoidDialogProps {
    /** null = closed. */
    record: Pick<DisciplineRecordRow, 'id' | 'type' | 'issued_on'> | null;
    employeeName?: string;
    onOpenChange: (open: boolean) => void;
}

/**
 * "Batalkan" — mirrors VoidDisciplinaryRecordRequest. Adds a PEMBATALAN
 * entry; the original record stays in the history (append-only).
 */
export function DisciplineVoidDialog({ record, employeeName, onOpenChange }: DisciplineVoidDialogProps) {
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { reason: '' } });

    useEffect(() => {
        if (record) form.reset({ reason: '' });
    }, [record]);

    function onSubmit(values: FormValues) {
        if (!record) return;

        router.post(route('hr.discipline.void', { disciplinary_record: record.id }), values, {
            preserveScroll: true,
            onError: (errors: Record<string, string>) => form.setError('reason', { message: Object.values(errors)[0] }),
            onSuccess: () => onOpenChange(false),
        });
    }

    return (
        <Dialog open={record !== null} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Batalkan Catatan</DialogTitle>
                    <DialogDescription>
                        {record && `${DISCIPLINE_TYPE_LABEL[record.type]} tanggal ${formatDate(record.issued_on)}`}
                        {employeeName && ` untuk ${employeeName}`}. Catatan asli tetap tersimpan sebagai riwayat, tetapi tidak dihitung
                        lagi — termasuk untuk tingkat SP.
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="reason"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Alasan Pembatalan</FormLabel>
                                    <FormControl>
                                        <Textarea rows={3} {...field} placeholder="mis. Salah input karyawan" />
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
                            <Button type="submit" variant="destructive" disabled={form.formState.isSubmitting}>
                                Batalkan Catatan
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
