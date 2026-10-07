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
import { Textarea } from '@/Components/ui/textarea';
import type { Branch } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const schema = z.object({
    name: z.string().min(1, 'Nama cabang wajib diisi'),
    code: z.string().min(1, 'Kode cabang wajib diisi').max(20),
    address: z.string().optional(),
});

type FormValues = z.infer<typeof schema>;

export function BranchManager({ branches }: { branches: Branch[] }) {
    const [editing, setEditing] = useState<Branch | null>(null);
    const [open, setOpen] = useState(false);

    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { name: '', code: '', address: '' },
    });

    function openCreate() {
        setEditing(null);
        form.reset({ name: '', code: '', address: '' });
        setOpen(true);
    }

    function openEdit(branch: Branch) {
        setEditing(branch);
        form.reset({ name: branch.name, code: branch.code, address: branch.address ?? '' });
        setOpen(true);
    }

    function onSubmit(values: FormValues) {
        const onError = (errors: Record<string, string>) => {
            Object.entries(errors).forEach(([field, message]) => {
                form.setError(field as keyof FormValues, { message });
            });
        };
        const onSuccess = () => setOpen(false);

        if (editing) {
            router.put(route('master-data.branches.update', { branch: editing.id }), values, {
                onError,
                onSuccess,
            });
        } else {
            router.post(route('master-data.branches.store'), values, { onError, onSuccess });
        }
    }

    function onDelete(branch: Branch) {
        if (!confirm(`Hapus cabang "${branch.name}"?`)) return;
        router.delete(route('master-data.branches.destroy', { branch: branch.id }));
    }

    return (
        <div>
            <div className="mb-4 flex items-center justify-between">
                <p className="text-sm text-daiku-muted">
                    Daftar cabang perusahaan — dasar untuk ekspansi multi-cabang (PRD §11.3).
                </p>
                <Dialog open={open} onOpenChange={setOpen}>
                    <DialogTrigger asChild>
                        <Button size="sm" onClick={openCreate}>
                            <Plus className="size-4" />
                            Tambah Cabang
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>{editing ? 'Edit Cabang' : 'Tambah Cabang'}</DialogTitle>
                        </DialogHeader>
                        <Form {...form}>
                            <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                                <FormField
                                    control={form.control}
                                    name="name"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel required>Nama Cabang</FormLabel>
                                            <FormControl>
                                                <Input {...field} autoFocus />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="code"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel required>Kode</FormLabel>
                                            <FormControl>
                                                <Input {...field} placeholder="mis. JKT01" />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="address"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Alamat</FormLabel>
                                            <FormControl>
                                                <Textarea {...field} />
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

            {branches.length === 0 ? (
                <EmptyState className="rounded-xl border border-dashed border-border" title="Belum ada cabang." />
            ) : (
                <TableCard>
                    <table className="w-full text-sm">
                        <thead className={TABLE_HEAD_CLASS}>
                            <tr>
                                <th className="px-4 py-2.5 text-left font-semibold">Kode</th>
                                <th className="px-4 py-2.5 text-left font-semibold">Nama</th>
                                <th className="px-4 py-2.5 text-left font-semibold">Alamat</th>
                                <th className="w-20 px-4 py-2.5" />
                            </tr>
                        </thead>
                        <tbody>
                            {branches.map((branch) => (
                                <tr key={branch.id} className="border-t border-border transition-colors hover:bg-daiku-gray/60">
                                    <td className="px-4 py-3 font-medium">{branch.code}</td>
                                    <td className="px-4 py-3">{branch.name}</td>
                                    <td className="px-4 py-3 text-daiku-muted">{branch.address ?? '—'}</td>
                                    <td className="flex justify-end gap-1 px-4 py-3">
                                        <Button variant="ghost" size="icon-sm" onClick={() => openEdit(branch)}>
                                            <Pencil className="size-4" />
                                        </Button>
                                        <Button variant="ghost" size="icon-sm" onClick={() => onDelete(branch)}>
                                            <Trash2 className="size-4 text-error-ink" />
                                        </Button>
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
