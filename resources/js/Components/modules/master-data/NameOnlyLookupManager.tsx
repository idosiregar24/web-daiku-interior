import { Button } from '@/Components/ui/button';
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
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

interface LookupItem {
    id: number;
    name: string;
}

interface NameOnlyLookupManagerProps {
    title: string;
    description: string;
    addLabel: string;
    items: LookupItem[];
    storeRouteName: string;
    updateRouteName: string;
    destroyRouteName: string;
    routeParam: string;
    emptyMessage: string;
}

const schema = z.object({
    name: z.string().min(1, 'Nama wajib diisi'),
});

type FormValues = z.infer<typeof schema>;

/**
 * Generic add/edit/delete manager for `{id, name}`-shaped Master Data
 * (Lead Sources, Lead Categories) — see
 * .claude/skills/react-component-skill.md. Not reused for Branch/BankAccount
 * since those have more fields.
 */
export function NameOnlyLookupManager({
    title,
    description,
    addLabel,
    items,
    storeRouteName,
    updateRouteName,
    destroyRouteName,
    routeParam,
    emptyMessage,
}: NameOnlyLookupManagerProps) {
    const [editing, setEditing] = useState<LookupItem | null>(null);
    const [open, setOpen] = useState(false);

    const form = useForm<FormValues>({
        resolver: zodResolver(schema),
        defaultValues: { name: '' },
    });

    function openCreate() {
        setEditing(null);
        form.reset({ name: '' });
        setOpen(true);
    }

    function openEdit(item: LookupItem) {
        setEditing(item);
        form.reset({ name: item.name });
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
            router.put(route(updateRouteName, { [routeParam]: editing.id }), values, {
                onError,
                onSuccess,
            });
        } else {
            router.post(route(storeRouteName), values, { onError, onSuccess });
        }
    }

    function onDelete(item: LookupItem) {
        if (!confirm(`Hapus "${item.name}"?`)) return;
        router.delete(route(destroyRouteName, { [routeParam]: item.id }));
    }

    return (
        <div>
            <div className="mb-4 flex items-center justify-between">
                <p className="text-sm text-daiku-muted">{description}</p>
                <Dialog open={open} onOpenChange={setOpen}>
                    <DialogTrigger asChild>
                        <Button size="sm" onClick={openCreate}>
                            <Plus className="size-4" />
                            {addLabel}
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>{editing ? `Edit ${title}` : addLabel}</DialogTitle>
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
                                                <Input {...field} autoFocus />
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

            {items.length === 0 ? (
                <p className="rounded-lg border border-daiku-border py-10 text-center text-sm text-daiku-muted">
                    {emptyMessage}
                </p>
            ) : (
                <div className="overflow-hidden rounded-lg border border-daiku-border">
                    <table className="w-full text-sm">
                        <thead className="bg-daiku-yellow-light">
                            <tr>
                                <th className="p-2 text-left font-medium">Nama</th>
                                <th className="w-20 p-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {items.map((item) => (
                                <tr key={item.id} className="border-t border-daiku-border">
                                    <td className="p-2">{item.name}</td>
                                    <td className="flex justify-end gap-1 p-2">
                                        <Button variant="ghost" size="icon-sm" onClick={() => openEdit(item)}>
                                            <Pencil className="size-4" />
                                        </Button>
                                        <Button variant="ghost" size="icon-sm" onClick={() => onDelete(item)}>
                                            <Trash2 className="size-4 text-error" />
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
