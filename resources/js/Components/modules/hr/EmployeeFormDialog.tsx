import { DatePicker } from '@/Components/shared/DatePicker';
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
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/Components/ui/select';
import { Switch } from '@/Components/ui/switch';
import { Textarea } from '@/Components/ui/textarea';
import { formatRupiah } from '@/lib/format';
import type { Division, Employee, User } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { endOfDay, format } from 'date-fns';
import { useEffect, useMemo } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

/** Radix Select can't hold an empty value — "no linked account". */
const NO_USER = 'none';

// Mirrors HRStoreEmployeeRequest / UpdateEmployeeRequest (base salary only on create —
// afterwards it changes through a salary-change request the CEO approves).
const schema = z.object({
    name: z.string().min(1, 'Nama karyawan wajib diisi.').max(100, 'Nama karyawan maksimal 100 karakter.'),
    position_id: z.string().min(1, 'Jabatan wajib dipilih.'),
    base_salary: z
        .string()
        .min(1, 'Gaji pokok wajib diisi.')
        .refine((v) => !isNaN(Number(v)) && Number(v) > 0, 'Gaji pokok harus lebih dari 0.'),
    user_id: z.string(),
    bank_name: z.string().max(50, 'Nama bank maksimal 50 karakter.').optional(),
    account_no: z.string().max(50, 'Nomor rekening maksimal 50 karakter.').optional(),
    join_date: z
        .date()
        .optional()
        .refine((d) => !d || d <= endOfDay(new Date()), 'Tanggal bergabung tidak boleh di masa depan.'),
    notes: z.string().max(1000, 'Catatan maksimal 1000 karakter.').optional(),
    is_active: z.boolean(),
});

type FormValues = z.infer<typeof schema>;

const EMPTY: FormValues = {
    name: '',
    position_id: '',
    base_salary: '',
    user_id: NO_USER,
    bank_name: '',
    account_no: '',
    join_date: undefined,
    notes: '',
    is_active: true,
};

interface EmployeeFormDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** null = add a new employee. */
    employee: Employee | null;
    /** Every employee — accounts already linked to someone else are hidden from the picker. */
    employees: Employee[];
    linkableUsers: Pick<User, 'id' | 'name'>[];
    /** Divisi → Jabatan master; only active positions of active divisions are offered (plus the current one). */
    structure: Division[];
}

/**
 * SDM (Sprint 10) — HR adds/edits a permanent employee. The job title comes from the
 * Divisi → Jabatan master (decision #10); field-staff accounts are never offered
 * (decision #11). Employees are deactivated, never deleted.
 */
export function EmployeeFormDialog({ open, onOpenChange, employee, employees, linkableUsers, structure }: EmployeeFormDialogProps) {
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: EMPTY });

    const userOptions = useMemo(() => {
        const takenByOthers = new Set(
            employees.filter((other) => other.id !== employee?.id && other.user_id !== null).map((other) => other.user_id),
        );

        return linkableUsers.filter((user) => !takenByOthers.has(user.id));
    }, [employees, employee, linkableUsers]);

    // Active positions of active divisions, plus the one the employee holds today.
    const positionGroups = useMemo(
        () =>
            structure
                .map((division) => ({
                    ...division,
                    positions: (division.positions ?? []).filter(
                        (position) => (division.is_active && position.is_active) || position.id === employee?.position_id,
                    ),
                }))
                .filter((division) => division.positions.length > 0),
        [structure, employee],
    );

    useEffect(() => {
        if (!open) return;

        form.reset(
            employee
                ? {
                      name: employee.name,
                      position_id: String(employee.position_id),
                      base_salary: String(Number(employee.base_salary)),
                      user_id: employee.user_id ? String(employee.user_id) : NO_USER,
                      bank_name: employee.bank_name ?? '',
                      account_no: employee.account_no ?? '',
                      join_date: employee.join_date ? new Date(employee.join_date) : undefined,
                      notes: employee.notes ?? '',
                      is_active: employee.is_active,
                  }
                : EMPTY,
        );
    }, [open, employee]);

    function onSubmit(values: FormValues) {
        const payload = {
            name: values.name,
            position_id: Number(values.position_id),
            ...(employee ? {} : { base_salary: Number(values.base_salary) }),
            user_id: values.user_id === NO_USER ? null : Number(values.user_id),
            bank_name: values.bank_name || null,
            account_no: values.account_no || null,
            join_date: values.join_date ? format(values.join_date, 'yyyy-MM-dd') : null,
            notes: values.notes || null,
            ...(employee ? { is_active: values.is_active } : {}),
        };
        const options = {
            preserveScroll: true,
            onError: (errors: Record<string, string>) =>
                Object.entries(errors).forEach(([field, message]) => form.setError(field as keyof FormValues, { message })),
            onSuccess: () => onOpenChange(false),
        };

        if (employee) {
            router.put(route('hr.employees.update', { employee: employee.id }), payload, options);
        } else {
            router.post(route('hr.employees.store'), payload, options);
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{employee ? 'Edit Karyawan' : 'Tambah Karyawan'}</DialogTitle>
                    <DialogDescription>Karyawan tetap bergaji bulanan. Tukang dibayar lewat menu Upah Tukang.</DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                control={form.control}
                                name="name"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Nama</FormLabel>
                                        <FormControl>
                                            <Input {...field} placeholder="mis. Icha" autoFocus />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="position_id"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel required>Jabatan</FormLabel>
                                        <Select value={field.value} onValueChange={field.onChange}>
                                            <FormControl>
                                                <SelectTrigger className="w-full">
                                                    <SelectValue placeholder="Pilih jabatan" />
                                                </SelectTrigger>
                                            </FormControl>
                                            <SelectContent>
                                                {positionGroups.map((division) => (
                                                    <SelectGroup key={division.id}>
                                                        <SelectLabel>{division.name}</SelectLabel>
                                                        {division.positions.map((position) => (
                                                            <SelectItem key={position.id} value={String(position.id)}>
                                                                {position.name}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectGroup>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            {employee ? (
                                <FormItem>
                                    <FormLabel>Gaji Pokok</FormLabel>
                                    <p className="flex h-9 items-center text-sm font-medium tabular-nums">
                                        {formatRupiah(employee.base_salary)}
                                    </p>
                                    <FormDescription>Diubah lewat pengajuan perubahan gaji (disetujui CEO).</FormDescription>
                                </FormItem>
                            ) : (
                                <FormField
                                    control={form.control}
                                    name="base_salary"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel required>Gaji Pokok (Rp)</FormLabel>
                                            <FormControl>
                                                <Input type="number" min="0" step="any" inputMode="decimal" {...field} />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                            )}
                            <FormField
                                control={form.control}
                                name="join_date"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Tanggal Bergabung</FormLabel>
                                        <FormControl>
                                            <DatePicker value={field.value} onChange={field.onChange} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <div className="grid grid-cols-2 gap-4">
                            <FormField
                                control={form.control}
                                name="bank_name"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Bank</FormLabel>
                                        <FormControl>
                                            <Input {...field} placeholder="mis. BCA" />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="account_no"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>No. Rekening</FormLabel>
                                        <FormControl>
                                            <Input {...field} inputMode="numeric" />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                        </div>
                        <FormField
                            control={form.control}
                            name="user_id"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Akun Sistem</FormLabel>
                                    <Select value={field.value} onValueChange={field.onChange}>
                                        <FormControl>
                                            <SelectTrigger className="w-full">
                                                <SelectValue />
                                            </SelectTrigger>
                                        </FormControl>
                                        <SelectContent>
                                            <SelectItem value={NO_USER}>Tidak ditautkan</SelectItem>
                                            {userOptions.map((user) => (
                                                <SelectItem key={user.id} value={String(user.id)}>
                                                    {user.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <FormDescription>Tidak semua karyawan punya akun — boleh dikosongkan.</FormDescription>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        <FormField
                            control={form.control}
                            name="notes"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Catatan</FormLabel>
                                    <FormControl>
                                        <Textarea {...field} rows={2} />
                                    </FormControl>
                                    <FormMessage />
                                </FormItem>
                            )}
                        />
                        {employee && (
                            <FormField
                                control={form.control}
                                name="is_active"
                                render={({ field }) => (
                                    <FormItem className="flex flex-row items-center justify-between rounded-lg border border-daiku-border p-3">
                                        <div>
                                            <FormLabel className="cursor-pointer">Karyawan aktif</FormLabel>
                                            <FormDescription>Nonaktifkan bila sudah keluar — riwayat gajinya tetap tersimpan.</FormDescription>
                                        </div>
                                        <FormControl>
                                            <Switch checked={field.value} onCheckedChange={field.onChange} />
                                        </FormControl>
                                    </FormItem>
                                )}
                            />
                        )}
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
