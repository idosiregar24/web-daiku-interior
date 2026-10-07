import { Button } from '@/Components/ui/button';
import { EmptyState } from '@/Components/shared/EmptyState';
import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
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
import type { CityRow } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

// Mirrors CityRequest.
const schema = z.object({
    name: z.string().trim().min(1, 'Nama kota wajib diisi').max(100),
    province: z.string().max(100).optional(),
});

type FormValues = z.infer<typeof schema>;

const EMPTY: FormValues = { name: '', province: '' };

/**
 * Sprint 16 Sub 08 — Master Kota: the list a lead's city is picked from
 * (`CitySelect`). A city used by a lead can only be renamed.
 */
export function CityManager({ cities }: { cities: CityRow[] }) {
    const [editing, setEditing] = useState<CityRow | null>(null);
    const [open, setOpen] = useState(false);

    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: EMPTY });

    function openCreate() {
        setEditing(null);
        form.reset(EMPTY);
        setOpen(true);
    }

    function openEdit(city: CityRow) {
        setEditing(city);
        form.reset({ name: city.name, province: city.province ?? '' });
        setOpen(true);
    }

    function onSubmit(values: FormValues) {
        const onError = (errors: Record<string, string>) => {
            Object.entries(errors).forEach(([field, message]) => form.setError(field as keyof FormValues, { message }));
        };
        const onSuccess = () => setOpen(false);
        const payload = { name: values.name, province: values.province || null };

        if (editing) {
            router.put(route('master-data.cities.update', { city: editing.id }), payload, { onError, onSuccess });
        } else {
            router.post(route('master-data.cities.store'), payload, { onError, onSuccess });
        }
    }

    function onDelete(city: CityRow) {
        if (!confirm(`Hapus kota "${city.name}"?`)) return;
        router.delete(route('master-data.cities.destroy', { city: city.id }));
    }

    return (
        <div>
            <div className="mb-4 flex items-center justify-between gap-4">
                <p className="text-sm text-daiku-muted">
                    Kota yang bisa dipilih di form lead. Kota yang sudah dipakai lead hanya bisa diubah namanya.
                </p>
                <Dialog open={open} onOpenChange={setOpen}>
                    <DialogTrigger asChild>
                        <Button size="sm" onClick={openCreate}>
                            <Plus className="size-4" />
                            Tambah Kota
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>{editing ? 'Ubah Kota' : 'Tambah Kota'}</DialogTitle>
                        </DialogHeader>
                        <Form {...form}>
                            <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                                <FormField
                                    control={form.control}
                                    name="name"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel required>Nama Kota / Kabupaten</FormLabel>
                                            <FormControl>
                                                <Input {...field} autoFocus placeholder="mis. Pekanbaru" />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="province"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Provinsi</FormLabel>
                                            <FormControl>
                                                <Input {...field} placeholder="mis. Riau" />
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
            </div>

            {cities.length === 0 ? (
                <EmptyState className="rounded-xl border border-dashed border-border" title="Belum ada kota." />
            ) : (
                <TableCard>
                    <table className="w-full text-sm">
                        <thead className={TABLE_HEAD_CLASS}>
                            <tr>
                                <th className="px-4 py-2.5 text-left font-semibold">Kota</th>
                                <th className="px-4 py-2.5 text-left font-semibold">Provinsi</th>
                                <th className="px-4 py-2.5 text-right font-semibold">Lead</th>
                                <th className="w-20 px-4 py-2.5" />
                            </tr>
                        </thead>
                        <tbody>
                            {cities.map((city) => (
                                <tr key={city.id} className="border-t border-border transition-colors hover:bg-daiku-gray/60">
                                    <td className="px-4 py-3 font-medium">{city.name}</td>
                                    <td className="px-4 py-3 text-daiku-muted">{city.province ?? '—'}</td>
                                    <td className="px-4 py-3 text-right tabular-nums">{city.leads_count}</td>
                                    <td className="flex justify-end gap-1 px-4 py-3">
                                        <Button variant="ghost" size="icon-sm" onClick={() => openEdit(city)} aria-label={`Ubah ${city.name}`}>
                                            <Pencil className="size-4" />
                                        </Button>
                                        {city.leads_count === 0 && (
                                            <Button variant="ghost" size="icon-sm" onClick={() => onDelete(city)} aria-label={`Hapus ${city.name}`}>
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
        </div>
    );
}
