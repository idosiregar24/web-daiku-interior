import { EmptyState } from '@/Components/shared/EmptyState';
import { TableCard, TABLE_HEAD_CLASS } from '@/Components/shared/TableCard';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import type { MaterialSynonym } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { ArrowRight, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

// Mirrors App\Http\Requests\MasterData\StoreMaterialSynonymRequest.
const schema = z
    .object({
        term: z.string().trim().min(1, 'Kata wajib diisi').max(50, 'Maksimal 50 karakter'),
        canonical: z.string().trim().min(1, 'Padanan wajib diisi').max(50, 'Maksimal 50 karakter'),
    })
    .refine((values) => values.term.toLowerCase() !== values.canonical.toLowerCase(), {
        path: ['term'],
        message: 'Kata dan padanannya tidak boleh sama',
    });

type FormValues = z.infer<typeof schema>;

/**
 * Data Master → Sinonim Barang (Sprint 11 §5.5 Lapis 2): "plywood" means
 * "triplek" when the catalog checks for duplicates. Saving rebuilds the
 * catalog's match keys — items that now turn out to be the same are
 * flagged for Logistics' Cek Duplikat page (nothing is deleted).
 */
export function MaterialSynonymManager({ synonyms }: { synonyms: MaterialSynonym[] }) {
    const [editing, setEditing] = useState<MaterialSynonym | null>(null);
    const [open, setOpen] = useState(false);
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { term: '', canonical: '' } });

    function openForm(synonym: MaterialSynonym | null) {
        setEditing(synonym);
        form.reset(synonym ? { term: synonym.term, canonical: synonym.canonical } : { term: '', canonical: '' });
        setOpen(true);
    }

    function onSubmit(values: FormValues) {
        const options = {
            onError: (errors: Record<string, string>) =>
                Object.entries(errors).forEach(([field, message]) => form.setError(field as keyof FormValues, { message })),
            onSuccess: () => setOpen(false),
        };

        if (editing) {
            router.put(route('master-data.material-synonyms.update', { material_synonym: editing.id }), values, options);
        } else {
            router.post(route('master-data.material-synonyms.store'), values, options);
        }
    }

    function onDelete(synonym: MaterialSynonym) {
        if (!confirm(`Hapus sinonim "${synonym.term} → ${synonym.canonical}"?`)) return;
        router.delete(route('master-data.material-synonyms.destroy', { material_synonym: synonym.id }));
    }

    return (
        <div>
            <div className="mb-4 flex items-center justify-between gap-3">
                <p className="text-sm text-daiku-muted">
                    Kata yang berarti barang yang sama, untuk mencegah barang dobel di katalog (mis. plywood → triplek).
                </p>
                <Button size="sm" onClick={() => openForm(null)}>
                    <Plus className="size-4" />
                    Tambah Sinonim
                </Button>
            </div>

            {synonyms.length === 0 ? (
                <EmptyState className="rounded-xl border border-dashed border-border" title="Belum ada sinonim." />
            ) : (
                <TableCard>
                    <table className="w-full text-sm">
                        <thead className={TABLE_HEAD_CLASS}>
                            <tr>
                                <th className="px-4 py-2.5 text-left font-semibold">Kata</th>
                                <th className="w-10 px-2 py-2.5" />
                                <th className="px-4 py-2.5 text-left font-semibold">Dianggap sama dengan</th>
                                <th className="w-20 px-4 py-2.5" />
                            </tr>
                        </thead>
                        <tbody>
                            {synonyms.map((synonym) => (
                                <tr key={synonym.id} className="border-t border-border transition-colors hover:bg-daiku-gray/60">
                                    <td className="px-4 py-3 font-medium">{synonym.term}</td>
                                    <td className="px-2 py-3 text-daiku-muted">
                                        <ArrowRight className="size-4" />
                                    </td>
                                    <td className="px-4 py-3">{synonym.canonical}</td>
                                    <td className="flex justify-end gap-1 px-4 py-3">
                                        <Button variant="ghost" size="icon-sm" aria-label={`Edit ${synonym.term}`} onClick={() => openForm(synonym)}>
                                            <Pencil className="size-4" />
                                        </Button>
                                        <Button variant="ghost" size="icon-sm" aria-label={`Hapus ${synonym.term}`} onClick={() => onDelete(synonym)}>
                                            <Trash2 className="size-4 text-error-ink" />
                                        </Button>
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
                        <DialogTitle>{editing ? 'Edit Sinonim' : 'Tambah Sinonim'}</DialogTitle>
                    </DialogHeader>
                    <Form {...form}>
                        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                            <div className="grid grid-cols-2 gap-4">
                                <FormField
                                    control={form.control}
                                    name="term"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Kata</FormLabel>
                                            <FormControl>
                                                <Input {...field} autoFocus placeholder="plywood" />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="canonical"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Dianggap sama dengan</FormLabel>
                                            <FormControl>
                                                <Input {...field} placeholder="triplek" />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                            </div>
                            <p className="text-xs text-daiku-muted">
                                Setelah disimpan, katalog diperiksa ulang; barang yang ternyata sama ditandai di halaman Cek Duplikat.
                            </p>
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
