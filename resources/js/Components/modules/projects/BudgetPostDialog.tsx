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
import type { BudgetPost } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

// Mirrors SaveBudgetPostRequest.
const schema = z.object({
    name: z.string().trim().min(1, 'Nama pos wajib diisi.').max(100, 'Nama pos maksimal 100 karakter.'),
});

type FormValues = z.infer<typeof schema>;

interface BudgetPostDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    projectId: number;
    /** Rename this post; omitted = a new post. */
    post?: Pick<BudgetPost, 'id' | 'name'> | null;
}

/** Sprint 12 #24 — a budget post with a free name (Interior, Listrik, Percetakan, Mural, …). */
export function BudgetPostDialog({ open, onOpenChange, projectId, post }: BudgetPostDialogProps) {
    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: { name: '' } });

    useEffect(() => {
        if (open) form.reset({ name: post?.name ?? '' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, post?.id]);

    function onSubmit(values: FormValues) {
        const options = {
            preserveScroll: true,
            onError: (errors: Record<string, string>) => form.setError('name', { message: errors.name ?? errors.post }),
            onSuccess: () => onOpenChange(false),
        };

        if (post) {
            router.put(route('projects.budget.posts.update', { project: projectId, post: post.id }), values, options);
        } else {
            router.post(route('projects.budget.posts.store', { project: projectId }), values, options);
        }
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>{post ? 'Ubah Nama Pos' : 'Tambah Pos'}</DialogTitle>
                    <DialogDescription>Nama bebas, mis. Interior, Listrik, Percetakan, Mural.</DialogDescription>
                </DialogHeader>
                <Form {...form}>
                    <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                        <FormField
                            control={form.control}
                            name="name"
                            render={({ field }) => (
                                <FormItem>
                                    <FormLabel>Nama Pos</FormLabel>
                                    <FormControl>
                                        <Input {...field} maxLength={100} autoFocus />
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
    );
}
