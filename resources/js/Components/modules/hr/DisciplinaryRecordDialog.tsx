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
import { Form, FormControl, FormDescription, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import { formatDate } from '@/lib/format';
import type { DisciplinaryType } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { addMonths, endOfDay, format, startOfDay } from 'date-fns';
import { useEffect, useMemo } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { DISCIPLINE_TYPE_LABEL, type DisciplineEmployeeOption, isSpType } from './DisciplineCommon';

const ISSUABLE: DisciplinaryType[] = ['TEGURAN_LISAN', 'SP1', 'SP2', 'SP3', 'CATATAN'];

/** Default validity of an SP (DisciplineService::SP_VALID_MONTHS). */
const SP_VALID_MONTHS = 6;

interface DisciplinaryRecordDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Active employees with their next allowed SP level. */
    employees: DisciplineEmployeeOption[];
    /** Preselects (and locks) the employee — the profile tab. */
    employeeId?: number;
}

/**
 * "Catat Kedisiplinan" — mirrors StoreDisciplinaryRecordRequest. The SP
 * select only enables the next allowed level (decision #12); the server
 * re-checks it on the issue date under a row lock.
 */
export function DisciplinaryRecordDialog({ open, onOpenChange, employees, employeeId }: DisciplinaryRecordDialogProps) {
    const schema = useMemo(
        () =>
            z
                .object({
                    employee_id: z.string().min(1, 'Karyawan wajib dipilih.'),
                    type: z.enum(['TEGURAN_LISAN', 'SP1', 'SP2', 'SP3', 'CATATAN'], { message: 'Jenis catatan wajib dipilih.' }),
                    issued_on: z
                        .date({ message: 'Tanggal terbit wajib diisi.' })
                        .refine((d) => d <= endOfDay(new Date()), 'Tanggal terbit tidak boleh di masa depan.'),
                    valid_until: z.date().optional(),
                    description: z.string().trim().min(1, 'Uraian wajib diisi.').max(2000, 'Uraian maksimal 2000 karakter.'),
                    link: z
                        .string()
                        .max(500, 'Link dokumen maksimal 500 karakter.')
                        .refine((v) => v === '' || /^https?:\/\/\S+$/i.test(v), 'Link dokumen harus berupa URL http/https yang valid.'),
                })
                .refine((v) => !v.valid_until || !isSpType(v.type) || startOfDay(v.valid_until) > startOfDay(v.issued_on), {
                    path: ['valid_until'],
                    message: 'Masa berlaku harus setelah tanggal terbit.',
                })
                .superRefine((v, ctx) => {
                    const employee = employees.find((e) => String(e.id) === v.employee_id);

                    if (!employee || !isSpType(v.type) || employee.next_sp === v.type) return;

                    ctx.addIssue({
                        code: 'custom',
                        path: ['type'],
                        message: employee.next_sp
                            ? `Tingkat SP berikutnya untuk ${employee.name} adalah ${employee.next_sp}.`
                            : `SP3 ${employee.name} masih berlaku — SP3 adalah tingkat terakhir.`,
                    });
                }),
        [employees],
    );

    type FormValues = z.infer<typeof schema>;

    const defaults = (): FormValues => ({
        employee_id: employeeId ? String(employeeId) : '',
        type: 'TEGURAN_LISAN',
        issued_on: new Date(),
        valid_until: undefined,
        description: '',
        link: '',
    });

    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: defaults() });

    useEffect(() => {
        if (open) form.reset(defaults());
    }, [open, employeeId]);

    const selected = employees.find((e) => String(e.id) === form.watch('employee_id'));
    const type = form.watch('type');
    const issuedOn = form.watch('issued_on');

    function onSubmit(values: FormValues) {
        router.post(
            route('hr.discipline.store'),
            {
                employee_id: Number(values.employee_id),
                type: values.type,
                issued_on: format(values.issued_on, 'yyyy-MM-dd'),
                valid_until: isSpType(values.type) && values.valid_until ? format(values.valid_until, 'yyyy-MM-dd') : null,
                description: values.description,
                link: values.link || null,
            },
            {
                preserveScroll: true,
                onError: (errors: Record<string, string>) => {
                    Object.entries(errors).forEach(([field, message]) => {
                        form.setError(field in form.getValues() ? (field as keyof FormValues) : 'type', { message });
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
                    <DialogTitle>Catat Kedisiplinan</DialogTitle>
                    <DialogDescription>
                        Catatan bersifat permanen — kesalahan dikoreksi dengan pembatalan, bukan diedit. Karyawan yang punya akun menerima
                        notifikasi.
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="employee_id"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Karyawan</FormLabel>
                                    <Select value={field.value} onValueChange={field.onChange} disabled={employeeId !== undefined}>
                                        <FormControl>
                                            <SelectTrigger className="w-full">
                                                <SelectValue placeholder="Pilih karyawan" />
                                            </SelectTrigger>
                                        </FormControl>
                                        <SelectContent>
                                            {employees.map((employee) => (
                                                <SelectItem key={employee.id} value={String(employee.id)}>
                                                    {employee.name}
                                                    {employee.position && <span className="text-daiku-muted"> · {employee.position}</span>}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />

                        {selected && (
                            <Notice tone={selected.active_sp ? 'warning' : 'info'}>
                                {selected.active_sp
                                    ? `${selected.active_sp.type} berlaku sampai ${formatDate(selected.active_sp.valid_until)}. `
                                    : 'Tidak ada SP yang berlaku. '}
                                {selected.next_sp
                                    ? `Tingkat berikutnya: ${selected.next_sp}.`
                                    : 'SP3 adalah tingkat terakhir — langkah selanjutnya dilakukan di luar sistem.'}
                            </Notice>
                        )}

                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                control={form.control}
                                name="type"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Jenis</FormLabel>
                                        <Select value={field.value} onValueChange={field.onChange}>
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                {ISSUABLE.map((option) => (
                                                    <SelectItem
                                                        key={option}
                                                        value={option}
                                                        disabled={isSpType(option) && (!selected || selected.next_sp !== option)}
                                                    >
                                                        {DISCIPLINE_TYPE_LABEL[option]}
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
                                name="issued_on"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Tanggal Terbit</FormLabel>
                                        <FormControl>
                                            <DatePicker value={field.value} onChange={field.onChange} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>

                        {isSpType(type) && (
                            <FormField
                                control={form.control}
                                name="valid_until"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Berlaku Sampai (opsional)</FormLabel>
                                        <FormControl>
                                            <DatePicker
                                                value={field.value}
                                                onChange={field.onChange}
                                                placeholder={`Otomatis ${formatDate(format(addMonths(issuedOn ?? new Date(), SP_VALID_MONTHS), 'yyyy-MM-dd'))}`}
                                            />
                                        </FormControl>
                                        <FormDescription>Kosongkan untuk masa berlaku standar {SP_VALID_MONTHS} bulan.</FormDescription>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        )}

                        <FormField
                            control={form.control}
                            name="description"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Uraian</FormLabel>
                                    <FormControl>
                                        <Textarea rows={3} {...field} placeholder="mis. Terlambat masuk kerja 4 kali dalam sebulan" />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="link"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Link Dokumen (opsional)</FormLabel>
                                    <FormControl>
                                        <Input type="url" {...field} placeholder="https://drive.google.com/..." />
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
                                Simpan {DISCIPLINE_TYPE_LABEL[type]}
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
