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
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

export interface ReviewEmployeeOption {
    id: number;
    name: string;
    position: string | null;
    division: string | null;
    join_date: string | null;
}

// Mirrors StorePerformanceReviewRequest / BulkPerformanceReviewRequest.
const schema = z.object({
    employee_id: z.string(),
    year: z.string().min(1, 'Tahun wajib diisi.'),
    semester: z.enum(['1', '2'], { message: 'Semester harus 1 atau 2.' }),
});

type FormValues = z.infer<typeof schema>;

interface ReviewCreateDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** single = one employee; bulk = every active employee without a review for that semester. */
    mode: 'single' | 'bulk';
    employees: ReviewEmployeeOption[];
    /** "employeeId:year:semester" of existing reviews. */
    existing: string[];
    years: number[];
    current: { year: number; semester: 1 | 2 };
    defaults: { year: number; semester: 1 | 2 };
}

function semesterEnd(year: number, semester: number): string {
    return semester === 1 ? `${year}-06-30` : `${year}-12-31`;
}

/**
 * SDM (Sprint 10, §3.4) — HR opens a review for one employee, or for all
 * of them at once ("Buat untuk semua karyawan"). Semesters that haven't
 * started are not offered (the server refuses them too); employees who
 * already have a review for the semester are left out.
 */
export function ReviewCreateDialog({ open, onOpenChange, mode, employees, existing, years, current, defaults }: ReviewCreateDialogProps) {
    const [processing, setProcessing] = useState(false);
    const form = useForm<FormValues>({
        resolver: zodResolver(
            mode === 'single'
                ? schema.extend({ employee_id: z.string().min(1, 'Karyawan wajib dipilih.') })
                : schema,
        ),
        defaultValues: { employee_id: '', year: String(defaults.year), semester: String(defaults.semester) as '1' | '2' },
    });

    useEffect(() => {
        if (open) {
            form.reset({ employee_id: '', year: String(defaults.year), semester: String(defaults.semester) as '1' | '2' });
        }
    }, [open, defaults.year, defaults.semester]);

    const year = Number(form.watch('year'));
    const semester = Number(form.watch('semester'));
    const taken = useMemo(() => new Set(existing), [existing]);

    const available = useMemo(
        () =>
            employees.filter(
                (employee) =>
                    !taken.has(`${employee.id}:${year}:${semester}`) &&
                    (!employee.join_date || employee.join_date <= semesterEnd(year, semester)),
            ),
        [employees, taken, year, semester],
    );

    const isFuture = (y: number, s: number) => y > current.year || (y === current.year && s > current.semester);

    useEffect(() => {
        const selected = form.getValues('employee_id');
        if (selected && !available.some((employee) => String(employee.id) === selected)) {
            form.setValue('employee_id', '');
        }
    }, [available]);

    function onSubmit(values: FormValues) {
        const payload = {
            ...(mode === 'single' ? { employee_id: Number(values.employee_id) } : {}),
            year: Number(values.year),
            semester: Number(values.semester),
        };

        router.post(route(mode === 'single' ? 'hr.reviews.store' : 'hr.reviews.bulk'), payload, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: (errors: Record<string, string>) =>
                Object.entries(errors).forEach(([field, message]) => form.setError(field as keyof FormValues, { message })),
            onSuccess: () => onOpenChange(false),
        });
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{mode === 'single' ? 'Buat Evaluasi' : 'Buat Evaluasi untuk Semua Karyawan'}</DialogTitle>
                    <DialogDescription>
                        Nilai KPI (rata-rata bulan yang sudah ditutup) dan ringkasan kedisiplinan semester diisi otomatis.
                        Semester 1 = Januari–Juni, Semester 2 = Juli–Desember.
                    </DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                control={form.control}
                                name="year"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Tahun</FormLabel>
                                        <Select value={field.value} onValueChange={field.onChange}>
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                {years.map((y) => (
                                                    <SelectItem key={y} value={String(y)}>
                                                        {y}
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
                                name="semester"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Semester</FormLabel>
                                        <Select value={field.value} onValueChange={field.onChange}>
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                <SelectItem value="1" disabled={isFuture(year, 1)}>
                                                    Semester 1 (Jan–Jun)
                                                </SelectItem>
                                                <SelectItem value="2" disabled={isFuture(year, 2)}>
                                                    Semester 2 (Jul–Des)
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>

                        {isFuture(year, semester) && <Notice tone="warning">Semester ini belum dimulai.</Notice>}

                        {mode === 'single' ? (
                            <FormField
                                control={form.control}
                                name="employee_id"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Karyawan</FormLabel>
                                        <Select value={field.value} onValueChange={field.onChange}>
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue placeholder="Pilih karyawan" />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                {available.map((employee) => (
                                                    <SelectItem key={employee.id} value={String(employee.id)}>
                                                        {employee.name}
                                                        {employee.position && <span className="text-daiku-muted"> · {employee.position}</span>}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <FormDescription>
                                            Hanya karyawan aktif yang belum punya evaluasi di semester ini.
                                        </FormDescription>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        ) : (
                            <Notice tone={available.length > 0 ? 'info' : 'success'}>
                                {available.length > 0
                                    ? `${available.length} karyawan aktif belum punya evaluasi semester ini — masing-masing dibuatkan draf. Yang sudah ada dilewati.`
                                    : 'Semua karyawan aktif sudah punya evaluasi semester ini.'}
                            </Notice>
                        )}

                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    Batal
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                disabled={processing || isFuture(year, semester) || (mode === 'bulk' && available.length === 0)}
                            >
                                {mode === 'single' ? 'Buat Evaluasi' : `Buat ${available.length} Evaluasi`}
                            </Button>
                        </DialogFooter>
                    </form>
                </Form>
            </DialogContent>
        </Dialog>
    );
}
