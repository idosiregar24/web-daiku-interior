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
import { formatDate, formatRupiah } from '@/lib/format';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { parseISO, startOfDay } from 'date-fns';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { salaryDelta, type SalaryChangeRow } from './SalaryCommon';

const rejectSchema = z.object({
    reject_note: z.string().trim().min(1, 'Alasan penolakan wajib diisi.').max(1000, 'Alasan penolakan maksimal 1000 karakter.'),
});

type RejectValues = z.infer<typeof rejectSchema>;

export type SalaryDecision = 'approve' | 'reject';

interface SalaryDecisionDialogProps {
    /** null = closed. */
    change: SalaryChangeRow | null;
    decision: SalaryDecision;
    employeeName?: string;
    onOpenChange: (open: boolean) => void;
}

/**
 * CEO decision on a salary change — approve (confirm) or reject (note
 * required, mirrors RejectSalaryChangeRequest). A decision is final.
 */
export function SalaryDecisionDialog({ change, decision, employeeName, onOpenChange }: SalaryDecisionDialogProps) {
    const [processing, setProcessing] = useState(false);
    const form = useForm<RejectValues>({ resolver: zodResolver(rejectSchema), defaultValues: { reject_note: '' } });
    const name = employeeName ?? change?.employee?.name ?? 'karyawan';

    useEffect(() => {
        if (change) form.reset({ reject_note: '' });
    }, [change]);

    const options = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
        onSuccess: () => onOpenChange(false),
    };

    function approve() {
        if (!change) return;

        router.post(route('hr.salary-changes.approve', { salary_change: change.id }), {}, options);
    }

    function reject(values: RejectValues) {
        if (!change) return;

        router.post(route('hr.salary-changes.reject', { salary_change: change.id }), values, {
            ...options,
            onError: (errors: Record<string, string>) => form.setError('reject_note', { message: Object.values(errors)[0] }),
        });
    }

    const futureDate = change ? startOfDay(parseISO(change.effective_date)) > startOfDay(new Date()) : false;

    const summary = change && (
        <dl className="space-y-1.5 rounded-lg bg-daiku-gray/70 p-3 text-sm ring-1 ring-border ring-inset">
            <div className="flex justify-between">
                <dt className="text-daiku-muted">Gaji pokok lama</dt>
                <dd className="tabular-nums">{formatRupiah(change.old_salary)}</dd>
            </div>
            <div className="flex justify-between font-semibold">
                <dt>Gaji pokok baru</dt>
                <dd className="tabular-nums">
                    {formatRupiah(change.new_salary)} <span className="text-xs font-normal text-daiku-muted">{salaryDelta(change.old_salary, change.new_salary)}</span>
                </dd>
            </div>
            <div className="flex justify-between">
                <dt className="text-daiku-muted">Tanggal berlaku</dt>
                <dd>{formatDate(change.effective_date)}</dd>
            </div>
            <div className="border-t border-border pt-1.5">
                <dt className="text-daiku-muted">Alasan SDM</dt>
                <dd className="mt-0.5 whitespace-pre-line">{change.reason}</dd>
            </div>
        </dl>
    );

    return (
        <Dialog open={change !== null} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{decision === 'approve' ? 'Setujui Perubahan Gaji' : 'Tolak Perubahan Gaji'}</DialogTitle>
                    <DialogDescription>
                        {decision === 'approve'
                            ? futureDate
                                ? `Gaji pokok ${name} akan berubah otomatis pada tanggal berlaku.`
                                : `Gaji pokok ${name} langsung diperbarui setelah disetujui.`
                            : `Pengajuan perubahan gaji ${name} ditolak dan SDM menerima alasannya.`}{' '}
                        Keputusan tidak bisa diubah.
                    </DialogDescription>
                </DialogHeader>
                {summary}
                {decision === 'approve' ? (
                    <DialogFooter>
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                Batal
                            </Button>
                        </DialogClose>
                        <Button onClick={approve} disabled={processing}>
                            Setujui Perubahan Gaji
                        </Button>
                    </DialogFooter>
                ) : (
                    <Form {...form}>
                        <form onSubmit={form.handleSubmit(reject)} className="space-y-4">
                            <FormField
                                control={form.control}
                                name="reject_note"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Alasan Penolakan</FormLabel>
                                        <FormControl>
                                            <Textarea rows={3} {...field} placeholder="mis. Anggaran belum memungkinkan" />
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
                                <Button type="submit" variant="destructive" disabled={processing}>
                                    Tolak Perubahan Gaji
                                </Button>
                            </DialogFooter>
                        </form>
                    </Form>
                )}
            </DialogContent>
        </Dialog>
    );
}
