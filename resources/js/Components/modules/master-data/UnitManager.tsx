import { Button } from '@/Components/ui/button';
import { EmptyState } from '@/Components/shared/EmptyState';
import { StatusChip } from '@/Components/shared/StatusChip';
import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/Components/ui/dialog';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Switch } from '@/Components/ui/switch';
import type { UnitRow } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

// Mirrors App\Http\Requests\MasterData\StoreUnitRequest.
const schema = z.object({
    code: z.string().trim().min(1, 'Kode satuan wajib diisi').max(20, 'Kode satuan maksimal 20 karakter'),
    name: z.string().trim().min(1, 'Nama satuan wajib diisi').max(50, 'Nama satuan maksimal 50 karakter'),
    sort_order: z
        .string()
        .refine((v) => v === '' || (Number.isInteger(Number(v)) && Number(v) >= 0), 'Urutan harus bilangan bulat'),
    is_active: z.boolean(),
});

type FormValues = z.infer<typeof schema>;

const EMPTY: FormValues = { code: '', name: '', sort_order: '0', is_active: true };

/**
 * Data Master → Satuan (Sprint 11 Sub 1). A unit already used by a
 * material or RAB line can only be deactivated — it disappears from the
 * dropdowns, existing rows keep showing it.
 */
export function UnitManager({ units }: { units: UnitRow[] }) {
    const [editing, setEditing] = useState<UnitRow | null>(null);
    const [open, setOpen] = useState(false);

    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: EMPTY });

    function openCreate() {
        setEditing(null);
        form.reset(EMPTY);
        setOpen(true);
    }

    function openEdit(unit: UnitRow) {
        setEditing(unit);
        form.reset({ code: unit.code, name: unit.name, sort_order: String(unit.sort_order), is_active: unit.is_active });
        setOpen(true);
    }

    function onSubmit(values: FormValues) {
        const payload = { ...values, sort_order: values.sort_order === '' ? 0 : Number(values.sort_order) };
        const options = {
            onError: (errors: Record<string, string>) =>
                Object.entries(errors).forEach(([field, message]) => form.setError(field as keyof FormValues, { message })),
            onSuccess: () => setOpen(false),
        };

        if (editing) {
            router.put(route('master-data.units.update', { unit: editing.id }), payload, options);
        } else {
            router.post(route('master-data.units.store'), payload, options);
        }
    }

    function onDelete(unit: UnitRow) {
        if (!confirm(`Hapus satuan "${unit.code}"?`)) return;
        router.delete(route('master-data.units.destroy', { unit: unit.id }));
    }

    return (
        <div>
            <div className="mb-4 flex items-center justify-between gap-3">
                <p className="text-sm text-daiku-muted">
                    Satuan barang untuk material & RAB. Satuan yang sudah dipakai hanya bisa dinonaktifkan.
                </p>
                <Button size="sm" onClick={openCreate}>
                    <Plus className="size-4" />
                    Tambah Satuan
                </Button>
            </div>

            {units.length === 0 ? (
                <EmptyState className="rounded-xl border border-dashed border-border" title="Belum ada satuan." />
            ) : (
                <TableCard>
                    <table className="w-full text-sm">
                        <thead className={TABLE_HEAD_CLASS}>
                            <tr>
                                <th className="px-4 py-2.5 text-left font-semibold">Kode</th>
                                <th className="px-4 py-2.5 text-left font-semibold">Nama</th>
                                <th className="px-4 py-2.5 text-right font-semibold">Urutan</th>
                                <th className="px-4 py-2.5 text-left font-semibold">Status</th>
                                <th className="w-20 px-4 py-2.5" />
                            </tr>
                        </thead>
                        <tbody>
                            {units.map((unit) => (
                                <tr key={unit.id} className="border-t border-border transition-colors hover:bg-daiku-gray/60">
                                    <td className="px-4 py-3 font-medium">{unit.code}</td>
                                    <td className="px-4 py-3">{unit.name}</td>
                                    <td className="px-4 py-3 text-right tabular-nums text-daiku-muted">{unit.sort_order}</td>
                                    <td className="px-4 py-3">
                                        <StatusChip
                                            status={unit.is_active ? 'ACTIVE' : 'INACTIVE'}
                                            label={unit.is_active ? 'Aktif' : 'Nonaktif'}
                                            tone={unit.is_active ? 'success' : 'neutral'}
                                        />
                                    </td>
                                    <td className="flex justify-end gap-1 px-4 py-3">
                                        <Button variant="ghost" size="icon-sm" aria-label={`Edit ${unit.code}`} onClick={() => openEdit(unit)}>
                                            <Pencil className="size-4" />
                                        </Button>
                                        {!unit.in_use && (
                                            <Button variant="ghost" size="icon-sm" aria-label={`Hapus ${unit.code}`} onClick={() => onDelete(unit)}>
                                                <Trash2 className="size-4 text-error-ink" />
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </TableCard>
            )}

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{editing ? 'Edit Satuan' : 'Tambah Satuan'}</DialogTitle>
                    </DialogHeader>
                    <Form {...form}>
                        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                            <div className="grid grid-cols-2 gap-4">
                                <FormField
                                    control={form.control}
                                    name="code"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Kode</FormLabel>
                                            <FormControl>
                                                <Input {...field} autoFocus placeholder="mis. lbr" />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="sort_order"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Urutan</FormLabel>
                                            <FormControl>
                                                <Input type="number" min="0" step="1" inputMode="numeric" {...field} />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                            </div>
                            <FormField
                                control={form.control}
                                name="name"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Nama</FormLabel>
                                        <FormControl>
                                            <Input {...field} placeholder="mis. Lembar" />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="is_active"
                                render={({ field }) => (
                                    <FormItem className="flex flex-row items-center justify-between rounded-lg border border-daiku-border p-3">
                                        <FormLabel className="cursor-pointer">Satuan aktif (muncul di pilihan)</FormLabel>
                                        <FormControl>
                                            <Switch checked={field.value} onCheckedChange={field.onChange} />
                                        </FormControl>
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
        </div>
    );
}
