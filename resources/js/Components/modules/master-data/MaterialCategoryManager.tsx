import { EmptyState } from '@/Components/shared/EmptyState';
import { StatusChip } from '@/Components/shared/StatusChip';
import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Switch } from '@/Components/ui/switch';
import type { MaterialCategory } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

// Mirrors App\Http\Requests\MasterData\StoreMaterialCategoryRequest.
const schema = z.object({
    name: z.string().trim().min(1, 'Nama kategori wajib diisi').max(50, 'Maksimal 50 karakter'),
    code_prefix: z
        .string()
        .trim()
        .regex(/^[A-Za-z]{2,5}$/, 'Prefix kode 2–5 huruf, mis. KYP'),
    sort_order: z.string().refine((v) => v === '' || (Number.isInteger(Number(v)) && Number(v) >= 0), 'Urutan harus bilangan bulat'),
    is_active: z.boolean(),
});

type FormValues = z.infer<typeof schema>;

const EMPTY: FormValues = { name: '', code_prefix: '', sort_order: '0', is_active: true };

/**
 * Data Master → Kategori Material (Sprint 11 decision #11). The prefix
 * starts every item code of the category (KYP-0012); changing it only
 * affects items created afterwards. A category in use can only be
 * deactivated.
 */
export function MaterialCategoryManager({ categories }: { categories: MaterialCategory[] }) {
    const [editing, setEditing] = useState<MaterialCategory | null>(null);
    const [open, setOpen] = useState(false);
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: EMPTY });

    function openForm(category: MaterialCategory | null) {
        setEditing(category);
        form.reset(
            category
                ? { name: category.name, code_prefix: category.code_prefix, sort_order: String(category.sort_order), is_active: category.is_active }
                : EMPTY,
        );
        setOpen(true);
    }

    function onSubmit(values: FormValues) {
        const payload = { ...values, code_prefix: values.code_prefix.toUpperCase(), sort_order: values.sort_order === '' ? 0 : Number(values.sort_order) };
        const options = {
            onError: (errors: Record<string, string>) =>
                Object.entries(errors).forEach(([field, message]) => form.setError(field as keyof FormValues, { message })),
            onSuccess: () => setOpen(false),
        };

        if (editing) {
            router.put(route('master-data.material-categories.update', { material_category: editing.id }), payload, options);
        } else {
            router.post(route('master-data.material-categories.store'), payload, options);
        }
    }

    function onDelete(category: MaterialCategory) {
        if (!confirm(`Hapus kategori "${category.name}"?`)) return;
        router.delete(route('master-data.material-categories.destroy', { material_category: category.id }));
    }

    return (
        <div>
            <div className="mb-4 flex items-center justify-between gap-3">
                <p className="text-sm text-daiku-muted">
                    Kategori barang katalog Logistik. Prefix dipakai untuk kode barang otomatis (mis. KYP-0012).
                </p>
                <Button size="sm" onClick={() => openForm(null)}>
                    <Plus className="size-4" />
                    Tambah Kategori
                </Button>
            </div>

            {categories.length === 0 ? (
                <EmptyState className="rounded-xl border border-dashed border-border" title="Belum ada kategori material." />
            ) : (
                <TableCard>
                    <table className="w-full text-sm">
                        <thead className={TABLE_HEAD_CLASS}>
                            <tr>
                                <th className="px-4 py-2.5 text-left font-semibold">Prefix</th>
                                <th className="px-4 py-2.5 text-left font-semibold">Nama</th>
                                <th className="px-4 py-2.5 text-right font-semibold">Barang</th>
                                <th className="px-4 py-2.5 text-left font-semibold">Status</th>
                                <th className="w-20 px-4 py-2.5" />
                            </tr>
                        </thead>
                        <tbody>
                            {categories.map((category) => (
                                <tr key={category.id} className="border-t border-border transition-colors hover:bg-daiku-gray/60">
                                    <td className="px-4 py-3 font-medium">{category.code_prefix}</td>
                                    <td className="px-4 py-3">{category.name}</td>
                                    <td className="px-4 py-3 text-right tabular-nums text-daiku-muted">{category.materials_count ?? 0}</td>
                                    <td className="px-4 py-3">
                                        <StatusChip
                                            status={category.is_active ? 'ACTIVE' : 'INACTIVE'}
                                            label={category.is_active ? 'Aktif' : 'Nonaktif'}
                                            tone={category.is_active ? 'success' : 'neutral'}
                                        />
                                    </td>
                                    <td className="flex justify-end gap-1 px-4 py-3">
                                        <Button variant="ghost" size="icon-sm" aria-label={`Edit ${category.name}`} onClick={() => openForm(category)}>
                                            <Pencil className="size-4" />
                                        </Button>
                                        {!category.materials_count && (
                                            <Button variant="ghost" size="icon-sm" aria-label={`Hapus ${category.name}`} onClick={() => onDelete(category)}>
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
                        <DialogTitle>{editing ? 'Edit Kategori Material' : 'Tambah Kategori Material'}</DialogTitle>
                    </DialogHeader>
                    <Form {...form}>
                        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                            <FormField
                                control={form.control}
                                name="name"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Nama</FormLabel>
                                        <FormControl>
                                            <Input {...field} autoFocus placeholder="mis. Kayu & Panel" />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <div className="grid grid-cols-2 gap-4">
                                <FormField
                                    control={form.control}
                                    name="code_prefix"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Prefix Kode</FormLabel>
                                            <FormControl>
                                                <Input {...field} placeholder="KYP" className="uppercase" />
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
                                name="is_active"
                                render={({ field }) => (
                                    <FormItem className="flex flex-row items-center justify-between rounded-lg border border-daiku-border p-3">
                                        <FormLabel className="cursor-pointer">Kategori aktif (muncul di pilihan)</FormLabel>
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
