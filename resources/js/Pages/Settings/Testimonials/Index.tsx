import { CharCounter } from '@/Components/modules/settings/CharCounter';
import { optionalInt } from '@/Components/modules/settings/companyProfileSchema';
import { PublishChip } from '@/Components/modules/settings/PortfolioFields';
import { DataTable } from '@/Components/shared/DataTable';
import { ModuleTabs } from '@/Components/shared/ModuleTabs';
import { PageHeader } from '@/Components/shared/PageHeader';
import { ResponsiveDialogContent } from '@/Components/shared/ResponsiveDialogContent';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogClose, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Form, FormControl, FormDescription, FormField, FormItem, FormLabel, FormMessage } from '@/Components/ui/form';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Switch } from '@/Components/ui/switch';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import type { TestimonialRow } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { MessageSquareQuote, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

interface TestimonialIndexProps {
    testimonials: TestimonialRow[];
    /** Every portfolio item, published or not — `value` is the id as a string. */
    portfolioOptions: { value: string; label: string }[];
}

const NO_PORTFOLIO = '__none';

// Mirrors App\Http\Requests\CompanyProfile\SaveTestimonialRequest.
const schema = z.object({
    client_label: z.string().trim().min(1, 'Nama klien wajib diisi.').max(120, 'Nama klien maksimal 120 karakter.'),
    quote: z.string().trim().min(1, 'Isi testimoni wajib diisi.').max(600, 'Isi testimoni maksimal 600 karakter.'),
    portfolio_item_id: z.string(),
    is_published: z.boolean(),
    sort_order: optionalInt(0, 100000, {
        integer: 'Urutan harus berupa angka.',
        min: 'Urutan minimal 0.',
        max: 'Urutan maksimal 100000.',
    }),
});

type FormValues = z.infer<typeof schema>;

const EMPTY: FormValues = { client_label: '', quote: '', portfolio_item_id: '', is_published: true, sort_order: '' };

/**
 * Sprint 20 Sub 05 (K2) — ⚙ Pengaturan → Testimoni, CEO + Marketing.
 * Text only (no star ratings): what real clients said, with their
 * permission. Published ones replace the site's placeholder quotes.
 */
export default function TestimonialIndex({ testimonials, portfolioOptions }: TestimonialIndexProps) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<TestimonialRow | null>(null);

    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: EMPTY });
    const [clientLabel, quote] = form.watch(['client_label', 'quote']);

    function openForm(row: TestimonialRow | null) {
        setEditing(row);
        form.reset(
            row
                ? {
                      client_label: row.client_label,
                      quote: row.quote,
                      portfolio_item_id: row.portfolio_item_id?.toString() ?? '',
                      is_published: row.is_published,
                      sort_order: row.sort_order.toString(),
                  }
                : EMPTY,
        );
        setOpen(true);
    }

    function onSubmit(values: FormValues) {
        const payload = {
            client_label: values.client_label.trim(),
            quote: values.quote.trim(),
            portfolio_item_id: values.portfolio_item_id === '' ? null : Number(values.portfolio_item_id),
            is_published: values.is_published,
            // Empty = keep the current position (a new one goes last); the column is never null.
            ...(values.sort_order.trim() === '' ? {} : { sort_order: Number(values.sort_order) }),
        };
        const options = {
            preserveScroll: true,
            onError: (errors: Record<string, string>) =>
                Object.entries(errors).forEach(([field, message]) => form.setError(field as keyof FormValues, { message })),
            onSuccess: () => setOpen(false),
        };

        if (editing) {
            router.put(route('settings.testimonials.update', { testimonial: editing.id }), payload, options);
        } else {
            router.post(route('settings.testimonials.store'), payload, options);
        }
    }

    function destroy(row: TestimonialRow) {
        if (!confirm(`Hapus testimoni dari "${row.client_label}"?`)) return;
        router.delete(route('settings.testimonials.destroy', { testimonial: row.id }), { preserveScroll: true });
    }

    /** Row actions — one source for the grid column and the phone card. */
    function actionsOf(row: TestimonialRow) {
        return (
            <div className="flex justify-end gap-1">
                <Button variant="ghost" size="icon-sm" aria-label={`Edit testimoni ${row.client_label}`} onClick={() => openForm(row)}>
                    <Pencil className="size-4" />
                </Button>
                <Button variant="ghost" size="icon-sm" aria-label={`Hapus testimoni ${row.client_label}`} onClick={() => destroy(row)}>
                    <Trash2 className="size-4 text-error-ink" />
                </Button>
            </div>
        );
    }

    const columns: ColumnDef<TestimonialRow>[] = [
        {
            accessorKey: 'quote',
            header: 'Testimoni',
            cell: ({ row }) => (
                <div className="max-w-xl">
                    <p className="line-clamp-2 text-foreground">“{row.original.quote}”</p>
                    <p className="mt-0.5 text-xs font-medium text-daiku-muted">{row.original.client_label}</p>
                </div>
            ),
        },
        {
            accessorKey: 'portfolio_title',
            header: 'Portofolio',
            cell: ({ row }) => <span className="text-daiku-muted">{row.original.portfolio_title ?? '—'}</span>,
        },
        {
            accessorKey: 'sort_order',
            header: 'Urutan',
            cell: ({ row }) => <span className="tabular-nums">{row.original.sort_order}</span>,
        },
        {
            accessorKey: 'is_published',
            header: 'Status',
            cell: ({ row }) => <PublishChip published={row.original.is_published} />,
        },
        { id: 'actions', header: '', cell: ({ row }) => actionsOf(row.original) },
    ];

    return (
        <AppLayout>
            <Head title="Testimoni" />

            <PageHeader
                title="Testimoni"
                icon={MessageSquareQuote}
                description="Kutipan klien di beranda situs. Hanya yang berstatus Terbit yang tampil; selama belum ada, situs memakai kutipan contoh."
                actions={
                    <Button size="sm" onClick={() => openForm(null)}>
                        <Plus className="size-4" />
                        Tambah Testimoni
                    </Button>
                }
            />

            <ModuleTabs />

            <DataTable
                columns={columns}
                data={testimonials}
                emptyMessage="Belum ada testimoni. Minta izin klien yang puas, lalu tulis kutipannya di sini."
                mobileCard={(row) => (
                    <div className="flex items-start gap-3">
                        <div className="min-w-0 flex-1">
                            <p className="line-clamp-3 text-sm text-foreground">“{row.quote}”</p>
                            <p className="mt-1 text-xs font-medium text-daiku-muted">{row.client_label}</p>
                            <div className="mt-1.5 flex flex-wrap items-center gap-2">
                                <PublishChip published={row.is_published} />
                                {row.portfolio_title && <span className="text-xs text-daiku-muted">{row.portfolio_title}</span>}
                            </div>
                        </div>
                        {actionsOf(row)}
                    </div>
                )}
            />

            <Dialog open={open} onOpenChange={setOpen}>
                <ResponsiveDialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{editing ? 'Edit Testimoni' : 'Tambah Testimoni'}</DialogTitle>
                        <DialogDescription>Tulis sesuai ucapan klien dan pastikan klien setuju kutipannya ditampilkan.</DialogDescription>
                    </DialogHeader>
                    <Form {...form}>
                        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                            <FormField
                                control={form.control}
                                name="client_label"
                                render={({ field }) => (
                                    <FormItem>
                                        <div className="flex items-center justify-between gap-2">
                                            <FormLabel required>Nama Klien</FormLabel>
                                            <CharCounter value={clientLabel} max={120} />
                                        </div>
                                        <FormControl>
                                            <Input {...field} autoFocus placeholder="mis. Ibu R., Kitchen Set — Panam" />
                                        </FormControl>
                                        <FormDescription>Inisial, layanan dan kawasan — tanpa nama lengkap atau alamat.</FormDescription>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <FormField
                                control={form.control}
                                name="quote"
                                render={({ field }) => (
                                    <FormItem>
                                        <div className="flex items-center justify-between gap-2">
                                            <FormLabel required>Isi Testimoni</FormLabel>
                                            <CharCounter value={quote} max={600} />
                                        </div>
                                        <FormControl>
                                            <Textarea {...field} rows={4} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <div className="grid gap-4 sm:grid-cols-3">
                                <FormField
                                    control={form.control}
                                    name="portfolio_item_id"
                                    render={({ field }) => (
                                        <FormItem className="sm:col-span-2">
                                            <FormLabel>Portofolio Terkait</FormLabel>
                                            <Select
                                                value={field.value === '' ? NO_PORTFOLIO : field.value}
                                                onValueChange={(value) => field.onChange(value === NO_PORTFOLIO ? '' : value)}
                                            >
                                                <FormControl>
                                                    <SelectTrigger className="w-full">
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                </FormControl>
                                                <SelectContent>
                                                    <SelectItem value={NO_PORTFOLIO}>Tanpa portofolio</SelectItem>
                                                    {portfolioOptions.map((option) => (
                                                        <SelectItem key={option.value} value={option.value}>
                                                            {option.label}
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
                                    name="sort_order"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Urutan</FormLabel>
                                            <FormControl>
                                                <Input
                                                    {...field}
                                                    inputMode="numeric"
                                                    placeholder={editing ? undefined : "Terakhir"}
                                                    onChange={(event) => field.onChange(event.target.value.replace(/\D/g, ''))}
                                                />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                            </div>
                            <FormField
                                control={form.control}
                                name="is_published"
                                render={({ field }) => (
                                    <FormItem className="flex flex-row items-center justify-between gap-4 rounded-lg border border-daiku-border p-3">
                                        <div className="space-y-1">
                                            <FormLabel className="cursor-pointer">Tampil di situs</FormLabel>
                                            <FormDescription>Matikan untuk menyimpan sebagai draf.</FormDescription>
                                        </div>
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
                </ResponsiveDialogContent>
            </Dialog>
        </AppLayout>
    );
}
