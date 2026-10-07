import { DatePicker } from '@/Components/shared/DatePicker';
import { Notice } from '@/Components/shared/Notice';
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
import { Textarea } from '@/Components/ui/textarea';
import { formatRupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { useEffect, useMemo } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { salaryDelta, type SalaryEmployeeOption } from './SalaryCommon';

interface SalaryChangeDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    employees: SalaryEmployeeOption[];
    /** Preselects (and locks) the employee — profile tab, or the review shortcut. */
    employeeId?: number | null;
    /** Set when the request comes from a review's "naik gaji" recommendation. */
    performanceReviewId?: number | null;
}

/**
 * "Ajukan Perubahan Gaji" — mirrors StoreSalaryChangeRequest. HR asks, the
 * CEO decides; the base salary only changes after approval (decision #3).
 */
export function SalaryChangeDialog({ open, onOpenChange, employees, employeeId, performanceReviewId }: SalaryChangeDialogProps) {
    const schema = useMemo(
        () =>
            z
                .object({
                    employee_id: z.string().min(1, 'Karyawan wajib dipilih.'),
                    new_salary: z
                        .string()
                        .min(1, 'Gaji pokok baru wajib diisi.')
                        .refine((v) => !isNaN(Number(v)), 'Gaji pokok baru harus berupa angka.')
                        .refine((v) => Number(v) > 0, 'Gaji pokok baru harus lebih dari 0.'),
                    effective_date: z.date({ message: 'Tanggal berlaku wajib diisi.' }),
                    reason: z.string().trim().min(1, 'Alasan perubahan wajib diisi.').max(2000, 'Alasan maksimal 2000 karakter.'),
                })
                .superRefine((v, ctx) => {
                    const employee = employees.find((e) => String(e.id) === v.employee_id);

                    if (employee?.has_open_request) {
                        ctx.addIssue({
                            code: 'custom',
                            path: ['employee_id'],
                            message: `${employee.name} masih punya pengajuan perubahan gaji yang belum selesai.`,
                        });
                    }

                    if (employee && Math.round(Number(v.new_salary) * 100) === Math.round(Number(employee.base_salary) * 100)) {
                        ctx.addIssue({
                            code: 'custom',
                            path: ['new_salary'],
                            message: `Gaji pokok baru sama dengan gaji pokok saat ini (${formatRupiah(employee.base_salary)}).`,
                        });
                    }
                }),
        [employees],
    );

    type FormValues = z.infer<typeof schema>;

    const defaults = (): FormValues => ({
        employee_id: employeeId ? String(employeeId) : '',
        new_salary: '',
        effective_date: new Date(),
        reason: '',
    });

    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: defaults() });

    useEffect(() => {
        if (open) form.reset(defaults());
    }, [open, employeeId]);

    const selected = employees.find((e) => String(e.id) === form.watch('employee_id'));
    const newSalary = Number(form.watch('new_salary') || 0);
    const current = Number(selected?.base_salary ?? 0);

    function onSubmit(values: FormValues) {
        router.post(
            route('hr.salary-changes.store'),
            {
                employee_id: Number(values.employee_id),
                new_salary: Number(values.new_salary),
                effective_date: format(values.effective_date, 'yyyy-MM-dd'),
                reason: values.reason,
                performance_review_id: performanceReviewId ?? null,
            },
            {
                preserveScroll: true,
                onError: (errors: Record<string, string>) => {
                    Object.entries(errors).forEach(([field, message]) => {
                        form.setError(field in form.getValues() ? (field as keyof FormValues) : 'reason', { message });
                    });
                },
                onSuccess: () => onOpenChange(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>Ajukan Perubahan Gaji</DialogTitle>
                    <DialogDescription>
                        Pengajuan dikirim ke CEO. Gaji pokok baru berlaku setelah disetujui, mulai tanggal berlaku. Satu pengajuan aktif per
                        karyawan.
                    </DialogDescription>
                </DialogHeader>
                {performanceReviewId && <Notice tone="info">Pengajuan ini merujuk ke hasil evaluasi kinerja karyawan.</Notice>}
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="employee_id"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Karyawan</FormLabel>
                                    <Select value={field.value} onValueChange={field.onChange} disabled={Boolean(employeeId)}>
                                        <FormControl>
                                            <SelectTrigger className="w-full">
                                                <SelectValue placeholder="Pilih karyawan" />
                                            </SelectTrigger>
                                        </FormControl>
                                        <SelectContent>
                                            {employees.map((employee) => (
                                                <SelectItem key={employee.id} value={String(employee.id)} disabled={employee.has_open_request}>
                                                    {employee.name}
                                                    {employee.position && <span className="text-daiku-muted"> · {employee.position}</span>}
                                                    {employee.has_open_request && <span className="text-daiku-muted"> (sedang diajukan)</span>}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />

                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                control={form.control}
                                name="new_salary"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Gaji Pokok Baru (Rp)</FormLabel>
                                        <FormControl>
                                            <Input type="number" min="0" step="1000" inputMode="decimal" placeholder="0" {...field} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="effective_date"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Tanggal Berlaku</FormLabel>
                                        <FormControl>
                                            <DatePicker value={field.value} onChange={field.onChange} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>

                        {selected && (
                            <dl className="space-y-1.5 rounded-lg bg-daiku-gray/70 p-3 text-sm ring-1 ring-border ring-inset">
                                <div className="flex justify-between">
                                    <dt className="text-daiku-muted">Gaji pokok saat ini</dt>
                                    <dd className="tabular-nums">{formatRupiah(current)}</dd>
                                </div>
                                <div className="flex justify-between border-t border-border pt-1.5 font-semibold">
                                    <dt>Gaji pokok baru</dt>
                                    <dd className="tabular-nums">
                                        {newSalary > 0 ? formatRupiah(newSalary) : '—'}
                                        {newSalary > 0 && newSalary !== current && (
                                            <span className={cn('ml-2 text-xs', newSalary > current ? 'text-success-ink' : 'text-error-ink')}>
                                                {salaryDelta(current, newSalary)}
                                            </span>
                                        )}
                                    </dd>
                                </div>
                            </dl>
                        )}

                        <FormField
                            control={form.control}
                            name="reason"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel required>Alasan</FormLabel>
                                    <FormControl>
                                        <Textarea rows={3} {...field} placeholder="mis. Hasil evaluasi semester 1 grade A" />
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
                                Kirim ke CEO
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
