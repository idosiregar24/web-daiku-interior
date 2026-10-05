import { DataTable } from '@/Components/shared/DataTable';
import { ModuleTabs } from '@/Components/shared/ModuleTabs';
import { PageHeader } from '@/Components/shared/PageHeader';
import { SearchInput } from '@/Components/shared/SearchInput';
import { StatusChip } from '@/Components/shared/StatusChip';
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
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Switch } from '@/Components/ui/switch';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import type { PaginatedData, Vendor, VendorType } from '@/types';
import { zodResolver } from '@hookform/resolvers/zod';
import { Head, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { Pencil, Plus, Store, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

type VendorRow = Omit<Vendor, 'created_at' | 'updated_at'> & { in_use: boolean };

interface VendorIndexProps {
    vendors: PaginatedData<VendorRow>;
    filters: { search?: string; type?: string; status?: string };
}

const TYPE_LABEL: Record<VendorType, string> = {
    MATERIAL: 'Material',
    JASA: 'Jasa',
};

const optional = (max: number, label: string) => z.string().max(max, `${label} maksimal ${max} karakter`).optional();

// Mirrors App\Http\Requests\MasterData\StoreVendorRequest.
const schema = z.object({
    name: z.string().trim().min(1, 'Nama vendor wajib diisi').max(100, 'Nama vendor maksimal 100 karakter'),
    type: z.enum(['MATERIAL', 'JASA']),
    contact: optional(100, 'Kontak'),
    address: optional(1000, 'Alamat'),
    bank_name: optional(50, 'Nama bank'),
    bank_account_number: optional(50, 'Nomor rekening'),
    account_holder: optional(100, 'Nama pemilik rekening'),
    is_active: z.boolean(),
});

type FormValues = z.infer<typeof schema>;

const EMPTY: FormValues = {
    name: '',
    type: 'MATERIAL',
    contact: '',
    address: '',
    bank_name: '',
    bank_account_number: '',
    account_holder: '',
    is_active: true,
};

/**
 * Data Master → Vendor (Sprint 11 Sub 2) — CEO + SUPERADMIN. Other roles
 * pick vendors through VendorSelect (supplier debts, material purchases).
 * A vendor that's already referenced can only be deactivated.
 */
export default function VendorIndex({ vendors, filters }: VendorIndexProps) {
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<VendorRow | null>(null);
    const [search, setSearch] = useState(filters.search ?? '');

    const form = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: EMPTY });

    function applyFilter(next: Partial<VendorIndexProps['filters']>) {
        router.get(route('master-data.vendors.index'), { ...filters, ...next }, { preserveState: true, preserveScroll: true, replace: true });
    }

    useEffect(() => {
        if (search === (filters.search ?? '')) {
            return;
        }

        const timeout = setTimeout(() => applyFilter({ search: search || undefined }), 350);

        return () => clearTimeout(timeout);
    }, [search]);

    function openForm(vendor: VendorRow | null) {
        setEditing(vendor);
        form.reset(
            vendor
                ? {
                      name: vendor.name,
                      type: vendor.type,
                      contact: vendor.contact ?? '',
                      address: vendor.address ?? '',
                      bank_name: vendor.bank_name ?? '',
                      bank_account_number: vendor.bank_account_number ?? '',
                      account_holder: vendor.account_holder ?? '',
                      is_active: vendor.is_active,
                  }
                : EMPTY,
        );
        setOpen(true);
    }

    function onSubmit(values: FormValues) {
        const options = {
            preserveScroll: true,
            onError: (errors: Record<string, string>) =>
                Object.entries(errors).forEach(([field, message]) => form.setError(field as keyof FormValues, { message })),
            onSuccess: () => setOpen(false),
        };

        if (editing) {
            router.put(route('master-data.vendors.update', { vendor: editing.id }), values, options);
        } else {
            router.post(route('master-data.vendors.store'), values, options);
        }
    }

    function destroy(vendor: VendorRow) {
        if (!confirm(`Hapus vendor "${vendor.name}"?`)) return;
        router.delete(route('master-data.vendors.destroy', { vendor: vendor.id }), { preserveScroll: true });
    }

    const columns: ColumnDef<VendorRow>[] = [
        {
            accessorKey: 'name',
            header: 'Vendor',
            cell: ({ row }) => (
                <div>
                    <p className="font-medium text-daiku-dark">{row.original.name}</p>
                    <p className="text-xs text-daiku-muted">{TYPE_LABEL[row.original.type]}</p>
                </div>
            ),
        },
        {
            accessorKey: 'contact',
            header: 'Kontak',
            cell: ({ row }) => <span className="text-daiku-muted">{row.original.contact ?? '—'}</span>,
        },
        {
            id: 'bank',
            header: 'Rekening',
            cell: ({ row }) =>
                row.original.bank_account_number ? (
                    <div className="text-sm">
                        <p>
                            {row.original.bank_name} {row.original.bank_account_number}
                        </p>
                        {row.original.account_holder && <p className="text-xs text-daiku-muted">a.n. {row.original.account_holder}</p>}
                    </div>
                ) : (
                    <span className="text-daiku-muted">—</span>
                ),
        },
        {
            accessorKey: 'is_active',
            header: 'Status',
            cell: ({ row }) => (
                <StatusChip
                    status={row.original.is_active ? 'ACTIVE' : 'INACTIVE'}
                    label={row.original.is_active ? 'Aktif' : 'Nonaktif'}
                    tone={row.original.is_active ? 'success' : 'neutral'}
                />
            ),
        },
        {
            id: 'actions',
            header: '',
            cell: ({ row }) => (
                <div className="flex justify-end gap-1">
                    <Button variant="ghost" size="icon-sm" aria-label={`Edit ${row.original.name}`} onClick={() => openForm(row.original)}>
                        <Pencil className="size-4" />
                    </Button>
                    {!row.original.in_use && (
                        <Button variant="ghost" size="icon-sm" aria-label={`Hapus ${row.original.name}`} onClick={() => destroy(row.original)}>
                            <Trash2 className="size-4 text-error-ink" />
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    const textField = (name: 'contact' | 'bank_name' | 'bank_account_number' | 'account_holder', label: string, placeholder?: string) => (
        <FormField
            control={form.control}
            name={name}
            render={({ field }) => (
                <FormItem>
                    <FormLabel>{label}</FormLabel>
                    <FormControl>
                        <Input {...field} placeholder={placeholder} />
                    </FormControl>
                    <FormMessage />
                </FormItem>
            )}
        />
    );

    return (
        <AppLayout>
            <Head title="Vendor" />

            <PageHeader
                title="Vendor"
                icon={Store}
                description="Daftar vendor/supplier yang dipakai untuk hutang supplier dan pembelian material proyek."
                actions={
                    <Button size="sm" onClick={() => openForm(null)}>
                        <Plus className="size-4" />
                        Tambah Vendor
                    </Button>
                }
            />

            <ModuleTabs />

            <DataTable
                columns={columns}
                data={vendors.data}
                emptyMessage={filters.search || filters.type || filters.status ? 'Tidak ada vendor yang cocok dengan filter.' : 'Belum ada vendor.'}
                pagination={vendors}
                toolbar={
                    <div className="flex flex-wrap items-center gap-2">
                        <SearchInput
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Cari nama vendor…"
                            className="w-full sm:w-64"
                            aria-label="Cari vendor"
                        />
                        <Select value={filters.type ?? 'all'} onValueChange={(value) => applyFilter({ type: value === 'all' ? undefined : value })}>
                            <SelectTrigger className="w-40" aria-label="Filter jenis">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua jenis</SelectItem>
                                <SelectItem value="MATERIAL">Material</SelectItem>
                                <SelectItem value="JASA">Jasa</SelectItem>
                            </SelectContent>
                        </Select>
                        <Select value={filters.status ?? 'all'} onValueChange={(value) => applyFilter({ status: value === 'all' ? undefined : value })}>
                            <SelectTrigger className="w-40" aria-label="Filter status">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">Semua status</SelectItem>
                                <SelectItem value="active">Aktif</SelectItem>
                                <SelectItem value="inactive">Nonaktif</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                }
            />

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{editing ? 'Edit Vendor' : 'Tambah Vendor'}</DialogTitle>
                        {editing?.in_use && (
                            <DialogDescription>Vendor ini sudah dipakai — nonaktifkan bila tidak dipakai lagi.</DialogDescription>
                        )}
                    </DialogHeader>
                    <Form {...form}>
                        <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
                            <div className="grid grid-cols-3 gap-4">
                                <FormField
                                    control={form.control}
                                    name="name"
                                    render={({ field }) => (
                                        <FormItem className="col-span-2">
                                            <FormLabel>Nama Vendor</FormLabel>
                                            <FormControl>
                                                <Input {...field} autoFocus placeholder="mis. Kaca Jaya" />
                                            </FormControl>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                                <FormField
                                    control={form.control}
                                    name="type"
                                    render={({ field }) => (
                                        <FormItem>
                                            <FormLabel>Jenis</FormLabel>
                                            <Select value={field.value} onValueChange={field.onChange}>
                                                <FormControl>
                                                    <SelectTrigger className="w-full">
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                </FormControl>
                                                <SelectContent>
                                                    <SelectItem value="MATERIAL">Material</SelectItem>
                                                    <SelectItem value="JASA">Jasa</SelectItem>
                                                </SelectContent>
                                            </Select>
                                            <FormMessage />
                                        </FormItem>
                                    )}
                                />
                            </div>
                            {textField('contact', 'Kontak (opsional)', 'No. HP / nama sales')}
                            <FormField
                                control={form.control}
                                name="address"
                                render={({ field }) => (
                                    <FormItem>
                                        <FormLabel>Alamat (opsional)</FormLabel>
                                        <FormControl>
                                            <Textarea {...field} rows={2} />
                                        </FormControl>
                                        <FormMessage />
                                    </FormItem>
                                )}
                            />
                            <div className="grid grid-cols-2 gap-4">
                                {textField('bank_name', 'Bank (opsional)', 'mis. BCA')}
                                {textField('bank_account_number', 'No. Rekening (opsional)')}
                            </div>
                            {textField('account_holder', 'Atas Nama (opsional)')}
                            <FormField
                                control={form.control}
                                name="is_active"
                                render={({ field }) => (
                                    <FormItem className="flex flex-row items-center justify-between rounded-lg border border-daiku-border p-3">
                                        <FormLabel className="cursor-pointer">Vendor aktif (muncul di pilihan)</FormLabel>
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
        </AppLayout>
    );
}
